# DVR Sites Implementation

## Overview
Implemented a complete DVR Sites management page with data from the PostgreSQL `dvrsite` table, including pagination, filtering, and CSV export functionality.

## Features

### 1. Data Display
- **Source**: PostgreSQL `dvrsite` table
- **Pagination**: 10, 25, 50, or 100 records per page
- **Columns Displayed** (14 total):
  - SN (Serial Number)
  - Customer
  - Bank
  - ATM ID
  - Address
  - City
  - State
  - Zone
  - DVR Name
  - DVR IP
  - HTTP Port
  - RTSP Port
  - Live Status (with color coding)
  - Installation Date

### 2. Filters
All filters support dynamic dropdown options from the database:

| Filter | Type | Description |
|--------|------|-------------|
| **ATM ID** | Input | Search by ATM ID (partial match) |
| **Customer** | Dropdown | Filter by customer name |
| **Bank** | Dropdown | Filter by bank name |
| **DVR Name** | Dropdown | Filter by DVR name |
| **DVR IP** | Input | Search by DVR IP (partial match) |
| **Live Status** | Dropdown | Filter by live status (distinct values) |

### 3. Export
- **Format**: CSV
- **Filename**: `dvr_sites_YYYY-MM-DD_HHMMSS.csv`
- **Columns** (24 total in export):
  - All display columns plus additional fields
  - Username, Password
  - Camera 1, Camera 2, Camera 3, Camera 4
  - Recording Status, HDD Status
  - Last Maintenance, Remarks
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

### GET /api/dvr-sites
Get paginated DVR sites list

**Permission Required**: `sites.dvr`

**Query Parameters**:
```
page: integer (default: 1)
per_page: integer (10-100, default: 25)
atmid: string (optional)
customer: string (optional)
bank: string (optional)
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

### GET /api/dvr-sites/filter-options
Get filter dropdown options

**Permission Required**: `sites.dvr`

**Response**:
```json
{
  "success": true,
  "data": {
    "customers": ["Customer A", "Customer B", ...],
    "banks": ["Bank A", "Bank B", ...],
    "dvr_names": ["DVR A", "DVR B", ...],
    "live_statuses": ["Y", "N", ...]
  }
}
```

### GET /api/dvr-sites/export/csv
Export DVR sites to CSV

**Permission Required**: `sites.dvr`

**Query Parameters**: Same as index endpoint

**Response**: CSV file download

## Files Created

### Backend
1. **app/Http/Controllers/DVRSitesController.php**
   - `index()` - Paginated sites list with filters
   - `filterOptions()` - Get dropdown options (cached 1 hour)
   - `exportCsv()` - CSV export with streaming

### Frontend
2. **resources/js/services/dvrSitesService.js**
   - `getDVRSites()` - Fetch paginated sites
   - `getDVRFilterOptions()` - Fetch filter options
   - `exportDVRCsv()` - Generate export URL

3. **resources/js/pages/SitesDVRPage.jsx**
   - Complete UI with filters, table, pagination
   - State management with React hooks
   - Export functionality

### Routes
4. **routes/api.php**
   - Added `/api/dvr-sites` route group
   - All routes protected with `sites.dvr` permission

## Database Schema

The `dvrsite` table (PostgreSQL) contains:
- SN (Primary Key)
- Customer, Bank, ATMID
- Address, City, State, Zone
- DVRName, DVRIP, HTTPPort, RTSPPort
- Username, Password
- live, InstallationDate
- Camera1, Camera2, Camera3, Camera4
- RecordingStatus, HDDStatus
- LastMaintenance, Remarks

## Performance Optimizations

1. **Caching**: Filter options cached for 1 hour
2. **Chunked Export**: CSV export uses 1000-record chunks
3. **Indexed Queries**: Uses database indexes for filtering
4. **Pagination**: Limits data transfer per request
5. **Memory Management**: 512MB limit for exports

## Usage

### Access the Page
1. Login as user with `sites.dvr` permission
2. Navigate to: http://192.168.100.21:9000/sites/dvr
3. Or click: Sidebar → Sites → DVR

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
curl "http://192.168.100.21:9000/api/dvr-sites?atmid=ATM001"

# Test Customer filter
curl "http://192.168.100.21:9000/api/dvr-sites?customer=CustomerName"

# Test multiple filters
curl "http://192.168.100.21:9000/api/dvr-sites?customer=CustomerName&bank=BankName&live=Y"
```

### Test Export
```bash
# Export all DVR sites
curl "http://192.168.100.21:9000/api/dvr-sites/export/csv" -o dvr_sites.csv

# Export filtered sites
curl "http://192.168.100.21:9000/api/dvr-sites/export/csv?customer=CustomerName" -o dvr_sites_filtered.csv"
```

### Test Filter Options
```bash
curl "http://192.168.100.21:9000/api/dvr-sites/filter-options"
```

## Error Handling

- **No Permission**: Returns 403 Forbidden
- **Invalid Parameters**: Returns 422 Validation Error
- **Database Error**: Returns 500 with error message
- **No Results**: Shows "No DVR sites found" message
- **Export Failure**: Returns JSON error response

## Security

- **Authentication**: All endpoints require `auth:sanctum`
- **Authorization**: All endpoints require `sites.dvr` permission
- **SQL Injection**: Protected by Laravel query builder
- **XSS**: React automatically escapes output
- **CSRF**: Protected by Sanctum

## Comparison with RMS Sites

| Feature | RMS Sites | DVR Sites |
|---------|-----------|-----------|
| Source Table | `sites` | `dvrsite` |
| Display Columns | 16 | 14 |
| Export Columns | 26 | 24 |
| Filters | 7 | 6 |
| Permission | `sites.rms` | `sites.dvr` |
| URL | `/sites/rms` | `/sites/dvr` |

## Future Enhancements

Potential improvements:
1. Add DVR site editing functionality
2. Add DVR site creation form
3. Add camera status monitoring
4. Add recording status dashboard
5. Add HDD health monitoring
6. Add bulk operations
7. Add Excel export option
8. Add DVR connection testing
9. Add video playback integration
10. Add maintenance scheduling

## Summary

✅ Complete DVR Sites page implemented  
✅ 6 filters with dynamic dropdowns  
✅ Pagination (10/25/50/100 per page)  
✅ CSV export with all filters  
✅ 14 columns displayed, 24 in export  
✅ Permission-based access control  
✅ Responsive design  
✅ Error handling  
✅ Loading states  
✅ Caching for performance  

The DVR Sites page is fully functional and ready for production use!
