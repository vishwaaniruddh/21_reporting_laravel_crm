# RMS Sites Implementation

## Overview
Implemented a complete RMS Sites management page with data from the PostgreSQL `sites` table, including pagination, filtering, and CSV export functionality.

## Features

### 1. Data Display
- **Source**: PostgreSQL `sites` table
- **Pagination**: 10, 25, 50, or 100 records per page
- **Columns Displayed** (16 total):
  - SN (Serial Number)
  - Customer
  - Bank
  - ATM ID
  - Address
  - City
  - State
  - Zone
  - Panel ID (New)
  - Panel ID (Old)
  - Panel Make
  - DVR Name
  - DVR IP
  - Port
  - Live Status (with color coding)
  - Installation Date

### 2. Filters
All filters support dynamic dropdown options from the database:

| Filter | Type | Description |
|--------|------|-------------|
| **ATM ID** | Input | Search by ATM ID (partial match) |
| **Customer** | Dropdown | Filter by customer name |
| **Bank** | Dropdown | Filter by bank name |
| **Panel Make** | Dropdown | Filter by panel manufacturer |
| **DVR Name** | Dropdown | Filter by DVR name |
| **DVR IP** | Input | Search by DVR IP (partial match) |
| **Live Status** | Dropdown | Filter by live status (distinct values) |

### 3. Export
- **Format**: CSV
- **Filename**: `rms_sites_YYYY-MM-DD_HHMMSS.csv`
- **Columns** (26 total in export):
  - All display columns plus additional fields
  - ATM ID 2, ATM ID 3
  - Username, Password
  - Router ID, SIM Number, SIM Owner, Router Brand
  - Old/New Panel IDs
- **Filters Applied**: Export respects all active filters
- **Limit**: Up to 100,000 records

### 4. UI Features
- **Responsive Design**: Works on desktop and mobile
- **Loading States**: Spinner while fetching data
- **Error Handling**: User-friendly error messages
- **Empty State**: Message when no results found
- **Live Status Badge**: Green for 'Y', Red for others
- **Hover Effects**: Row highlighting on hover
- **Truncated Text**: Long addresses show tooltip on hover

## API Endpoints

### GET /api/rms-sites
Get paginated RMS sites list

**Permission Required**: `sites.rms`

**Query Parameters**:
```
page: integer (default: 1)
per_page: integer (10-100, default: 25)
atmid: string (optional)
customer: string (optional)
bank: string (optional)
panel_make: string (optional)
dvrname: string (optional)
dvrip: string (optional)
live: string (optional)
```

**Response**:
```json
{
  "success": true,
  "data": {
    "sites": [...],
    "pagination": {
      "current_page": 1,
      "last_page": 10,
      "per_page": 25,
      "total": 250,
      "from": 1,
      "to": 25
    },
    "total_count": 250
  }
}
```

### GET /api/rms-sites/filter-options
Get filter dropdown options

**Permission Required**: `sites.rms`

**Response**:
```json
{
  "success": true,
  "data": {
    "customers": ["Customer A", "Customer B", ...],
    "banks": ["Bank A", "Bank B", ...],
    "panel_makes": ["Make A", "Make B", ...],
    "dvr_names": ["DVR A", "DVR B", ...],
    "live_statuses": ["Y", "N", ...]
  }
}
```

### GET /api/rms-sites/export/csv
Export RMS sites to CSV

**Permission Required**: `sites.rms`

**Query Parameters**: Same as index endpoint

**Response**: CSV file download

## Files Created

### Backend
1. **app/Http/Controllers/RMSSitesController.php**
   - `index()` - Paginated sites list with filters
   - `filterOptions()` - Get dropdown options (cached 1 hour)
   - `exportCsv()` - CSV export with streaming

### Frontend
2. **resources/js/services/rmsSitesService.js**
   - `getRMSSites()` - Fetch paginated sites
   - `getRMSFilterOptions()` - Fetch filter options
   - `exportRMSCsv()` - Generate export URL

