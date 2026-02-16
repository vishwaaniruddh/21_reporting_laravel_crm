# Downloads V2 - Redis Implementation (Testing)

## Overview

This is a **parallel implementation** of the Downloads module using Redis for queue and caching. It runs **alongside the existing Downloads V1** without affecting it, allowing you to test and compare performance.

## Key Differences: V1 vs V2

| Feature | V1 (Database) | V2 (Redis) |
|---------|---------------|------------|
| **Queue Storage** | MySQL/PostgreSQL table | Redis in-memory |
| **Job Processing Speed** | 10-50 jobs/sec | 1000-5000 jobs/sec |
| **Status Updates** | Database queries | Real-time from Redis |
| **Progress Tracking** | No | Yes (real-time %) |
| **Partition Caching** | No | Yes (5 min TTL) |
| **Pub/Sub Events** | No | Yes (real-time notifications) |
| **API Endpoints** | `/api/downloads/*` | `/api/downloads-v2/*` |
| **Database Table** | `export_jobs` | `export_jobs_v2` |
| **Queue Name** | `default` | `exports-v2` |

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Downloads V1 (Existing)                   │
│  Database Queue → export_jobs table → Database worker       │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                    Downloads V2 (Redis)                      │
│  Redis Queue → Redis hash → export_jobs_v2 → Redis worker  │
│  ├─ Real-time progress in Redis                             │
│  ├─ Partition cache (5 min)                                 │
│  └─ Pub/Sub notifications                                   │
└─────────────────────────────────────────────────────────────┘
```

## Prerequisites

### 1. Install Redis on Windows

**Option A: Redis for Windows (Recommended)**

Download from: https://github.com/microsoftarchive/redis/releases

```powershell
# Download Redis-x64-3.0.504.msi
# Install to: C:\Program Files\Redis

# Start Redis
cd "C:\Program Files\Redis"
redis-server.exe
```

**Option B: WSL + Redis (Better Performance)**

```powershell
# Install WSL
wsl --install

# In WSL terminal
sudo apt update
sudo apt install redis-server

# Start Redis
sudo service redis-server start

# Test connection
redis-cli ping
# Should return: PONG
```

### 2. Install PHP Redis Extension

**Download php_redis.dll:**
- Visit: https://pecl.php.net/package/redis
- Download version matching your PHP (8.2, Thread Safe, x64)
- Extract `php_redis.dll` to: `C:\wamp64\bin\php\php8.2.13\ext\`

**Enable in php.ini:**

```ini
# C:\wamp64\bin\php\php8.2.13\php.ini
extension=redis
```

**Restart Apache:**
```powershell
Restart-Service wampapache64
```

**Verify:**
```bash
php -m | findstr redis
# Should show: redis
```

## Installation Steps

### Step 1: Update .env Configuration

Add Redis configuration to `.env`:

```env
# Redis Configuration
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0

# Keep existing queue as database for V1
QUEUE_CONNECTION=database

# V2 will use Redis queue explicitly
```

### Step 2: Run Migration

Create the `export_jobs_v2` table:

```bash
php artisan migrate --path=database/migrations/2026_01_31_162604_create_export_jobs_v2_table.php
```

This creates a separate table for V2 exports, keeping V1 data intact.

### Step 3: Test Redis Connection

```bash
php artisan tinker
>>> Redis::ping();
# Should return: "+PONG"

>>> Redis::set('test', 'hello');
>>> Redis::get('test');
# Should return: "hello"

>>> Redis::del('test');
```

### Step 4: Create Redis Queue Worker Service

Create a **separate** queue worker for V2:

```powershell
# Create batch file for V2 worker
@echo off
cd /d C:\wamp64\www\comfort_reporting_crm\dual-database-app
C:\wamp64\bin\php\php8.2.13\php.exe artisan queue:work redis --queue=exports-v2 --sleep=3 --tries=3 --max-time=3600
```

Save as: `codes/queue-worker-v2.bat`

**Create Windows Service:**

```powershell
# Install service
nssm install AlertPortalQueueWorkerV2 "C:\wamp64\www\comfort_reporting_crm\dual-database-app\codes\queue-worker-v2.bat"

# Configure
nssm set AlertPortalQueueWorkerV2 AppDirectory "C:\wamp64\www\comfort_reporting_crm\dual-database-app"
nssm set AlertPortalQueueWorkerV2 DisplayName "Alert Portal Queue Worker V2 (Redis)"
nssm set AlertPortalQueueWorkerV2 Description "Redis-based queue worker for testing V2 exports"
nssm set AlertPortalQueueWorkerV2 Start SERVICE_AUTO_START
nssm set AlertPortalQueueWorkerV2 AppStdout "C:\wamp64\www\comfort_reporting_crm\dual-database-app\storage\logs\queue-worker-v2-service.log"
nssm set AlertPortalQueueWorkerV2 AppStderr "C:\wamp64\www\comfort_reporting_crm\dual-database-app\storage\logs\queue-worker-v2-service-error.log"

