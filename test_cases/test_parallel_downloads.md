# Test Parallel Downloads

## Quick Test

1. Open browser: http://192.168.100.21:9000/reports/downloads
2. Select date: 2026-01-30 (4 batches)
3. **Right-click** "Batch 3 of 4" → Open in new tab
4. **Right-click** "Batch 4 of 4" → Open in new tab
5. Both tabs should start downloading immediately

## Expected Result
- ✓ Both downloads start at the same time
- ✓ Both show progress in browser
- ✓ No errors

## If Still Blocking
The issue is that Laravel's session middleware is running before our close.session middleware.

### Solution: Check Middleware Order
The `close.session` middleware must run AFTER Laravel's session middleware but BEFORE the controller.

Current route:
```php
Route::get('export/csv', [AlertsReportController::class, 'exportCsv'])
    ->middleware('close.session');
```

### Debug: Check Session Status
Add this to the controller's exportCsv method (line 650):
```php
Log::info('Session status at export start', [
    'status' => session_status(),
    'session_id' => session_id(),
    'has_token' => $request->has('token')
]);
```

Then check logs:
```powershell
Get-Content storage\logs\laravel.log -Tail 20
```

## Alternative: Disable Session for Export Routes

If the middleware approach doesn't work, we can exclude export routes from session middleware entirely by creating a separate route group.