3. **resources/js/pages/SitesRMSPage.jsx**
   - Complete UI with filters, table, pagination
   - State management with React hooks
   - Export functionality

### Routes
4. **routes/api.php**
   - Added `/api/rms-sites` route group
   - All routes protected with `sites.rms` permission

## Database Schema

The `sites` table (PostgreSQL) contains:
- SN (Primary Key)
- Customer, Bank, ATMID, ATMID_2, ATMID_3
- SiteAddress, City, State, Zone
- NewPanelID, OldPanelID, Panel_Make
- DVRName, DVRIP, Port, UserName, Password
- live, installationDate
- RouterID, SIMNumber, SIMOwner, RouterBrand
- old_panelid, new_panelid

## Performance Optimizations

1. **Caching**: Filter options cached for 1 hour
2. **Chunked Export**: CSV export uses 1000-record chunks
3. **Indexed Queries**: Uses database indexes for filtering
4. **Pagination**: Limits data transfer per request
5. **Memory Management**: 512MB limit for exports

## Usage

### Access the Page
1. Login as user with `sites.rms` permission
2. Navigate to: http://192.168.100.21:9000/sites/rms
3. Or click: Sidebar → Sites → RMS

### Filter Sites
1. Enter search criteria in filter fields
2. Click "Filter" button
3. Results update automatically
4. Click "Clear" to reset filters

### Export Data
1. Apply desired filters (optional)
2. Click "Export CSV" button
3. CSV file downloads automatically
4. Export includes all filtered results

### Pagination
1. Use "Show" dropdown to change records per page
2. Click page numbers to navigate
3. Use arrow buttons for first/last/prev/next
4. Current page highlighted in blue

## Testing

### Test Filters
```bash
# Test ATM ID filter
curl "http://192.168.100.21:9000/api/rms-sites?atmid=ATM001"

# Test Customer filter
curl "http://192.168.100.21:9000/api/rms-sites?customer=CustomerName"

# Test multiple filters
curl "http://192.168.100.21:9000/api/rms-sites?customer=CustomerName&bank=BankName&live=Y"
```

### Test Export
```bash
# Export all sites
curl "http://192.168.100.21:9000/api/rms-sites/export/csv" -o sites.csv

# Export filtered sites
curl "http://192.168.100.21:9000/api/rms-sites/export/csv?customer=CustomerName" -o sites_filtered.csv
```

### Test Filter Options
```bash
curl "http://192.168.100.21:9000/api/rms-sites/filter-options"
```

## Error Handling

- **No Permission**: Returns 403 Forbidden
- **Invalid Parameters**: Returns 422 Validation Error
- **Database Error**: Returns 500 with error message
- **No Results**: Shows "No sites found" message
- **Export Failure**: Returns JSON error response

## Security

- **Authentication**: All endpoints require `auth:sanctum`
- **Authorization**: All endpoints require `sites.rms` permission
- **SQL Injection**: Protected by Laravel query builder
- **XSS**: React automatically escapes output
- **CSRF**: Protected by Sanctum

## Future Enhancements

Potential improvements:
1. Add site editing functionality
2. Add site creation form
3. Add bulk operations (delete, update)
4. Add Excel export option
5. Add advanced search (date ranges, etc.)
6. Add site details modal/page
7. Add map view for site locations
8. Add site status monitoring
9. Add audit log for changes
10. Add import from CSV/Excel

## Summary

✅ Complete RMS Sites page implemented  
✅ 7 filters with dynamic dropdowns  
✅ Pagination (10/25/50/100 per page)  
✅ CSV export with all filters  
✅ 16 columns displayed, 26 in export  
✅ Permission-based access control  
✅ Responsive design  
✅ Error handling  
✅ Loading states  
✅ Caching for performance  

The RMS Sites page is fully functional and ready for production use!
