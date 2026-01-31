# VM Alerts API Implementation - Complete

## Summary
Created complete API infrastructure for VM Alerts, duplicating the structure from All Alerts (Alerts Reports). The system is now ready for VM-specific logic implementation.

## Files Created/Modified

### Backend Files

#### 1. Controller: `app/Http/Controllers/VMAlertController.php`
- **Created**: Duplicate of AlertsReportController
- **Class**: VMAlertController
- **Methods**: All methods from AlertsReportController
  - `index()` - GET /api/vm-alerts
  - `filterOptions()` - GET /api/vm-alerts/filter-options
  - `exportCsv()` - GET /api/vm-alerts/export/csv
  - `checkCsvReport()` - GET /api/vm-alerts/check-csv
  - `checkExcelReport()` - GET /api/vm-alerts/excel-check
  - `generateExcelReport()` - POST /api/vm-alerts/excel-generate
  - Plus all helper methods

#### 2. Routes: `routes/api.php`
- **Added**: VMAlertController import
- **Added**: Complete route group for `/api/vm-alerts`
- **Protection**: All routes require `auth:sanctum` and `reports.view` permission

### Frontend Files

#### 3. Service: `resources/js/services/vmAlertService.js`
- **Created**: Duplicate of alertsReportService
- **Functions**:
  - `getVMAlerts(params)` - Fetch paginated VM alerts
  - `getVMFilterOptions()` - Get filter options
  - `exportVMCsv(params)` - Generate CSV export URL
  - `checkVMCsvReport(date)` - Check for pre-generated CSV
  - `checkVMExcelReport(date, filters)` - Check for Excel report

#### 4. Component: `resources/js/components/VMAlertDashboard.jsx`
- **Updated**: Now uses vmAlertService instead of placeholder
- **Features**: Complete dashboard with filters, pagination, CSV export
- **Identical to**: AlertsReportDashboard structure

#### 5. Routes: `resources/js/components/App.jsx`
- **Updated**: VMAlertDashboard import and route already added

## API Endpoints

### VM Alerts Endpoints (Protected with reports.view)

```
GET    /api/vm-alerts                      - Paginated VM alerts list
GET    /api/vm-alerts/filter-options       - Available filters
GET    /api/vm-alerts/export/csv           - CSV export
GET    /api/vm-alerts/check-csv            - Check pre-generated CSV
GET    /api/vm-alerts/excel-check          - Check Excel report
POST   /api/vm-alerts/excel-generate       - Generate Excel report
```

### Request Parameters (Same as All Alerts)

**GET /api/vm-alerts**
```json
{
    "page": 1,
    "per_page": 25,
    "panelid": "string",
    "dvrip": "string",
    "customer": "string",
    "panel_type": "string",
    "atmid": "string",
    "from_date": "2026-01-09" // required
}
```

**GET /api/vm-alerts/export/csv**
```json
{
    "from_date": "2026-01-09", // required
    "limit": 1000000
}
```

## Current Behavior

### Identical to All Alerts
Currently, VM Alerts behaves **exactly the same** as All Alerts:
- Queries same database tables
- Uses same filters
- Returns same data structure
- Same CSV export format
- Same pagination

### Ready for Customization
The infrastructure is in place to add VM-specific logic:
- Filter by VM-specific criteria
- Add VM-specific columns
- Implement VM-specific business rules
- Customize export formats

## Testing

### Test VM Alerts API
```bash
# Get VM alerts
curl -H "Authorization: Bearer {token}" \
  "http://localhost:9000/api/vm-alerts?from_date=2026-01-09&per_page=25"

# Get filter options
curl -H "Authorization: Bearer {token}" \
  "http://localhost:9000/api/vm-alerts/filter-options"

# Export CSV
curl -H "Authorization: Bearer {token}" \
  "http://localhost:9000/api/vm-alerts/export/csv?from_date=2026-01-09"
```

### Test Frontend
1. Login as any user with `reports.view` permission
2. Navigate to **Reports > VM Alert**
3. Should see identical interface to All Alerts
4. Test filters, pagination, CSV export

## Next Steps: VM-Specific Logic

Now you can implement VM-specific changes in `VMAlertController.php`:

### Example: Filter by VM-specific criteria
```php
// In VMAlertController.php - index() method
// Add VM-specific WHERE clause
$query->where('alert_type', 'VM'); // Example VM filter
```

### Example: Add VM-specific columns
```php
// In VMAlertController.php - enrichWithSites() method
// Add VM-specific fields from sites table
$sites = DB::connection('pgsql')
    ->table('sites')
    ->select([
        'OldPanelID', 'NewPanelID', 'Customer', 
        'vm_status', 'vm_type', 'vm_location' // VM-specific fields
    ])
    ->get();
```

### Example: VM-specific CSV export
```php
// In VMAlertController.php - exportCsv() method
// Modify CSV headers for VM alerts
fputcsv($file, [
    '#', 'Client', 'Incident #', 'VM Type', 'VM Status', 
    'VM Location', 'Alert', 'Created', 'Closed'
    // ... VM-specific columns
]);
```

## File Structure

```
Backend:
├── app/Http/Controllers/
│   ├── AlertsReportController.php (All Alerts)
│   └── VMAlertController.php (VM Alerts) ✅ NEW

Frontend:
├── resources/js/
│   ├── components/
│   │   ├── AlertsReportDashboard.jsx (All Alerts)
│   │   └── VMAlertDashboard.jsx (VM Alerts) ✅ UPDATED
│   └── services/
│       ├── alertsReportService.js (All Alerts)
│       └── vmAlertService.js (VM Alerts) ✅ NEW

Routes:
└── routes/api.php ✅ UPDATED
```

## Comparison: All Alerts vs VM Alerts

| Feature | All Alerts | VM Alerts |
|---------|-----------|-----------|
| **Endpoint** | `/api/alerts-reports` | `/api/vm-alerts` |
| **Controller** | AlertsReportController | VMAlertController |
| **Service** | alertsReportService.js | vmAlertService.js |
| **Component** | AlertsReportDashboard | VMAlertDashboard |
| **Route** | `/alerts-reports` | `/vm-alerts` |
| **Permission** | `reports.view` | `reports.view` |
| **Data Source** | PostgreSQL alerts | PostgreSQL alerts |
| **Current Logic** | All alerts | Same as All alerts |
| **Future Logic** | All alerts | VM-specific filtering |

## Status
✅ **COMPLETE** - VM Alerts API infrastructure fully implemented

## Ready For
- VM-specific filtering logic
- VM-specific data columns
- VM-specific business rules
- VM-specific export formats
- VM-specific validations

All the infrastructure is in place. You can now implement the VM-specific logic in the VMAlertController without affecting the All Alerts functionality!
