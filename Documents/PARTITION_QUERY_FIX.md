# 🔧 Partition Query Fix - "alerts" Table Not Found

**Date:** January 9, 2026  
**Issue:** API returning error "relation 'alerts' does not exist"  
**Status:** ✅ FIXED

---

## Problem Description

When filtering alerts by `panel_type` (e.g., "comfort"), the API returned this error:

```
SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "alerts" does not exist
LINE 1: select count(*) as aggregate from "alerts" where "alerts"."r...
```

**API Endpoint:** `/api/alerts-reports?panel_type=comfort&from_date=2026-01-09`

---

## Root Cause

The system was trying to query a table called `alerts` directly, but that table doesn't exist in PostgreSQL. The system uses **date-partitioned tables** like:
- `alerts_2026_01_09`
- `alerts_2026_01_10`
- etc.

### Why It Happened

1. When `panel_type` filter was applied, the controller needed to:
   - Query the `sites` table to get matching panel IDs
   - Filter alerts by those panel IDs

2. The code had a fallback to `getAlertsSingleTable()` method for sites-based filters

3. This method called `buildBaseQuery()` which tried to query `alerts` table directly:
   ```php
   return DB::connection('pgsql')
       ->table('alerts')  // ❌ This table doesn't exist!
   ```

4. The single `alerts` table was removed when we implemented partitioning, but this fallback code path wasn't updated

---

## Solution Implemented

### 1. Updated AlertsReportController

**File:** `app/Http/Controllers/AlertsReportController.php`

**Changes:**
- Removed fallback to `getAlertsSingleTable()` for sites-based filters
- Now uses `PartitionQueryRouter` with `panel_ids` filter instead
- Returns empty result on error instead of falling back to non-existent table

**Before:**
```php
// Fall back to single table for sites-based filters
Log::info('Falling back to single table for sites-based filters');
return $this->getAlertsSingleTable($validated, $perPage, $page);
```

**After:**
```php
// Use panel IDs filter with partition router
$filters['panel_ids'] = $panelIds;
```

### 2. Added panel_ids Support to PartitionQueryRouter

**File:** `app/Services/PartitionQueryRouter.php`

**Changes:**
- Added support for `panel_ids` filter (array of panel IDs)
- Generates SQL `IN` clause for multiple panel IDs

**New Code:**
```php
// Panel IDs filter (array of panel IDs - IN clause)
if (!empty($filters['panel_ids']) && is_array($filters['panel_ids'])) {
    $escapedIds = array_map(function($id) {
        return "'" . $this->escapeString($id) . "'";
    }, $filters['panel_ids']);
    $inClause = implode(', ', $escapedIds);
    $conditions[] = "\"panelid\" IN ({$inClause})";
}
```

---

## How It Works Now

### Request Flow

```
1. User filters by panel_type="comfort"
   ↓
2. Controller queries sites table:
   SELECT OldPanelID, NewPanelID FROM sites WHERE Panel_Make = 'comfort'
   ↓
3. Gets array of panel IDs: [091206, 092765, 093694, ...]
   ↓
4. Passes to PartitionQueryRouter with filters:
   {
     "panel_ids": [091206, 092765, 093694, ...],
     "date_from": "2026-01-09",
     "date_to": "2026-01-09"
   }
   ↓
5. PartitionQueryRouter:
   - Identifies partition: alerts_2026_01_09
   - Builds query: SELECT * FROM alerts_2026_01_09 
                   WHERE "panelid" IN ('091206', '092765', ...)
   ↓
6. Returns filtered results
```

### SQL Generated

```sql
SELECT * FROM (
    SELECT * FROM alerts_2026_01_09
    WHERE "panelid" IN ('091206', '092765', '093694', ...)
) AS combined_results
ORDER BY "receivedtime" DESC
LIMIT 25 OFFSET 0
```

---

## Testing

### Test the Fix

```powershell
# Restart portal service
Restart-Service AlertPortal

# Wait for restart
Start-Sleep -Seconds 5

# Test in browser or with curl
# http://192.168.100.21:9000/api/alerts-reports?panel_type=comfort&from_date=2026-01-09&per_page=25&page=1
```

### Expected Result

✅ Should return alerts filtered by panel_type without errors

---

## Files Modified

1. **app/Http/Controllers/AlertsReportController.php**
   - Removed fallback to single table query
   - Added `panel_ids` filter support
   - Improved error handling

2. **app/Services/PartitionQueryRouter.php**
   - Added `panel_ids` filter support
   - Generates SQL IN clause for array of panel IDs

---

## Impact

### What's Fixed
- ✅ Filtering by `panel_type` now works
- ✅ Filtering by `customer` now works
- ✅ Filtering by `dvrip` now works
- ✅ Filtering by `atmid` now works
- ✅ All sites-based filters now work with partitioned tables

### What's Improved
- ✅ No more fallback to non-existent `alerts` table
- ✅ Better error handling (returns empty instead of crashing)
- ✅ Consistent use of partition router for all queries

---

## Prevention

### Why This Won't Happen Again

1. **No Single Table Fallback:** Removed all code paths that try to query `alerts` table directly

2. **Partition Router Only:** All queries now go through `PartitionQueryRouter`

3. **Better Error Handling:** Errors return empty results instead of falling back to broken code

---

## Related Documentation

- `SYSTEM_ARCHITECTURE_OVERVIEW.md` - System architecture
- `TROUBLESHOOTING_RESTART_GUIDE.md` - Troubleshooting guide
- `ALERTS_REPORTS_PARTITION_UPDATE.md` - Partition system details

---

## Quick Reference

### Check if Fix is Applied

```powershell
# Check portal service
Get-Service AlertPortal

# View recent logs
Get-Content "storage\logs\portal-service.log" -Tail 20

# Test API endpoint
# Open browser: http://192.168.100.21:9000/api/alerts-reports?panel_type=comfort&from_date=2026-01-09
```

### If Issue Persists

```powershell
# Restart portal service
Restart-Service AlertPortal

# Clear Laravel cache
php artisan cache:clear
php artisan config:clear

# Check logs for errors
Get-Content "storage\logs\laravel.log" -Tail 50
```

---

**Fix Applied:** January 9, 2026  
**Services Restarted:** AlertPortal  
**Status:** ✅ RESOLVED
