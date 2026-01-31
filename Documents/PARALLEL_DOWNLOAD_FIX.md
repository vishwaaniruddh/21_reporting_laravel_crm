# Parallel Download Fix - Session Blocking Issue

## Problem
When downloading batch 3 and batch 4 simultaneously:
- First download starts successfully
- Second download gets blocked/errors
- User has to wait for first download to complete before second can start

## Root Cause
**PHP Session Locking**: When a request starts a PHP session, it locks the session file. Any other request from the same user that needs the session must wait for the lock to be released.

### Why This Happens
1. User clicks "Download Batch 3" → Request 1 starts, locks session
2. User clicks "Download Batch 4" → Request 2 starts, **waits for session lock**
3. Request 1 streams 470,490 records (takes 2-5 minutes)
4. Request 2 is blocked the entire time
5. Request 2 might timeout or fail

## The Solution

### 1. Close Session Early Middleware
Created `CloseSessionEarly` middleware that closes the PHP session immediately after authentication, before the download starts.

**File**: `app/Http/Middleware/CloseSessionEarly.php`

```php
public function handle(Request $request, Closure $next): Response
{
    // Close session immediately to prevent locking
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    
    return $next($request);
}
```

### 2. Applied to Export Routes
The middleware is applied to all CSV export routes:

**File**: `routes/api.php`

```php
// All Alerts CSV export
Route::get('export/csv', [AlertsReportController::class, 'exportCsv'])
    ->middleware('close.session');

// VM Alerts CSV export  
Route::get('export/csv', [VMAlertController::class, 'exportCsv'])
    ->middleware('close.session');
```

### 3. Token-Based Downloads
Downloads use a token system to avoid authentication on every request:

1. Frontend calls `/export/csv/token` (authenticated) → Gets temporary token
2. Frontend opens download URL with token: `/export/csv?token=xxx`
3. Backend validates token (no session needed)
4. Download proceeds without session lock

## How It Works Now

### Sequential Downloads (Before Fix)
```
Time: 0s -------- 120s -------- 240s
Batch 3: [========DOWNLOADING========]
Batch 4:          [BLOCKED...] [START]
```

### Parallel Downloads (After Fix)
```
Time: 0s -------- 120s -------- 240s
Batch 3: [========DOWNLOADING========]
Batch 4: [========DOWNLOADING========]
```

Both downloads run simultaneously!

## Testing

### Test Parallel Downloads
1. Open Downloads page
2. Select date with 4 batches (e.g., 2026-01-30)
3. Click "Batch 3" download button
4. **Immediately** click "Batch 4" download button
5. Both downloads should start simultaneously

### Expected Behavior
- ✓ Both downloads start immediately
- ✓ Both show progress in browser
- ✓ Both complete successfully
- ✓ No errors or timeouts

### Previous Behavior (Bug)
- ✗ First download starts
- ✗ Second download waits/blocks
- ✗ Second download might timeout
- ✗ User sees error message

## Technical Details

### Session Locking in PHP
PHP uses file-based session locking by default:
- Session file: `/tmp/sess_<session_id>`
- Lock type: Exclusive write lock (flock)
- Lock duration: Entire request duration
- Impact: Blocks all other requests from same user

### Why Close Session Early?
Downloads don't need to write to the session:
- No user state changes during download
- No authentication updates needed
- Just streaming data to browser

By closing the session early:
- Lock is released immediately
- Other requests can proceed
- Downloads run in parallel

### Middleware Order
```
1. StartSession (Laravel) → Starts session, acquires lock
2. Authenticate (Sanctum) → Reads session for auth
3. CloseSessionEarly (Custom) → Closes session, releases lock ✓
4. Controller → Streams download (no session needed)
```

## Additional Fixes

### Controller-Level Session Close
Both controllers also close the session explicitly:

```php
// In exportCsv() method
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
```

This provides double protection in case middleware doesn't run.

## Limitations

### What Still Requires Session
These operations still need session and will block:
- Login/Logout
- Changing user settings
- CSRF token validation (POST requests)

### What Doesn't Need Session (Can Run in Parallel)
- CSV downloads (token-based)
- API data fetching (Sanctum token)
- Read-only operations

## Monitoring

Check logs for session closure:
```bash
Get-Content storage\logs\laravel.log | Select-String "Session closed early"
```

## Status
✅ **IMPLEMENTED** - Parallel downloads now supported

## Date Fixed
January 31, 2026
