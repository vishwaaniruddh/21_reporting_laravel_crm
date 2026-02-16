# Redis Setup Status

## What Was Installed

### ✅ Completed Steps

1. **Redis Server** - Installed and running
   - Location: `C:\Redis`
   - Port: 6379
   - Status: Running
   - Test: `redis-cli ping` returns `PONG`

2. **PHP Redis Extension (CLI)** - Working
   - File: `C:\wamp64\bin\php\php8.4.11\ext\php_redis.dll`
   - Status: Loaded in CLI
   - Test: `php -m | findstr redis` shows `redis`

3. **Database Migration** - Complete
   - Table: `export_jobs_v2` created
   - Status: Ready

4. **V2 Queue Worker Service** - Running
   - Service: `AlertPortalQueueWorkerV2`
   - Status: Running
   - Queue: `exports-v2` (Redis)
   - Logs: `storage\logs\queue-worker-v2-service.log`

5. **Configuration** - Updated
   - `.env` has Redis configuration
   - Config cache cleared

### ⚠️ Issue: Apache PHP Not Loading Redis

**Problem**: Redis extension works in CLI but not in Apache/web server

**Symptoms**:
- `php -m` shows redis ✅
- Web requests get error: "Class 'Redis' not found" ❌

**What We Tried**:
1. ✅ Added `extension=redis` to Apache php.ini
2. ✅ Copied `php_redis.dll` to Apache bin folder
3. ✅ Restarted Apache multiple times

**Next Steps to Fix**:

1. **Check if Redis is loaded in Apache**:
   - Open: http://192.168.100.21:9000/test-redis.php
   - Should show if Redis extension is loaded

2. **If NOT loaded, possible causes**:
   - Apache using different PHP version
   - Wrong php.ini file
   - DLL architecture mismatch (x86 vs x64)
   - Missing Visual C++ Redistributable

## Quick Verification Commands

```powershell
# Check Redis server
redis-cli ping
# Should return: PONG

# Check PHP CLI has Redis
php -m | findstr redis
# Should show: redis

# Check V2 worker service
Get-Service AlertPortalQueueWorkerV2
# Should show: Running

# Check worker logs
Get-Content storage\logs\queue-worker-v2-service.log -Tail 20

# Test web Redis (open in browser)
# http://192.168.100.21:9000/test-redis.php
```

## If Redis Works in Apache

Once the test page shows Redis is working, test the V2 API:

```powershell
$token = "YOUR_TOKEN"
$headers = @{"Authorization" = "Bearer $token"}

# Test partitions
Invoke-RestMethod -Uri "http://192.168.100.21:9000/api/downloads-v2/partitions?type=all-alerts" -Headers $headers

# Request export
$body = '{"type":"all-alerts","date":"2026-01-30"}' | ConvertFrom-Json
Invoke-RestMethod -Uri "http://192.168.100.21:9000/api/downloads-v2/request" -Method POST -Headers $headers -Body ($body | ConvertTo-Json) -ContentType "application/json"
```

## Alternative: Use Database Queue for Now

If Redis in Apache is too difficult to fix, you can temporarily use the database queue for V2:

1. Update `config/queue.php`:
   ```php
   'connections' => [
       'exports-v2' => [
           'driver' => 'database',
           'table' => 'jobs',
           'queue' => 'exports-v2',
       ],
   ]
   ```

2. Update worker batch file to use database:
   ```bat
   php artisan queue:work database --queue=exports-v2
   ```

This won't be as fast as Redis, but V2 will work while you troubleshoot Redis.

## Files Created

- `app/Http/Controllers/DownloadsV2Controller.php`
- `app/Jobs/GenerateCsvExportJobV2.php`
- `database/migrations/2026_01_31_162604_create_export_jobs_v2_table.php`
- `routes/api.php` (V2 routes added)
- `codes/queue-worker-v2.bat`
- `codes/install-redis-windows.ps1`
- `codes/install-php-redis-extension.ps1`
- `codes/setup-redis-config.ps1`
- `codes/create-queue-worker-v2-service.ps1`
- `codes/test-downloads-v2.ps1`
- `public/test-redis.php` (diagnostic page)
- Documentation in `Documents/DOWNLOADS_V2_*.md`

## Current Status

- ✅ Redis server: Working
- ✅ PHP CLI Redis: Working
- ✅ V2 worker service: Running
- ✅ Database table: Created
- ❌ Apache PHP Redis: **NOT WORKING** (needs fix)

## Next Action

**Open in browser**: http://192.168.100.21:9000/test-redis.php

This will show exactly what's wrong with Apache's Redis setup.
