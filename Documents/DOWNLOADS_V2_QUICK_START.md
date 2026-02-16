# Downloads V2 (Redis) - Quick Start

## What is This?

A **parallel implementation** of Downloads using Redis instead of database. Runs **alongside V1** without affecting it.

## Why Test This?

- 🚀 **10-100x faster** job processing
- ⚡ **Real-time** progress tracking
- 💾 **Cached** partition data
- 📊 **Better** user experience

## Quick Setup (15 Minutes)

### 1. Install Redis

**Windows:**
```powershell
# Download: https://github.com/microsoftarchive/redis/releases
# Install Redis-x64-3.0.504.msi
# Start: redis-server.exe
```

**Or use WSL:**
```bash
wsl --install
sudo apt install redis-server
sudo service redis-server start
redis-cli ping  # Should return: PONG
```

### 2. Install PHP Redis Extension

```powershell
# Download php_redis.dll for PHP 8.2 x64 TS
# Copy to: C:\wamp64\bin\php\php8.2.13\ext\

# Add to php.ini:
extension=redis

# Restart Apache
Restart-Service wampapache64

# Verify
php -m | findstr redis
```

### 3. Update .env

```env
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

### 4. Run Migration

```bash
php artisan migrate --path=database/migrations/2026_01_31_162604_create_export_jobs_v2_table.php
```

### 5. Create V2 Worker Service

```powershell
.\codes\create-queue-worker-v2-service.ps1
```

### 6. Verify

```powershell
# Check Redis
redis-cli ping

# Check V2 worker
Get-Service AlertPortalQueueWorkerV2

# Check logs
Get-Content storage\logs\queue-worker-v2-service.log -Tail 20
```

## Test It

### API Test

```bash
# Request export (V2)
curl -X POST "http://192.168.100.21:9000/api/downloads-v2/request" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type":"all-alerts","date":"2026-01-30"}'

# Check status (real-time from Redis)
curl -X GET "http://192.168.100.21:9000/api/downloads-v2/status/JOB_ID" \
  -H "Authorization: Bearer YOUR_TOKEN"

# Get stats
curl -X GET "http://192.168.100.21:9000/api/downloads-v2/stats" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Performance Test

```powershell
.\codes\test-v1-vs-v2-performance.ps1 -Token "YOUR_TOKEN"
```

## Monitor

```powershell
# Watch V2 worker
Get-Content storage\logs\queue-worker-v2-service.log -Tail 50 -Wait

# Watch Redis
redis-cli MONITOR

# Check queue size
redis-cli LLEN "queues:exports-v2"

# View jobs in Redis
redis-cli KEYS "export_job_v2:*"
```

## Compare V1 vs V2

| Feature | V1 (Database) | V2 (Redis) |
|---------|---------------|------------|
| **API** | `/api/downloads/*` | `/api/downloads-v2/*` |
| **Table** | `export_jobs` | `export_jobs_v2` |
| **Queue** | Database | Redis |
| **Speed** | Baseline | 10-100x faster |
| **Progress** | No | Yes (real-time) |
| **Cache** | No | Yes (5 min) |

## Troubleshooting

**Redis not connecting?**
```bash
redis-cli ping
php -m | findstr redis
php artisan config:clear
```

**V2 worker not running?**
```powershell
Get-Service AlertPortalQueueWorkerV2
Restart-Service AlertPortalQueueWorkerV2
Get-Content storage\logs\queue-worker-v2-service-error.log
```

**Jobs stuck?**
```bash
redis-cli LLEN "queues:exports-v2"
redis-cli DEL "queues:exports-v2"
```

## Rollback

If V2 causes issues:

```powershell
# Stop V2 worker
Stop-Service AlertPortalQueueWorkerV2

# V1 continues working normally
# No data loss, no downtime
```

## Next Steps

1. ✅ Setup complete
2. ⏳ Test for 1-2 weeks
3. 📊 Compare performance
4. 🎯 Decide: Keep V2 or rollback to V1

## Full Documentation

- Complete guide: `Documents/DOWNLOADS_V2_REDIS_SETUP.md`
- Performance comparison: Run `.\codes\test-v1-vs-v2-performance.ps1`

---

**Status**: Testing (Parallel to V1)  
**Risk**: Low (V1 unaffected)  
**Benefit**: Potentially 10-100x faster
