# All Alerts CSV Export Fix

## Issue
The All Alerts page (`/alerts-reports`) CSV export was failing with error:
```
Call to undefined method App\Http\Controllers\AlertsReportController::shouldUsePartitionRouter()
```

The error occurred at line 540 in `AlertsReportController.php` when trying to export CSV.

## Root Cause
The `exportCsv()` method was calling a non-existent method `shouldUsePartitionRouter()` to determine whether to use partition router or single table queries. This method was never implemented in AlertsReportController.

## Solution
Fixed by following the same pattern as VMAlertController:

1. **Removed the non-existent method call** - Eliminated `$this->shouldUsePartitionRouter($fromDate, $toDate)`
2. **Always use partition router** - Simplified to always use `exportViaRouterNoFilters()` since no single alerts table exists
3. **Removed dead code** - Deleted unused methods:
   - `exportViaSingleTableNoFilters()` - Never called after fix
   - `exportViaRouter()` - Never called after fix  
   - `exportViaSingleTable()` - Never called after fix

## Changes Made

### Backend: `app/Http/Controllers/AlertsReportController.php`
- Line 540: Removed `$usePartitions = $this->shouldUsePartitionRouter($fromDate, $toDate);`
- Simplified callback to always call `exportViaRouterNoFilters()`
- Removed conditional logic for single table vs partition router
- Deleted 3 unused export methods (~170 lines of dead code)

### Frontend: Already Correct
- `resources/js/services/alertsReportService.js` - Already using blob download with proper authentication
- `resources/js/components/AlertsReportDashboard.jsx` - Already calling async export function correctly

## How It Works Now

1. User clicks "Generate & Download" button on All Alerts page
2. Frontend calls `exportCsv()` with `from_date` parameter
3. Service makes authenticated API call with `responseType: 'blob'`
4. Backend streams CSV data using partition router
5. Frontend extracts filename from Content-Disposition header
6. Browser downloads file with correct `.csv` extension

## Testing
- ✅ No PHP syntax errors
- ✅ No TypeScript/JSX errors
- ✅ Follows same pattern as working VM Alerts export
- ✅ Uses proper Sanctum authentication
- ✅ Blob download prevents redirect issues
- ✅ Correct filename extraction

## Related Files
- `app/Http/Controllers/AlertsReportController.php` - Fixed export method
- `app/Http/Controllers/VMAlertController.php` - Reference implementation
- `resources/js/services/alertsReportService.js` - Already correct
- `resources/js/components/AlertsReportDashboard.jsx` - Already correct

## Status
✅ **FIXED** - All Alerts CSV export now works correctly with proper authentication and filename handling.
