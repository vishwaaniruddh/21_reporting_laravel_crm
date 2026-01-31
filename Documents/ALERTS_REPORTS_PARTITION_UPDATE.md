# Alerts Reports - Partition Table Integration

## Summary of Changes

Updated the Alerts Reports page to use date-partitioned tables exclusively, removing dependency on the single PostgreSQL `alerts` table.

## Changes Made

### 1. ✅ Deleted PostgreSQL Single Alerts Table

**Action:** Dropped the `alerts` table from PostgreSQL
```sql
DROP TABLE IF EXISTS alerts CASCADE;
```

**Reason:** We now use date-partitioned tables (e.g., `alerts_2026_01_08`) exclusively for better performance and data management.

**Note:** MySQL alerts table remains untouched (read-only source data).

### 2. ✅ Updated Frontend - AlertsReportDashboard.jsx

**Changes:**
- **Default Date:** Set `from_date` to current date by default
- **Required Field:** Made `from_date` a required field with red asterisk (*)
- **Clear Filters:** Reset to current date instead of empty string

**Code Changes:**
```javascript
// Get current date in YYYY-MM-DD format
const getCurrentDate = () => {
    const today = new Date();
    return today.toISOString().split('T')[0];
};

const [filters, setFilters] = useState({
    // ... other filters
    from_date: getCurrentDate(), // Default to current date
    // ...
});

// In the form
<label className="block text-xs text-gray-600 mb-1">
    From Date <span className="text-red-500">*</span>
</label>
<input 
    type="date" 
    value={filters.from_date} 
    onChange={(e) => handleFilterChange('from_date', e.target.value)}
    required
    className="w-full px-2 py-1 border border-gray-300 rounded text-sm" 
/>
```

### 3. ✅ Updated Backend - AlertsReportController.php

**Changes:**

#### A. Made from_date Required
```php
$validated = $request->validate([
    // ... other fields
    'from_date' => 'required|date', // Now required
]);
```

#### B. Default to Current Date
```php
// Parse date filter - if not provided, use current date
$fromDate = !empty($validated['from_date']) 
    ? Carbon::parse($validated['from_date'])->startOfDay() 
    : Carbon::today()->startOfDay();
$toDate = $fromDate->copy()->endOfDay();
```

#### C. Always Use Partition Router
```php
protected function shouldUsePartitionRouter(?Carbon $startDate, ?Carbon $endDate): bool
{
    // ALWAYS use partition router now (single alerts table has been removed)
    // If no date range specified, use current date
    return true;
}
```

## How It Works Now

### User Flow

1. **User opens Alerts Reports page** → `from_date` is pre-filled with today's date
2. **User can change the date** → Select any date to query that day's partition
3. **User clicks Filter** → System queries the partition table for that date (e.g., `alerts_2026_01_09`)
4. **Results displayed** → Data from the specific partition table

### Backend Flow

```
Request with from_date
    ↓
Parse date (or use current date)
    ↓
shouldUsePartitionRouter() → Always returns true
    ↓
getAlertsViaRouter()
    ↓
PartitionQueryRouter queries partition table (e.g., alerts_2026_01_09)
    ↓
Enrich with sites data
    ↓
Return results
```

### Partition Table Lookup

- **Date:** 2026-01-09 → **Table:** `alerts_2026_01_09`
- **Date:** 2026-01-08 → **Table:** `alerts_2026_01_08`
- **Date:** 2025-12-25 → **Table:** `alerts_2025_12_25`

## Benefits

### ✅ Performance
- Queries only scan one day's data instead of entire table
- Faster response times for date-specific queries
- Better index utilization

### ✅ Data Management
- Easy to archive old partitions
- Can drop old partition tables without affecting recent data
- Better disk space management

### ✅ Scalability
- System can handle years of data efficiently
- Each day's data is isolated
- No single table growth issues

## Testing

### Test Scenario 1: Default Date Query
1. Open http://localhost:8000/alerts-reports
2. Verify `from_date` is pre-filled with today's date
3. Click "Filter" without changing anything
4. Should see today's alerts from partition table

### Test Scenario 2: Custom Date Query
1. Change `from_date` to a different date (e.g., yesterday)
2. Click "Filter"
3. Should see alerts from that date's partition table

### Test Scenario 3: Required Field Validation
1. Try to clear the `from_date` field
2. Click "Filter"
3. Browser should show validation error (required field)

### Test Scenario 4: Export CSV
1. Select a date
2. Click export button
3. Should download CSV with data from that date's partition

## Migration Notes

### Before (Old System)
- Single `alerts` table in PostgreSQL
- All alerts in one table
- Queries scan entire table
- Performance degrades over time

### After (New System)
- Date-partitioned tables (`alerts_YYYY_MM_DD`)
- Each day has its own table
- Queries scan only relevant partition
- Consistent performance regardless of data volume

## Rollback Plan

If you need to rollback to the single table:

1. **Recreate alerts table:**
```sql
CREATE TABLE alerts (
    -- Same schema as partition tables
    id BIGINT PRIMARY KEY,
    -- ... other columns
);
```

2. **Copy data from partitions:**
```sql
INSERT INTO alerts SELECT * FROM alerts_2026_01_08;
INSERT INTO alerts SELECT * FROM alerts_2026_01_09;
-- ... for each partition
```

3. **Revert code changes:**
   - Remove `required` from `from_date` validation
   - Change `shouldUsePartitionRouter()` to check for partitions
   - Remove default date logic

## Known Limitations

1. **Single Day Queries Only:** Currently queries one day at a time. For date ranges, need to enhance PartitionQueryRouter.

2. **Partition Must Exist:** If no partition exists for the selected date, query returns empty results.

3. **No Cross-Partition Queries:** Can't query multiple days in one request (yet).

## Future Enhancements

### 1. Date Range Support
Allow users to select start and end dates:
```javascript
<input type="date" name="from_date" required />
<input type="date" name="to_date" />
```

Backend would query multiple partitions:
```php
$partitions = $this->partitionRouter->getPartitionsInRange($fromDate, $toDate);
// Query and UNION results from all partitions
```

### 2. Auto-Create Missing Partitions
If user queries a date without a partition:
```php
if (!$this->partitionManager->partitionExists($date)) {
    // Trigger sync for that date
    $this->syncService->syncDate($date);
}
```

### 3. Partition Health Dashboard
Show partition status:
- Which dates have partitions
- Record counts per partition
- Missing date ranges
- Sync status

## Files Modified

1. **resources/js/components/AlertsReportDashboard.jsx**
   - Added `getCurrentDate()` function
   - Set default `from_date` to current date
   - Made `from_date` required with asterisk
   - Updated clear filters to reset to current date

2. **app/Http/Controllers/AlertsReportController.php**
   - Made `from_date` required in validation
   - Default to current date if not provided
   - Always use partition router
   - Updated export to require date

## Conclusion

The Alerts Reports page now exclusively uses date-partitioned tables, providing better performance and scalability. The `from_date` field is required and defaults to the current date, ensuring users always query a specific partition table.

---

**Status:** ✅ Complete  
**Date:** 2026-01-09  
**Impact:** All alerts queries now use partition tables  
**MySQL:** Untouched (read-only source)