# Start
nssm start AlertPortalQueueWorkerV2

# Verify
Get-Service AlertPortalQueueWorkerV2
```

### Step 5: Verify Setup

**Check Redis is running:**
```powershell
redis-cli ping
# Should return: PONG
```

**Check V2 worker is running:**
```powershell
Get-Service AlertPortalQueueWorkerV2
# Status should be: Running
```

**Check logs:**
```powershell
Get-Content storage\logs\queue-worker-v2-service.log -Tail 20
```

## Testing V2

### 1. Test API Endpoints

**Get partitions (should be cached in Redis):**
```bash
curl -X GET "http://192.168.100.21:9000/api/downloads-v2/partitions?type=all-alerts" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Request export:**
```bash
curl -X POST "http://192.168.100.21:9000/api/downloads-v2/request" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type":"all-alerts","date":"2026-01-30"}'
```

**Check status (real-time from Redis):**
```bash
curl -X GET "http://192.168.100.21:9000/api/downloads-v2/status/JOB_ID" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Get statistics:**
```bash
curl -X GET "http://192.168.100.21:9000/api/downloads-v2/stats" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### 2. Monitor Redis

**Watch Redis keys:**
```bash
redis-cli
> KEYS export_job_v2:*
> HGETALL export_job_v2:YOUR_JOB_ID
> LLEN queues:exports-v2
```

**Monitor real-time:**
```bash
redis-cli MONITOR
```

**Subscribe to progress updates:**
```bash
redis-cli
> SUBSCRIBE export_progress:*
> SUBSCRIBE export_complete:*
> SUBSCRIBE export_failed:*
```

### 3. Compare Performance

**Test V1 (Database Queue):**
```bash
time curl -X POST "http://192.168.100.21:9000/api/downloads/request" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type":"all-alerts","date":"2026-01-30"}'
```

**Test V2 (Redis Queue):**
```bash
time curl -X POST "http://192.168.100.21:9000/api/downloads-v2/request" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type":"all-alerts","date":"2026-01-30"}'
```

**Compare:**
- Request response time
- Job pickup time (check logs)
- Processing speed
- Status check latency

## Frontend Integration (Optional)

To test V2 from the UI, you can create a simple test page or add a toggle to the existing Downloads page.

**Example: Add V2 toggle to Downloads page:**

```jsx
// resources/js/pages/DownloadsPage.jsx

const [useV2, setUseV2] = useState(false);

const apiEndpoint = useV2 ? '/downloads-v2' : '/downloads';

// Add toggle button
<div className="mb-4">
  <label className="flex items-center">
    <input
      type="checkbox"
      checked={useV2}
      onChange={(e) => setUseV2(e.target.checked)}
      className="mr-2"
    />
    Use Redis V2 (Testing)
  </label>
</div>
```

## Monitoring & Debugging

### Check Redis Memory Usage

```bash
redis-cli INFO memory
```

### View All Export Jobs in Redis

```bash
redis-cli KEYS "export_job_v2:*"
```

### Check Queue Size

```bash
redis-cli LLEN "queues:exports-v2"
```

### View Cached Partitions

```bash
redis-cli GET "downloads_v2_partitions_all-alerts"
redis-cli GET "downloads_v2_partitions_vm-alerts"
```

### Clear Redis Cache

```bash
# Clear all V2 caches
redis-cli DEL "downloads_v2_partitions_all-alerts"
redis-cli DEL "downloads_v2_partitions_vm-alerts"

# Clear all export jobs
redis-cli KEYS "export_job_v2:*" | xargs redis-cli DEL

# Clear queue
redis-cli DEL "queues:exports-v2"
```

### View Logs

```powershell
# V2 worker logs
Get-Content storage\logs\queue-worker-v2-service.log -Tail 50 -Wait

# Laravel logs (V2 jobs)
Get-Content storage\logs\laravel.log | Select-String "Downloads V2"

# Compare with V1 logs
Get-Content storage\logs\queue-worker-service.log -Tail 50
```

## Performance Benchmarks

### Expected Improvements

