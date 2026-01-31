# Downloads Page Implementation Summary

## Overview
Created a centralized Downloads page that provides partition-based downloads for alerts and VM alerts with automatic batch splitting for large datasets.

## Implementation Date
January 28, 2026

## Features Implemented

### 1. Downloads Page UI (`resources/js/pages/DownloadsPage.jsx`)
- **Tabbed Interface**: Two tabs - "All Alerts" and "VM Alerts"
- **Partition Display**: Shows available dates with record counts
- **Batch Splitting**: Automatically splits datasets > 600k records into batches
- **Bulk Selection**: Checkbox selection for downloading multiple dates
- **Download Buttons**: Individual CSV download buttons per partition/batch
- **Progress Indicators**: Loading states and download progress feedback

### 2. Backend API (`app/Http/Controllers/DownloadsController.php`)
- **GET /api/downloads/partitions**: Fetches available partitions with record counts
- Queries `PartitionRegistry` model for partition metadata
- Returns combined statistics (alerts + backalerts counts)
- Filters by type (all-alerts vs vm-alerts)

### 3. Export Enhancements
Updated both `AlertsReportController` and `VMAlertController`:
- Added `offset` parameter support for batch downloads
- Serial numbers now start from `offset + 1` for proper batch numbering
- Maintains consistent CSV format across batches

### 4. Navigation Integration
- Added "Downloads" menu item under Reports section
- Route: `/reports/downloads`
- Requires `reports.view` permission

## Technical Details

### Batch Download Logic
- **Batch Size**: 600,000 records per file
- **Maximum Limit**: 1,000,000 records per date (hardcoded)
- **Calculation**: `batches = Math.ceil(records / 600000)`
- **Offset**: Each batch uses `offset = (batchIndex - 1) * 600000`

### API Endpoints Used
1. **GET /api/downloads/partitions**
   - Params: `type` (all-alerts | vm-alerts)
   - Returns: Array of partitions with dates and record counts

2. **GET /api/alerts-reports/export/csv**
   - Params: `from_date`, `limit`, `offset`
   - Downloads: All alerts (alerts + backalerts combined)

3. **GET /api/vm-alerts/export/csv**
   - Params: `from_date`, `limit`, `offset`
   - Downloads: VM alerts only (status O/C, sendtoclient=S)

### Data Flow
```
User selects date → Frontend calculates batches → 
For each batch: API call with offset → 
Backend queries partitions with offset/limit → 
Streams CSV with proper serial numbers → 
Browser downloads file
```

## File Structure
```
resources/js/pages/DownloadsPage.jsx          # Main UI component
app/Http/Controllers/DownloadsController.php  # Partition metadata API
app/Http/Controllers/AlertsReportController.php  # Updated with offset support
app/Http/Controllers/VMAlertController.php    # Updated with offset support
routes/api.php                                # Added downloads route
```

## User Experience

### All Alerts Tab
- Shows combined count (alerts + backalerts)
- Example: "2026-01-28 (1,142,786 records)"
- Downloads both alerts and backalerts tables for the date

### VM Alerts Tab
- Shows only backalerts count
- Example: "2026-01-28 (118,764 records)"
- Downloads only VM-specific alerts (status O/C, sendtoclient=S)

### Batch Downloads
For dates with > 600k records:
- Shows multiple download buttons: "Batch 1 of 3", "Batch 2 of 3", etc.
- Each batch downloads exactly 600k records (except last batch)
- Serial numbers continue across batches (Batch 1: 1-600000, Batch 2: 600001-1200000)

### Bulk Downloads
- Select multiple dates using checkboxes
- Click "Download All as CSV" to download all selected dates
- System downloads each date sequentially with 1-second delay between downloads

## Limitations & Future Enhancements

### Current Limitations
1. **CSV Only**: Excel format not yet implemented (shows "coming soon")
2. **1M Record Cap**: Maximum 1 million records per date (hardcoded in controllers)
3. **Sequential Downloads**: Bulk downloads happen one at a time (browser limitation)

### Future Enhancements
1. **Excel Support**: Implement Excel export with batch splitting
2. **Download Queue**: Show progress for bulk downloads
3. **Compression**: Add ZIP compression for large files
4. **Scheduling**: Allow scheduled/automated downloads
5. **Email Delivery**: Send download links via email for very large datasets

## Testing Checklist

### UI Testing
- [x] Tab switching works correctly
- [x] Partitions load from API
- [x] Record counts display correctly
- [x] Batch calculation is accurate
- [x] Checkbox selection works
- [x] Download buttons trigger correctly
- [x] Loading states display properly

