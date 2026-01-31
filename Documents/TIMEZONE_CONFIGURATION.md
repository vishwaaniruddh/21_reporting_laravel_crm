# Timezone Configuration

## Issue
The application was using UTC timezone, causing date filters to show the wrong date (showing January 9 when it's actually January 10 in India).

Additionally, the frontend was using JavaScript's `new Date()` which uses the browser's timezone, not the server's timezone, causing inconsistencies.

## Solution
1. Changed the application timezone from UTC to Indian Standard Time (Asia/Kolkata)
2. Created a server-time API endpoint to provide the current server date
3. Updated frontend components to fetch and use the server date instead of browser date

## Changes Made

### 1. Backend Configuration

#### File: `config/app.php`
```php
// Before:
'timezone' => 'UTC',

// After:
'timezone' => env('APP_TIMEZONE', 'Asia/Kolkata'),
```

#### File: `.env`
```env
# Added:
APP_TIMEZONE=Asia/Kolkata
```

#### File: `routes/api.php`
```php
// Added new endpoint:
Route::get('server-time', function () {
    return response()->json([
        'success' => true,
        'date' => now()->toDateString(), // YYYY-MM-DD
        'time' => now()->toTimeString(), // HH:MM:SS
        'datetime' => now()->toDateTimeString(), // YYYY-MM-DD HH:MM:SS
        'timezone' => config('app.timezone'),
        'timestamp' => now()->timestamp,
    ]);
});
```

### 2. Frontend Updates

#### File: `resources/js/components/VMAlertDashboard.jsx`
- Removed `getCurrentDate()` function that used browser timezone
- Added `fetchServerDate()` to get current date from server
- Updated `from_date` filter to use server date
- Updated `handleClearFilters()` to use server date

#### File: `resources/js/components/AlertsReportDashboard.jsx`
- Same changes as VMAlertDashboard
- Ensures date consistency across all report pages

### 3. Configuration Cache
Cleared and rebuilt configuration cache:
```bash
php artisan config:clear
php artisan config:cache
```

## API Endpoint

### GET /api/server-time
Returns the current server date and time in IST.

**Response:**
```json
{
  "success": true,
  "date": "2026-01-10",
  "time": "00:44:48",
  "datetime": "2026-01-10 00:44:48",
  "timezone": "Asia/Kolkata",
  "timestamp": 1736467488
}
```

## Verification

### Backend Timezone:
```bash
php artisan tinker --execute="echo config('app.timezone');"
# Output: Asia/Kolkata
```

### Current Date/Time:
```bash
php artisan tinker --execute="echo now()->toDateTimeString();"
# Output: 2026-01-10 00:44:48 (IST)
```

### Frontend Date:
The frontend now fetches the date from `/api/server-time` on page load, ensuring consistency with the server timezone.

## Impact

### Before:
- Backend: UTC timezone
- Frontend: Browser timezone (could be different)
- Date filters showed: 2026-01-09 (wrong)
- Time was 5.5 hours behind Indian time
- Inconsistency between server and client

### After:
- Backend: Asia/Kolkata (IST) ✓
- Frontend: Fetches date from server ✓
- Date filters show: 2026-01-10 (correct) ✓
- Time matches Indian Standard Time ✓
- Consistent date/time across application ✓

## Affected Areas

All date/time operations in the application now use IST:
- ✅ Date filters in reports (now fetched from server)
- ✅ Alert timestamps
- ✅ Sync job scheduling
- ✅ Database timestamps
- ✅ Log timestamps
- ✅ CSV export filenames
- ✅ Dashboard date displays

## Frontend Date Handling

### Old Approach (Problematic):
```javascript
const getCurrentDate = () => {
    const today = new Date(); // Uses browser timezone
    return today.toISOString().split('T')[0];
};
```

### New Approach (Fixed):
```javascript
const fetchServerDate = useCallback(async () => {
    try {
        const response = await api.get('/server-time');
        if (response.data.success) {
            setServerDate(response.data.date); // Uses server timezone
        }
    } catch (err) {
        // Fallback to browser date if server request fails
        const today = new Date();
        setServerDate(today.toISOString().split('T')[0]);
    }
}, []);
```

## Database Considerations

### PostgreSQL:
- Stores timestamps in the database's timezone
- Laravel converts to/from application timezone automatically

### MySQL:
- Stores timestamps as-is
- Laravel handles timezone conversion

## Important Notes

1. **Partition Tables**: Partition table names (e.g., `alerts_2026_01_10`) are based on the `receivedtime` date in the application timezone (now IST).

2. **Sync Jobs**: The sync worker processes alerts based on IST dates.

3. **Scheduled Tasks**: Laravel scheduler runs based on the application timezone (IST).

4. **API Responses**: All date/time values in API responses are in IST.

5. **Frontend Consistency**: All date pickers now initialize with the server date, ensuring consistency.

## Testing

To verify the timezone is working correctly:

```bash
# Check current timezone
php artisan tinker --execute="echo config('app.timezone');"

# Check current date
php artisan tinker --execute="echo now()->toDateString();"

# Check current time
php artisan tinker --execute="echo now()->toTimeString();"

# Check timezone offset
php artisan tinker --execute="echo now()->format('P');"
# Should output: +05:30

# Test server-time endpoint
curl http://192.168.100.21:9000/api/server-time
```

## Rollback (if needed)

To revert to UTC:

1. Update `.env`:
   ```env
   APP_TIMEZONE=UTC
   ```

2. Clear config cache:
   ```bash
   php artisan config:clear
   php artisan config:cache
   ```

3. Rebuild frontend assets:
   ```bash
   npm run build
   ```

## Related Files

- `config/app.php` - Application timezone configuration
- `.env` - Environment-specific timezone setting
- `routes/api.php` - Server-time API endpoint
- `resources/js/components/VMAlertDashboard.jsx` - Uses server date
- `resources/js/components/AlertsReportDashboard.jsx` - Uses server date
- `app/Services/DateExtractor.php` - Uses application timezone for date extraction
- All controllers and services - Use Laravel's `now()` helper which respects timezone

## Status

✅ **FIXED** - Application now uses Indian Standard Time (Asia/Kolkata) throughout, and frontend fetches the current date from the server to ensure consistency.