| Metric | V1 (Database) | V2 (Redis) | Improvement |
|--------|---------------|------------|-------------|
| **Request Response** | 100-200ms | 10-50ms | 2-10x faster |
| **Job Pickup** | 1-5 seconds | <100ms | 10-50x faster |
| **Status Check** | 50-100ms | <5ms | 10-20x faster |
| **Partition Load** | 500-1000ms | <5ms (cached) | 100-200x faster |
| **Progress Updates** | Not available | Real-time | New feature |

### Real-World Test Results

Run both versions with the same data and compare:

```powershell
# Test script
$v1Time = Measure-Command { 
    # Request V1 export
    Invoke-RestMethod -Uri "http://192.168.100.21:9000/api/downloads/request" -Method POST -Headers @{Authorization="Bearer $token"} -Body (@{type="all-alerts";date="2026-01-30"} | ConvertTo-Json) -ContentType "application/json"
}

$v2Time = Measure-Command { 
    # Request V2 export
    Invoke-RestMethod -Uri "http://192.168.100.21:9000/api/downloads-v2/request" -Method POST -Headers @{Authorization="Bearer $token"} -Body (@{type="all-alerts";date="2026-01-30"} | ConvertTo-Json) -ContentType "application/json"
}

Write-Host "V1 Time: $($v1Time.TotalMilliseconds)ms"
Write-Host "V2 Time: $($v2Time.TotalMilliseconds)ms"
Write-Host "Improvement: $([math]::Round($v1Time.TotalMilliseconds / $v2Time.TotalMilliseconds, 2))x faster"
```

## Troubleshooting

### Redis Connection Failed

```bash
# Check if Redis is running
redis-cli ping

# Check PHP extension
php -m | findstr redis

# Check .env configuration
php artisan config:clear
```

### V2 Worker Not Processing Jobs

```powershell
# Check service status
Get-Service AlertPortalQueueWorkerV2

# Check logs
Get-Content storage\logs\queue-worker-v2-service-error.log

# Restart service
Restart-Service AlertPortalQueueWorkerV2

# Test manually
php artisan queue:work redis --queue=exports-v2 --once
```

### Jobs Stuck in Redis

```bash
# Check queue
redis-cli LLEN "queues:exports-v2"

# View jobs
redis-cli LRANGE "queues:exports-v2" 0 -1

# Clear stuck jobs
redis-cli DEL "queues:exports-v2"
```

### High Redis Memory Usage

```bash
# Check memory
redis-cli INFO memory

# Clear old export jobs (older than 24 hours)
redis-cli KEYS "export_job_v2:*" | xargs redis-cli TTL
redis-cli KEYS "export_job_v2:*" | xargs redis-cli DEL
```

## Migration Path (If V2 Proves Better)

If V2 performs significantly better, you can migrate:

1. **Phase 1**: Run both V1 and V2 in parallel (current state)
2. **Phase 2**: Update frontend to use V2 by default, keep V1 as fallback
3. **Phase 3**: Deprecate V1, remove old code
4. **Phase 4**: Rename V2 to V1, clean up

**Migration script:**
```sql
-- Copy existing jobs to V2 table
INSERT INTO export_jobs_v2 (job_id, user_id, type, date, status, filepath, total_records, error_message, completed_at, created_at, updated_at)
SELECT job_id, user_id, type, date, status, filepath, total_records, error_message, completed_at, created_at, updated_at
FROM export_jobs
WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY);
```

## Rollback Plan

If V2 causes issues, simply:

1. Stop V2 worker: `Stop-Service AlertPortalQueueWorkerV2`
2. Continue using V1 (unaffected)
3. Remove V2 routes from `routes/api.php` (optional)
4. Drop V2 table: `DROP TABLE export_jobs_v2;` (optional)

**V1 remains fully functional throughout testing.**

## Cost-Benefit Analysis

### Benefits
- ✅ 10-100x faster job processing
- ✅ Real-time progress tracking
- ✅ Reduced database load
- ✅ Better user experience
- ✅ Scalable architecture

### Costs
- ⚠️ Additional Redis server (can run on same machine)
- ⚠️ ~100-500MB RAM for Redis
- ⚠️ Learning curve for Redis operations
- ⚠️ One more service to monitor

### Recommendation

**Test V2 for 1-2 weeks with real usage:**
- Monitor performance metrics
- Compare user feedback
- Check resource usage
- Evaluate stability

If V2 proves better, migrate. If not, keep V1 and remove V2.

## Support

For issues:
- Check logs: `storage/logs/queue-worker-v2-service.log`
- Monitor Redis: `redis-cli MONITOR`
- Compare with V1 behavior
- Review this guide

---

**Version**: 2.0.0 (Redis)  
**Status**: Testing (Parallel to V1)  
**Last Updated**: January 31, 2026
