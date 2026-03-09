# Timestamp Conversion Issue - Root Cause and Fix

## Problem Summary

Timestamps were being converted during sync from MySQL to PostgreSQL, causing mismatches like:
- MySQL: `2026-03-06 03:31:44`
- PostgreSQL: `2026-03-07 18:01:44`
- Difference: 38.5 hours (1 day, 14 hours, 30 minutes)

## Root Cause

When using Laravel's query builder `upsert()` method with parameter binding, PostgreSQL was interpreting timestamp strings and applying timezone conversion, even though both databases were configured for the same timezone (Asia/Kolkata / +05:30).

The issue occurred because:
1. Parameter binding (`?`) allows PostgreSQL to interpret the data type
2. PostgreSQL was treating the timestamp as if it needed timezone conversion
3. The conversion was happening despite explicit timezone configuration

## Solution

Use explicit `::timestamp` casting in raw SQL queries to tell PostgreSQL to treat the values as literal timestamps without any conversion.

### Before (Problematic)
```php
DB::connection('pgsql')->table($partitionTable)->upsert(
    [$data],
    ['id'],
    array_keys($data)
);
```

### After (Fixed)
```sql
INSERT INTO alerts_2026_03_06 (
    id, panelid, ..., createtime, receivedtime, closedtime, ...
) VALUES (
    ?, ?, ..., ?::timestamp, ?::timestamp, ?::timestamp, ...
)
ON CONFLICT (id) DO UPDATE SET
    createtime = EXCLUDED.createtime,
    receivedtime = EXCLUDED.receivedtime,
    closedtime = EXCLUDED.closedtime,
    ...
```

The `::timestamp` cast tells PostgreSQL: "This is already a timestamp in the correct format, don't convert it."

## Files Modified

1. **app/Services/AlertSyncService.php**
   - Updated `updateAlertInPostgres()` method
   - Now uses raw SQL with explicit `::timestamp` casting

2. **app/Services/BackAlertSyncService.php**
   - Updated `upsertBackAlertToPartition()` method
   - Now uses raw SQL with explicit `::timestamp` casting

## Verification Scripts

### 1. Diagnose Specific Alert
```bash
php diagnose_alert_1001097236.php
```
Shows detailed comparison between MySQL and PostgreSQL for a specific alert.

### 2. Fix Specific Alert
```bash
php fix_alert_1001097236.php
```
Fixes timestamp mismatches for alert ID 1001097236.

### 3. Find and Fix All Mismatches
```bash
# Dry run (report only)
php find_and_fix_all_timestamp_mismatches.php --dry-run

# Actually fix
php find_and_fix_all_timestamp_mismatches.php
```

## Testing the Fix

1. **Stop all sync services:**
   ```powershell
   .\codes\stop-all-services.ps1
   ```

2. **Run the fix script for existing data:**
   ```bash
   php find_and_fix_all_timestamp_mismatches.php
   ```

3. **Restart sync services:**
   ```powershell
   .\codes\start-all-services.ps1
   ```

4. **Monitor new syncs:**
   - Check logs for "All columns match after upsert" messages
   - Verify no "Column value mismatches detected" warnings

## Impact

- **Existing Data:** Needs to be fixed using the fix scripts
- **New Data:** Will be synced correctly with the updated services
- **Performance:** Minimal impact (raw SQL is actually faster than query builder)

## Prevention

The sync services now include validation after upsert:
```php
// Compare critical columns
if ($mysqlData['createtime'] !== $pgData->createtime) {
    $mismatches[] = "createtime mismatch";
}
```

This will log warnings if any future timestamp conversion issues occur.

## Related Issues

This fix also resolves:
- Closedtime update issues
- Timezone-related sync problems
- Timestamp immutability concerns

## Next Steps

1. ✅ Fix the sync services (DONE)
2. ⏳ Fix existing mismatched data (run the fix script)
3. ⏳ Restart services with the updated code
4. ⏳ Monitor for 24 hours to ensure no new mismatches

## Technical Details

### Why `::timestamp` Works

PostgreSQL has two timestamp types:
- `timestamp without time zone` - stores literal time values
- `timestamp with time zone` - stores UTC and converts on display

Our tables use `timestamp without time zone`, but parameter binding was causing PostgreSQL to treat the values as if they needed conversion. The `::timestamp` cast explicitly tells PostgreSQL to use the value as-is.

### Database Configuration

Both databases are configured for Asia/Kolkata timezone:
- MySQL: `timezone => '+05:30'`
- PostgreSQL: `timezone => 'Asia/Kolkata'`
- PHP: `date_default_timezone_set('Asia/Kolkata')`

Despite this, the conversion was still happening during parameter binding. The explicit cast bypasses this issue entirely.
