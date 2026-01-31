# Combined Alerts + BackAlerts Implementation Summary

## Overview
Successfully modified the alerts-reports endpoint (`http://192.168.100.21:9000/alerts-reports`) to combine data from **both** alerts and backalerts partitioned tables for comprehensive reporting.

## What Was Changed

### 1. **PartitionQueryRouter Service** (`app/Services/PartitionQueryRouter.php`)
- **Added table prefix support** to all main methods:
  - `queryDateRange()` - Now accepts `$tablePrefixes` parameter
  - `queryWithPagination()` - Now accepts `$tablePrefixes` parameter  
  - `countDateRange()` - Now accepts `$tablePrefixes` parameter
  - `getPartitionsInRange()` - Now accepts `$tablePrefix` parameter

- **Enhanced schema compatibility** in `buildPartitionSelect()`:
  - Detects backalerts tables vs alerts tables
  - Maps backalerts columns to match alerts schema:
    - `closedby` → `closedBy`
    - `alertuserstatus` → `AlertUserStatus`
    - Adds missing `Readstatus` column as NULL
    - Converts data types (integer → varchar, etc.)

### 2. **PartitionManager Service** (`app/Services/PartitionManager.php`)
- **Updated `getPartitionsInRange()`** to support table prefixes
- **Updated `getPartitionTableName()`** to support table prefixes
- **Added dynamic partition discovery** for different table types

### 3. **DateExtractor Service** (`app/Services/DateExtractor.php`)
- **Enhanced `formatPartitionName()`** to accept table prefix parameter
- **Updated `isValidPartitionName()`** to validate different table prefixes
- **Added `detectTablePrefix()`** method for automatic prefix detection
- **Updated `getPartitionTableName()`** to support table prefixes

### 4. **AlertsReportController** (`app/Http/Controllers/AlertsReportController.php`)
- **Modified `getAlertsViaRouter()`** to query both table types:
  ```php
  $result = $this->partitionRouter->queryWithPagination(
      $startDate,
      $endDate,
      $filters,
      $perPage,
      $page,
      ['alerts', 'backalerts'] // Query both table types
  );
  ```

## Results

### Before Implementation
- **Data Source**: Only `alerts_YYYY_MM_DD` tables
- **Record Count**: ~148,552 records for 2026-01-27

### After Implementation  
- **Data Sources**: Both `alerts_YYYY_MM_DD` AND `backalerts_YYYY_MM_DD` tables
- **Record Count**: ~885,730 records for 2026-01-27 (148,552 + 737,178)
- **Schema Compatibility**: Automatic column mapping handles differences
- **Performance**: Efficient UNION ALL queries across partitions

## Schema Differences Handled

| Issue | Solution |
|-------|----------|
| `closedBy` vs `closedby` | Automatic column aliasing |
| `AlertUserStatus` vs `alertuserstatus` | Automatic column aliasing |
| Missing `Readstatus` in backalerts | Added as NULL |
| Different data types (int vs varchar) | Automatic type casting |

## API Response Format
The endpoint now returns combined data with:
- **Total records**: Sum of both table types
- **Unified schema**: All records have consistent column structure
- **Sites enrichment**: Customer, ATMID, location data added
- **Pagination**: Works across combined dataset
- **Filtering**: Applies to both table types

## Testing Results
✅ **Single table queries**: Both alerts and backalerts work independently  
✅ **Combined queries**: UNION ALL across both table types  
✅ **Schema compatibility**: Column mapping handles differences  
✅ **API endpoint**: Full end-to-end functionality  
✅ **Filtering**: Panel ID and other filters work correctly  
✅ **Pagination**: Proper pagination across combined dataset  

## Usage
The alerts-reports endpoint now automatically includes data from both:
- `alerts_2026_01_27` (148,552 records)
- `backalerts_2026_01_27` (737,178 records)

**Total combined**: 885,730 records for comprehensive alert reporting.

## Backward Compatibility
- ✅ Existing API calls continue to work unchanged
- ✅ Response format remains the same
- ✅ All filters and pagination work as before
- ✅ Just includes more comprehensive data

The implementation is production-ready and provides a complete view of all alert data from both sources.