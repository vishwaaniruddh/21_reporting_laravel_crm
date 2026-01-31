# VM Alerts Filters Implementation

## Overview
VM Alerts report has been configured with specific filters to show only relevant alerts for VM (Virtual Machine) monitoring. Unlike All Alerts, VM Alerts always generates reports dynamically and does not use pre-generated CSV files.

## VM-Specific Filters

The VM Alerts report applies two mandatory filters to all queries:

### 1. Status Filter
```sql
status IN ('O', 'C')
```
- **O** = Open alerts
- **C** = Closed alerts
- Excludes all other status types

### 2. Send to Client Filter
```sql
sendtoclient = 'S'
```
- Only shows alerts that are marked to be sent to the client
- **S** = Send to client

## Download Behavior

### VM Alerts (Dynamic Generation)
- **No pre-generated files**: VM Alerts are filtered, so pre-generated CSV files cannot be used
- **Always generates on-demand**: Clicking "Download CSV" generates the report in real-time
- **Filename format**: `vm_alerts_report_YYYY-MM-DD.csv`
- **Applies filters**: All downloads include the VM-specific filters (status IN ('O','C') AND sendtoclient='S')

### All Alerts (Pre-generated + Dynamic)
- **Pre-generated files**: Available for past dates (instant download)
- **Dynamic generation**: Used for current date or when pre-generated file doesn't exist
- **Filename format**: `alerts_report_YYYY-MM-DD.csv`
- **No filters**: Downloads all alerts without restrictions

## Implementation Details

### Controller Changes
**File**: `app/Http/Controllers/VMAlertController.php`

The filters are applied in the `getAlertsViaRouter()` method:

```php
// VM-SPECIFIC FILTERS: Only show status O or C, and sendtoclient = S
$filters['vm_status'] = ['O', 'C'];  // Status IN ('O','C')
$filters['vm_sendtoclient'] = 'S';   // sendtoclient = 'S'
```

These filters are also applied in CSV export via `exportViaRouterNoFilters()` method.

### Service Changes
**File**: `app/Services/PartitionQueryRouter.php`

Added support for VM-specific filters in `buildWhereConditions()` method:

```php
// VM-specific status filter (array of statuses - IN clause)
if (!empty($filters['vm_status']) && is_array($filters['vm_status'])) {
    $escapedStatuses = array_map(function($status) {
        return "'" . $this->escapeString($status) . "'";
    }, $filters['vm_status']);
    $inClause = implode(', ', $escapedStatuses);
    $conditions[] = "\"status\" IN ({$inClause})";
}

// VM-specific sendtoclient filter
if (!empty($filters['vm_sendtoclient'])) {
    $sendtoclient = $this->escapeString($filters['vm_sendtoclient']);
    $conditions[] = "\"sendtoclient\" = '{$sendtoclient}'";
}
```

## SQL Query Example

When querying VM Alerts for a specific date, the generated SQL will include:

```sql
SELECT * FROM alerts_2026_01_09
WHERE "status" IN ('O', 'C')
  AND "sendtoclient" = 'S'
  AND "receivedtime" >= '2026-01-09 00:00:00'
  AND "receivedtime" <= '2026-01-09 23:59:59'
ORDER BY "receivedtime" DESC
LIMIT 25 OFFSET 0
```

## Comparison with All Alerts

| Feature | All Alerts | VM Alerts |
|---------|-----------|-----------|
| Status Filter | None (all statuses) | Only 'O' and 'C' |
| Send to Client Filter | None | Only 'S' |
| Data Source | PostgreSQL partitions | PostgreSQL partitions |
| Other Filters | Same | Same |
| Export Format | Same | Same (filename: `vm_alerts_report_YYYY-MM-DD.csv`) |

## API Endpoints

All VM Alerts endpoints apply these filters automatically:

- `GET /api/vm-alerts` - Paginated VM alerts list
- `GET /api/vm-alerts/export/csv` - CSV export with VM filters
- `GET /api/vm-alerts/filter-options` - Available filter options
- `GET /api/vm-alerts/check-csv` - Check pre-generated CSV
- `GET /api/vm-alerts/excel-check` - Check Excel report
- `GET /api/vm-alerts/excel-generate` - Generate Excel report

## Testing

To verify VM filters are working:

1. **Check the data**:
   ```bash
   # All Alerts (no filters)
   curl "http://localhost:9000/api/alerts-reports?from_date=2026-01-09"
   
   # VM Alerts (with filters)
   curl "http://localhost:9000/api/vm-alerts?from_date=2026-01-09"
   ```

2. **Compare counts**: VM Alerts should show fewer records than All Alerts

3. **Verify status values**: All returned records should have status 'O' or 'C'

4. **Verify sendtoclient**: All returned records should have sendtoclient = 'S'

## Code Cleanup

Removed dead code from VMAlertController:
- `exportViaSingleTableNoFilters()` - referenced non-existent `buildBaseQuery()`
- `exportViaRouter()` - never called, referenced dead methods
- `exportViaSingleTable()` - referenced non-existent `buildBaseQuery()`

Only kept:
- `exportViaRouterNoFilters()` - main export method with VM filters
- `writeCsvRow()` - CSV row writer

## Notes

- VM filters are **always applied** - they cannot be disabled
- These filters work across all partition tables automatically
- The filters are applied at the SQL level for optimal performance
- CSV exports include the same filters as the UI display
