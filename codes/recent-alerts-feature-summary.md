# Recent Alerts Feature Summary

## Overview

Created a new "Last 15 Min Alerts" report page that shows real-time alerts from MySQL alerts table from the last 15 minutes.

**CRITICAL**: This feature is **READ-ONLY** - no updates or deletes to MySQL.

## Features

### 1. Real-Time Data
- Shows alerts from the last 15 minutes
- Auto-refresh every 30 seconds (can be toggled)
- Manual refresh button
- Displays time range and last update time

### 2. Filters
- **Panel ID**: Filter by panel ID
- **ATM ID**: Filter by ATM ID (from sites table)
- Search and Reset buttons

### 3. Data Display
- All columns from MySQL alerts table
- Enriched with site information (Customer, ATMID, Address, etc.)
- Pagination support (25 records per page)
- Status badges (Open, Closed, New)

### 4. Columns Displayed
- ID
- Panel ID
- ATM ID (from sites table)
- Customer (from sites table)
- Zone
- Alarm
- Alert Type
- Status
- Received Time
- Address (from sites table)

## Files Created

### Backend

1. **app/Http/Controllers/RecentAlertsController.php**
   - `index()` - Get alerts from last 15 minutes with filters
   - `filterOptions()` - Get available filter options
   - `enrichAlertsWithSiteInfo()` - JOIN with sites table for ATMID

2. **routes/api.php**
   - `GET /api/recent-alerts` - Get recent alerts
   - `GET /api/recent-alerts/filter-options` - Get filter options
   - Protected by `auth:sanctum` and `reports.view` permission

### Frontend

3. **resources/js/pages/RecentAlertsPage.jsx**
   - React component for the page
   - Auto-refresh functionality
   - Filters panel
   - Pagination
   - Time range display

4. **resources/js/components/App.jsx**
   - Added route: `/recent-alerts`
   - Protected by `reports.view` permission

5. **resources/js/components/DashboardLayout.jsx**
   - Added "Last 15 Min Alerts" menu item under Reports
   - Added RecentAlertsIcon component

## Database Access

### MySQL (READ-ONLY)
- **alerts table**: Source of alert data
- **sites table**: Source of ATMID and site information
- **NO UPDATES OR DELETES** - Only SELECT queries

### Query Logic
```sql
SELECT * FROM alerts
WHERE receivedtime >= NOW() - INTERVAL 15 MINUTE
ORDER BY receivedtime DESC
```

With optional filters:
- Panel ID: `WHERE panelid LIKE '%value%'`
- ATM ID: `WHERE EXISTS (SELECT 1 FROM sites WHERE alerts.panelid = sites.OldPanelID AND ATMID LIKE '%value%')`

## Permissions

- **Required Permission**: `reports.view`
- **Available to**: Superadmin, Admin, Manager, Team Leader, Surveillance Team

## Navigation

**Location**: Reports → Last 15 Min Alerts

**URL**: http://192.168.100.21:9000/recent-alerts

## Auto-Refresh

- **Default**: Enabled
- **Interval**: 30 seconds
- **Toggle**: Checkbox in header
- **Manual Refresh**: Button in header

## API Endpoints

### Get Recent Alerts
```
GET /api/recent-alerts
```

**Parameters:**
- `page` (optional): Page number (default: 1)
- `per_page` (optional): Records per page (default: 25, max: 100)
- `panelid` (optional): Filter by panel ID
- `atmid` (optional): Filter by ATM ID

**Response:**
```json
{
  "success": true,
  "data": [...],
  "pagination": {
    "current_page": 1,
    "per_page": 25,
    "total": 150,
    "last_page": 6
  },
  "time_range": {
    "from": "2026-01-12T10:00:00+05:30",
    "to": "2026-01-12T10:15:00+05:30"
  }
}
```

### Get Filter Options
```
GET /api/recent-alerts/filter-options
```

**Response:**
```json
{
  "success": true,
  "panel_ids": ["P001", "P002", ...],
  "atm_ids": ["ATM001", "ATM002", ...]
}
```

## Testing

### 1. Access the Page
```
http://192.168.100.21:9000/recent-alerts
```

### 2. Test Filters
- Enter a Panel ID and click Search
- Enter an ATM ID and click Search
- Click Reset to clear filters

### 3. Test Auto-Refresh
- Uncheck "Auto-refresh" checkbox
- Wait 30 seconds - page should not refresh
- Check "Auto-refresh" checkbox
- Wait 30 seconds - page should refresh

### 4. Test Pagination
- If more than 25 records, pagination should appear
- Click "Next" and "Previous" buttons

## Important Notes

1. **READ-ONLY**: This feature NEVER updates or deletes data from MySQL
2. **Performance**: Queries only last 15 minutes of data (fast)
3. **Real-Time**: Auto-refresh keeps data current
4. **Site JOIN**: ATMID comes from sites table via JOIN on panelid
5. **Permission**: Uses existing `reports.view` permission

## Security

- Protected by Laravel Sanctum authentication
- Requires `reports.view` permission
- No write operations to MySQL
- Input validation on all parameters
- SQL injection protection via Eloquent ORM

## Future Enhancements

Possible improvements:
- Export to CSV
- More filter options (date range, status, alert type)
- Real-time WebSocket updates
- Alert sound notifications
- Customizable time range (5 min, 15 min, 30 min, 1 hour)

## Conclusion

The "Last 15 Min Alerts" feature provides real-time visibility into recent alerts from MySQL with filtering capabilities, all while maintaining READ-ONLY access to ensure data integrity.