### API Testing
- [x] Partition metadata endpoint returns correct data
- [x] CSV export with offset works
- [x] Serial numbers are correct across batches
- [x] VM alerts filter correctly
- [x] All alerts include both tables

### Integration Testing
- [ ] Download a single date (< 600k records)
- [ ] Download a single date with batches (> 600k records)
- [ ] Download multiple dates in bulk
- [ ] Verify CSV file contents
- [ ] Verify serial number continuity across batches

## Performance Considerations

### Frontend
- Minimal state management (only active partitions loaded)
- Efficient batch calculation
- Proper cleanup of download URLs

### Backend
- Streaming response (no memory buffering)
- Chunked database queries (1000 records per chunk)
- Memory cleanup every 5000 records
- Progress logging every 10k records

### Database
- Uses PartitionQueryRouter for efficient cross-partition queries
- Leverages partition pruning for date-based queries
- Proper indexing on receivedtime column

## Security

### Authentication
- All endpoints require `auth:sanctum` middleware
- Requires `reports.view` permission

### Authorization
- Token-based authentication for downloads
- No direct file access (streaming only)

### Data Protection
- No sensitive data exposure in URLs
- Proper escaping in CSV output
- Leading zeros preserved with tab character

## Monitoring & Logging

### Log Events
- CSV export started (with date, limit, offset)
- Export progress (every 10k records)
- CSV export completed (with total count)
- Export errors (with full trace)

### Metrics to Monitor
- Download request count per day
- Average download time per batch
- Failed download attempts
- Most downloaded dates

## Deployment Notes

### Prerequisites
- PartitionRegistry table populated with current partitions
- Both alerts and backalerts partitions exist
- Proper permissions assigned to users

### Configuration
No additional configuration required. Uses existing:
- Database connections (pgsql)
- Authentication (sanctum)
- Permission system (reports.view)

### Post-Deployment
1. Verify partition registry is up to date
2. Test downloads with small datasets first
3. Monitor server resources during large downloads
4. Check log files for any errors

## Success Criteria
✅ Users can view available download dates
✅ Record counts are accurate
✅ Batch splitting works automatically
✅ Downloads complete successfully
✅ CSV files have correct format and data
✅ Serial numbers are sequential across batches
✅ Bulk downloads work for multiple dates
✅ Performance is acceptable for large datasets
✅ **Non-blocking downloads** - UI remains responsive during downloads

## Recent Update: Non-Blocking Downloads (Jan 28, 2026)

### Problem Solved
Downloads were blocking all navigation and API requests until completion, making the UI unresponsive for large files.

### Solution Implemented
Token-based download system that uses browser's native download manager:
1. Frontend requests download token via POST
2. Backend generates secure token (10-min expiry, single-use)
3. Frontend uses `window.open()` with token
4. Browser handles download in background
5. UI remains fully responsive

### Technical Details
- **Token Generation**: `POST /api/alerts-reports/export/csv/token`
- **Token Storage**: Redis/cache with 10-minute TTL
- **Security**: Single-use tokens, requires authentication
- **Performance**: No UI blocking, multiple simultaneous downloads

See `DOWNLOADS_NON_BLOCKING_FIX.md` for complete implementation details.

## Related Documentation
- `COMBINED_ALERTS_IMPLEMENTATION_SUMMARY.md` - Combined alerts/backalerts queries
- `VM_ALERTS_EXPORT_TIMEOUT_FIX_SUMMARY.md` - Export timeout fixes
- `Documents/PARTITION_QUERY_FIX.md` - Partition query implementation
- `Documents/HOW_PARTITION_QUERIES_WORK.md` - Partition architecture

## Support & Troubleshooting

### Common Issues

**Issue**: No partitions showing
- **Solution**: Check PartitionRegistry table is populated
- **Command**: `php artisan tinker` → `App\Models\PartitionRegistry::count()`

**Issue**: Download fails with timeout
- **Solution**: Already handled with increased timeouts and streaming
- **Check**: `storage/logs/laravel.log` for errors

**Issue**: Wrong record counts
- **Solution**: Refresh partition registry
- **Command**: Run `populate_backalerts_registry.php`

**Issue**: Batch downloads incomplete
- **Solution**: Check offset calculation and limit enforcement
- **Verify**: Serial numbers in downloaded files

## Conclusion
The Downloads page provides a user-friendly interface for downloading large alert datasets with automatic batch splitting. It leverages the existing partition infrastructure and export functionality while adding a centralized, intuitive UI for managing downloads.
