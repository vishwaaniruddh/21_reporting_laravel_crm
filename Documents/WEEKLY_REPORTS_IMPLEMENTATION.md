# Weekly Reports Implementation

## Overview
Comprehensive weekly operational report system showing aggregated data, trends, and patterns over a 7-day period.

## Features Implemented

### 1. Backend Controller
**File**: `app/Http/Controllers/WeeklyReportController.php`

**Endpoint**: `GET /api/reports/weekly`

**Parameters**:
- `week_start` (optional) - Start date of the week (defaults to current week Monday)
- `customer` (optional) - Filter by customer
- `zone` (optional) - Filter by zone

**Data Sections**:

1. **Week Summary**
   - Total alerts for the week
   - VM alerts for the week
   - Average daily alerts
   - Peak day (highest alerts)
   - Quietest day (lowest alerts)
   - Days with data

2. **Daily Breakdown**
   - 7-day comparison (Monday to Sunday)
   - Total alerts and VM alerts per day
   - Day-to-day percentage changes

3. **Top Sites**
   - Top 10 sites by weekly alert volume
   - Includes ATMID, site name, customer, city, zone

4. **Customer Breakdown**
   - Alert counts aggregated by customer for the week

5. **Zone Breakdown**
   - Alert counts aggregated by zone for the week

6. **Alert Type Trends**
   - Top 10 alert types for the week
   - Count and percentage distribution

7. **Patterns**
   - Busiest day of the week
   - Busiest hour across the week
   - Day of week distribution

8. **Site Health Trends**
   - Sites with increasing alerts (>20% increase)
   - Sites with decreasing alerts (>20% decrease)
   - Consistent sites (stable alert levels)
   - Top 5 in each category

9. **Week Comparison**
   - This week vs last week
   - Percentage changes for total alerts, VM alerts, avg daily

### 2. Frontend Service
**File**: `resources/js/services/weeklyReportService.js`

**Methods**:
- `getWeeklyReport(params)` - Fetch weekly report data
- `getFilterOptions()` - Get available filters (customers, zones)

### 3. Frontend Page
**File**: `resources/js/pages/WeeklyReportPage.jsx`

**Components**:
- Week start date picker (automatically calculates week end)
- Filter dropdowns (Customer, Zone)
- Week summary cards (Total, VM, Avg Daily, Peak Day)
- Week-over-week comparison with trend indicators
- Daily breakdown table with day-to-day changes
- Weekly patterns (busiest day and hour)
- Top 10 sites table
- Customer and zone breakdowns
- Top alert types with progress bars
- Site health trends (increasing/decreasing/consistent)
- Export and Print buttons

### 4. Routes
**API Route**: `routes/api.php`
```php
Route::get('weekly', [\App\Http\Controllers\WeeklyReportController::class, 'index'])
    ->middleware(['auth:sanctum', 'permission:reports.view']);
```

**Frontend Route**: `resources/js/components/App.jsx`
```jsx
<Route 
    path="/reports/weekly" 
    element={
        <ProtectedRoute requiredPermission="reports.view">
            <WeeklyReportPage />
        </ProtectedRoute>
    } 
/>
```

### 5. Navigation
**Sidebar Menu**: Already configured in `DashboardLayout.jsx`
- Reports > Weekly Summary
- Path: `/reports/weekly`
- Permission: `reports.view`

## Key Features

### Week-over-Week Comparison
- Automatically compares current week with previous week
- Shows percentage changes with visual indicators:
  - 🔺 Red up arrow for increases
  - 🔻 Green down arrow for decreases
  - ➖ Gray dash for no change

### Daily Breakdown
- Shows all 7 days (Monday to Sunday)
- Displays day name, date, total alerts, VM alerts
- Calculates day-to-day percentage changes

### Pattern Analysis
- Identifies busiest day of the week
- Identifies busiest hour across the entire week
- Helps identify recurring patterns

### Site Health Trends
- Automatically categorizes sites based on alert trends:
  - **Increasing**: >20% increase from first half to second half of week
  - **Decreasing**: >20% decrease from first half to second half of week
  - **Consistent**: Within ±20% range

## Data Sources

### PostgreSQL
- `sites` table - Site information
- `alerts_YYYY_MM_DD` partitions - Daily alert data for each day of the week

### Aggregation Logic
- Loops through each day of the week
- Queries corresponding partition table
- Aggregates data across all 7 days
- Calculates trends and patterns

## VM Alert Logic

VM alerts are identified by:
- `status IN ('O', 'C')`
- `sendtoclient = 'S'`

This matches the logic used in `/api/vm-alerts` endpoint.

## Usage

1. Navigate to Reports > Weekly Summary
2. Select a week start date (defaults to current week Monday)
3. Optionally apply filters (Customer, Zone)
4. Click "Apply" to filter results
5. Use "Refresh" to reload data
6. Use "Print" for print-friendly view
7. Use "Export" for downloading (coming soon)

## Permissions Required

- `reports.view` - Required to access weekly reports

## Comparison with Daily Reports

| Feature | Daily Reports | Weekly Reports |
|---------|--------------|----------------|
| **Time Range** | Single day | 7 days (week) |
| **Focus** | Detailed breakdown | Trends and patterns |
| **Comparisons** | vs Yesterday, vs Last Week | vs Last Week |
| **Patterns** | Hourly distribution | Day of week, busiest hour |
| **Trends** | N/A | Increasing/decreasing sites |
| **Use Case** | Daily operations | Weekly review, planning |

## Testing

### Test Weekly Report
```bash
# With authentication token
curl -H "Authorization: Bearer {token}" \
  "http://192.168.100.21:9000/api/reports/weekly"

# With specific week
curl -H "Authorization: Bearer {token}" \
  "http://192.168.100.21:9000/api/reports/weekly?week_start=2026-01-13"

# With filters
curl -H "Authorization: Bearer {token}" \
  "http://192.168.100.21:9000/api/reports/weekly?week_start=2026-01-13&customer=ABC%20Bank"
```

### Access in Browser
```
http://192.168.100.21:9000/reports/weekly
```

## Files Created/Modified

### Created
1. `app/Http/Controllers/WeeklyReportController.php`
2. `resources/js/services/weeklyReportService.js`
3. `resources/js/pages/WeeklyReportPage.jsx`
4. `WEEKLY_REPORTS_IMPLEMENTATION.md`

### Modified
1. `routes/api.php` - Added weekly report route
2. `resources/js/components/App.jsx` - Added weekly report page route and import

### Already Configured
1. `resources/js/components/DashboardLayout.jsx` - Menu item already exists

## Future Enhancements

1. **Export Functionality**
   - PDF export with charts
   - Excel export with raw data
   - Email scheduled reports

2. **Advanced Visualizations**
   - Interactive trend charts
   - Heatmap for day/hour patterns
   - Site health trend graphs

3. **Predictive Analytics**
   - Forecast next week's alert volume
   - Identify sites likely to have issues
   - Recommend preventive actions

4. **Custom Week Ranges**
   - Select any 7-day period (not just Mon-Sun)
   - Compare any two weeks
   - Multi-week trends

## Notes

- Reports are generated on-demand (no caching)
- Week starts on Monday by default
- All queries use partitioned alert tables for performance
- Filters are applied at the database level for efficiency
- Trends are calculated by comparing first half vs second half of week
- Site health categorization uses 20% threshold for changes
