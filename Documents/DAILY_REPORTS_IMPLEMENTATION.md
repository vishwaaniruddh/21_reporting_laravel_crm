# Daily Reports Implementation

## Overview
Comprehensive daily operational report system for detailed analysis of alerts, sites, and performance metrics for any specific date.

## Features Implemented

### 1. Backend Controller
**File**: `app/Http/Controllers/DailyReportController.php`

**Endpoint**: `GET /api/reports/daily`

**Parameters**:
- `date` (required) - Date to generate report for
- `customer` (optional) - Filter by customer
- `zone` (optional) - Filter by zone
- `panel_type` (optional) - Filter by panel type

**Data Sections**:
1. **Daily Summary**
   - Total alerts
   - VM alerts (status IN ('O','C') AND sendtoclient = 'S')
   - Unique sites with alerts
   - Healthy sites (no alerts)
   - Down sites
   - Total sites

2. **Alert Breakdown**
   - Top 10 alert types by count

3. **Hourly Distribution**
   - 24-hour breakdown of total alerts and VM alerts

4. **Top Sites**
   - Top 10 sites with most alerts
   - Includes ATMID, site name, customer, city, zone

5. **Customer Breakdown**
   - Alert counts aggregated by customer

6. **Zone Breakdown**
   - Alert counts aggregated by zone

7. **Alert Type Distribution**
   - All alert types with counts and percentages

8. **Comparisons**
   - Today vs Yesterday
   - Today vs Last Week (same day)
   - Percentage changes for alerts and VM alerts

9. **Down Sites**
   - List of sites currently down
   - Hours down calculation

### 2. Frontend Service
**File**: `resources/js/services/dailyReportService.js`

**Methods**:
- `getDailyReport(params)` - Fetch daily report data
- `getFilterOptions()` - Get available filters (customers, zones, panel types)

### 3. Frontend Page
**File**: `resources/js/pages/DailyReportPage.jsx`

**Components**:
- Date picker for selecting report date
- Filter dropdowns (Customer, Zone, Panel Type)
- Summary cards with key metrics
- Comparison section (vs Yesterday, vs Last Week)
- Hourly distribution bar chart
- Top 10 sites table
- Customer breakdown list
- Zone breakdown list
- Alert type distribution with progress bars
- Export and Print buttons

### 4. Routes
**API Route**: `routes/api.php`
```php
Route::get('/reports/daily', [DailyReportController::class, 'index'])
    ->middleware(['auth:sanctum', 'permission:reports.view']);
```

**Frontend Route**: `resources/js/components/App.jsx`
```jsx
<Route 
    path="/reports/daily" 
    element={
        <ProtectedRoute requiredPermission="reports.view">
            <DailyReportPage />
        </ProtectedRoute>
    } 
/>
```

### 5. Navigation
**Sidebar Menu**: Already configured in `DashboardLayout.jsx`
- Reports > Daily Report
- Path: `/reports/daily`
- Permission: `reports.view`

## Key Differences from Executive Dashboard

| Feature | Executive Dashboard | Daily Reports |
|---------|-------------------|---------------|
| **Purpose** | Real-time monitoring | Historical analysis |
| **Time Range** | Last 30 days (configurable) | Single day |
| **Updates** | Auto-refresh every 5 minutes | Static report |
| **Focus** | Trends and predictions | Detailed breakdown |
| **Audience** | Executives, managers | Operations team |
| **Export** | Optional | Primary feature |

## Data Sources

### PostgreSQL
- `sites` table - Site information
- `alerts_YYYY_MM_DD` partitions - Daily alert data

### MySQL
- `down_communication` table - Down site tracking

## Filters Applied

All filters work by:
1. Getting panel IDs from `sites` table that match filter criteria
2. Filtering alert queries by those panel IDs

**Available Filters**:
- Customer
- Zone
- Panel Type

## VM Alert Logic

VM alerts are identified by:
- `status IN ('O', 'C')`
- `sendtoclient = 'S'`

This matches the logic used in `/api/vm-alerts` endpoint.

## Usage

1. Navigate to Reports > Daily Report
2. Select a date using the date picker
3. Optionally apply filters (Customer, Zone)
4. Click "Apply" to filter results
5. Use "Refresh" to reload data
6. Use "Print" for print-friendly view
7. Use "Export" for downloading (coming soon)

## Permissions Required

- `reports.view` - Required to access daily reports

## Future Enhancements

1. **Export Functionality**
   - PDF export with charts
   - Excel export with raw data
   - Email scheduled reports

2. **Additional Metrics**
   - Response time analysis
   - SLA compliance breakdown
   - Ticket correlation

3. **Advanced Filters**
   - Multiple customer selection
   - Date range comparison
   - Custom alert type filters

4. **Visualizations**
   - Interactive charts with drill-down
   - Heatmaps for alert patterns
   - Geographic distribution maps

## Testing

### Test Daily Report
```bash
# With authentication token
curl -H "Authorization: Bearer {token}" \
  "http://192.168.100.21:9000/api/reports/daily?date=2026-01-16"

# With filters
curl -H "Authorization: Bearer {token}" \
  "http://192.168.100.21:9000/api/reports/daily?date=2026-01-16&customer=ABC%20Bank"
```

### Access in Browser
```
http://192.168.100.21:9000/reports/daily
```

## Files Created/Modified

### Created
1. `app/Http/Controllers/DailyReportController.php`
2. `resources/js/services/dailyReportService.js`
3. `resources/js/pages/DailyReportPage.jsx`
4. `DAILY_REPORTS_IMPLEMENTATION.md`

### Modified
1. `routes/api.php` - Added daily report route
2. `resources/js/components/App.jsx` - Added daily report page route and import

### Already Configured
1. `resources/js/components/DashboardLayout.jsx` - Menu item already exists

## Notes

- Reports are generated on-demand (no caching)
- All queries use partitioned alert tables for performance
- Filters are applied at the database level for efficiency
- Comparisons automatically calculate percentage changes
- Down sites are fetched from MySQL down_communication table
- Hourly distribution shows both total and VM alerts
