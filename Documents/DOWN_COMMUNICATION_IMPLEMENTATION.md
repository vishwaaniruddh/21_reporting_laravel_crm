# Down Communication Report - Implementation Summary

## Overview
Converted the MySQL-based `down_communication_reference.php` into a full-stack Laravel API with React frontend, accessible under **Reports → Down Communication**.

## What Was Created

### Backend (Laravel)

1. **Controller**: `app/Http/Controllers/DownCommunicationController.php`
   - `index()` - Paginated list with filters
   - `filterOptions()` - Get customers and banks for dropdowns
   - `exportCsv()` - Export full report to CSV

2. **API Routes**: Added to `routes/api.php`
   - `GET /api/down-communication` - Main report endpoint
   - `GET /api/down-communication/filter-options` - Filter options
   - `GET /api/down-communication/export/csv` - CSV export
   - All protected with `auth:sanctum` and `reports.view` permission

### Frontend (React)

1. **Service**: `resources/js/services/downCommunicationService.js`
   - API communication layer

2. **Component**: `resources/js/components/DownCommunicationDashboard.jsx`
   - Full-featured dashboard with:
     - Summary cards (Total, Working, Not Working ATMs)
     - Filters (Customer, Bank, ATM ID, City, State)
     - Paginated table with 16 columns
     - CSV export button

3. **Navigation**: Updated `resources/js/components/DashboardLayout.jsx`
   - Added "Down Communication" submenu under Reports
   - Added icon and routing logic

4. **Routing**: Updated `resources/js/components/App.jsx`
   - Added `/down-communication` route with `reports.view` permission

## Features

### Data Logic
- Shows ATMs where `dc_date != today` or `dc_date IS NULL`
- Joins with `sites` table (live='Y', server_ip=23)
- Joins with `esurvsites` for BM details
- Real-time summary: Working vs Not Working counts

### Filters
- Customer (dropdown)
- Bank (dropdown)
- ATM ID (text search)
- City (text search)
- State (text search)

### Table Columns (16)
1. # (Serial number)
2. Customer
3. Bank
4. ATM ID
5. ATM Short Name
6. City
7. State
8. Panel Make
9. Old Panel ID
10. New Panel ID
11. DVR IP
12. DVR Name
13. Last Communication (dc_date)
14. BM Name
15. BM Number
16. Zone

### Export
- CSV export with all records (no filters applied to export)
- Filename: `down_communication_YYYY-MM-DD.csv`

## Access Control
- Requires `reports.view` permission
- Available to users with Manager role or higher

## Usage
1. Login to the application
2. Navigate to **Reports** → **Down Communication**
3. View summary cards showing working/not working ATMs
4. Apply filters as needed
5. Export to CSV for offline analysis

## Technical Notes
- Uses MySQL connection for all queries
- Pagination: 25 records per page (configurable)
- Responsive design with Tailwind CSS
- Follows existing application patterns and conventions
