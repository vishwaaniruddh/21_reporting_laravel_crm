# Session Blocking Fix - Complete ✅

## Problem
When a user downloads a report (CSV/Excel), all other API calls from the same user are blocked and wait for the download to complete. This causes the entire portal to freeze during downloads.

**Symptoms**:
- User clicks download button
- Portal becomes unresponsive
- All API calls timeout or wait
- After download completes, portal works again
- Refreshing the downloads page doesn't load

## Root Cause: Database Session Locking

The application uses **database sessions** (`SESSION_DRIVER=database`), which causes row-level locking in the database:

1. When a request starts, Laravel locks the session row in the database
2. The lock prevents other requests from reading/writing the session
3. During a long download (streaming response), the session row stays locked
4. All other requests from the same user wait for the database lock to release
5. This blocks the entire portal until the download completes

**Why `session_write_close()` doesn't work with database sessions**:
- `session_write_close()` only closes the PHP session handler
- It does NOT release the database row lock immediately
- Laravel's database session driver holds the lock until the request completes
- The lock is only released when the response is fully sent

## Solution: Switch to File Sessions

Change the session driver from `database` to `file`. File sessions don't have the same locking issues because:
- Each session is a separate file
- PHP can close the file immediately with `session_write_close()`
- No database row locks involved
- Multiple requests can proceed in parallel

## Implementation

### Automated Fix Script

Run the PowerShell script:
```powershell
.\codes\fix-session-blocking.ps1
```

This script will:
1. Backup your `.env` file
2. Change `SESSION_DRIVER=database` to `SESSION_DRIVER=file`
3. Clear configuration cache
4. Create sessions directory if needed
5. Set proper permissions

### Manual Fix

If you prefer to do it manually:

1. **Edit `.env` file**:
```env
# Change this:
SESSION_DRIVER=database

# To this:
SESSION_DRIVER=file
```

2. **Clear config cache**:
```bash
php artisan config:clear
```

3. **Ensure sessions directory exists**:
```bash
# Windows
mkdir storage\framework\sessions
icacls storage\framework\sessions /grant "Everyone:(OI)(CI)F" /T

# Linux
mkdir -p storage/framework/sessions
chmod 775 storage/framework/sessions
```

4. **Restart web server** (if using Apache/Nginx)

## Testing

### Before Fix (Expected Behavior)
1. Open portal in browser
2. Click download button for large CSV
3. Try to navigate to another page
4. Portal freezes/waits
5. After download completes, portal works again

### After Fix (Expected Behavior)
1. Open portal in browser
2. Click download button for large CSV
3. Try to navigate to another page
4. Portal works normally! ✅
5. Download continues in background

### Test Commands
```bash
# Terminal 1: Start a large download
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "http://localhost:9000/api/alerts-reports/export/csv?from_date=2026-01-01" \
  -o test.csv &

# Terminal 2: Immediately make another API call (should NOT block)
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "http://localhost:9000/api/dashboard/stats"

# Expected: Second request returns immediately (< 1 second)
# Before fix: Second request waits for first to complete (30+ seconds)
```

## Technical Details

### Session Storage Comparison

| Feature | Database Sessions | File Sessions |
|---------|------------------|---------------|
| Storage | Database table | Filesystem |
| Locking | Row-level lock | File lock |
| `session_write_close()` | Doesn't release DB lock | Releases file lock immediately |
| Parallel requests | ❌ Blocked | ✅ Works |
| Load balancing | ✅ Easy | ⚠️ Needs sticky sessions |
| Performance | Slower | Faster |

### Why Database Sessions Block

```php
// Request 1: Download starts
DB::table('sessions')->where('id', $sessionId)->lockForUpdate(); // LOCKS ROW
// ... streaming 100MB CSV (takes 30 seconds)
// Row stays locked for 30 seconds!

// Request 2: Dashboard API call (same user)
DB::table('sessions')->where('id', $sessionId)->lockForUpdate(); // BLOCKED!
// Waits for Request 1 to release the lock
```

### Why File Sessions Don't Block

```php
// Request 1: Download starts
fopen('sessions/sess_abc123', 'r+'); // Opens file
session_write_close(); // Closes file immediately ✅
// ... streaming 100MB CSV (file is already closed)

// Request 2: Dashboard API call (same user)
fopen('sessions/sess_abc123', 'r+'); // Opens file (NOT BLOCKED!)
```

## Important Notes

### Session Data After Switch
- All users will be logged out (sessions cleared)
- Users need to log in again
- This is a one-time inconvenience

### Load Balancing Considerations
If you have multiple servers:
- File sessions require "sticky sessions" (same user → same server)
- Configure your load balancer for sticky sessions
- Or use Redis sessions instead (see Alternative Solutions)

### Session File Cleanup
File sessions accumulate over time. Laravel automatically cleans them up based on `session.lottery` config:
```php
'lottery' => [2, 100], // 2% chance per request
```

You can also manually clean old sessions:
```bash
# Delete sessions older than 2 hours
find storage/framework/sessions -type f -mmin +120 -delete
```

## Alternative Solutions (Not Implemented)

### 1. Redis Sessions
Store sessions in Redis (no locking issues):
```env
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

**Pros**: Fast, no locking, works with load balancing
**Cons**: Requires Redis installation

### 2. Cookie Sessions
Store sessions in encrypted cookies:
```env
SESSION_DRIVER=cookie
```

**Pros**: No server storage, no locking
**Cons**: Limited size (4KB), security concerns

### 3. Memcached Sessions
Store sessions in Memcached:
```env
SESSION_DRIVER=memcached
MEMCACHED_HOST=127.0.0.1
```

**Pros**: Fast, no locking
**Cons**: Requires Memcached installation

We chose **file sessions** because:
- Simple to implement (no new infrastructure)
- No locking issues
- Fast enough for single-server setup
- Works with existing code

## Files Modified

1. `.env` - Changed `SESSION_DRIVER=database` to `SESSION_DRIVER=file`

## Related Files

- `config/session.php` - Session configuration
- `storage/framework/sessions/` - Session storage directory
- `codes/fix-session-blocking.ps1` - Automated fix script

## Monitoring

Check session files:
```bash
# Count active sessions
ls storage/framework/sessions | wc -l

# View session file
cat storage/framework/sessions/sess_abc123

# Monitor session creation
watch -n 1 'ls -lt storage/framework/sessions | head -10'
```

## Troubleshooting

### Issue: "Permission denied" error
```bash
# Windows
icacls storage\framework\sessions /grant "Everyone:(OI)(CI)F" /T

# Linux
chmod 775 storage/framework/sessions
chown -R www-data:www-data storage/framework/sessions
```

### Issue: Sessions not persisting
- Check `storage/framework/sessions` directory exists
- Check write permissions
- Check `SESSION_LIFETIME` in `.env`

### Issue: Still blocking after fix
- Clear browser cache and cookies
- Run `php artisan config:clear`
- Restart web server
- Check `.env` file was actually updated

---

**Status**: ✅ Complete
**Date**: 2026-03-09
**Issue**: Database session locking blocks portal during downloads
**Solution**: Switch from database sessions to file sessions
**Impact**: Portal remains responsive during downloads
