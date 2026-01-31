# Update Sync - Quick Start Guide

## ✅ The Fix is Complete

The update sync system now correctly writes to **date-partitioned tables** (`alerts_2026_01_08`, etc.) instead of trying to write to a non-existent single `alerts` table.

## 🚀 Quick Start (3 Steps)

### Step 1: Test It Works

```bash
php test_update_sync.php
```

**Expected:** ✓ Alert synced successfully!

### Step 2: Start the Worker

```bash
php artisan sync:update-worker
```

**Expected:** Processing pending entries and marking them completed

### Step 3: Verify Updates

```sql
-- MySQL - Check completed count is increasing
SELECT status, COUNT(*) FROM alert_pg_update_log GROUP BY status;

-- PostgreSQL - Check data is syncing
SELECT table_name, record_count FROM partition_registry ORDER BY partition_date DESC LIMIT 5;
```

## 📊 Monitor Status

### Check Pending Queue

```sql
-- MySQL
SELECT COUNT(*) as pending FROM alert_pg_update_log WHERE status=1;
```

**Should decrease over time as worker processes updates**

### Check Worker Logs

```bash
tail -f storage/logs/laravel.log | grep "Alert synced"
```

**Should see:** `Alert synced to partition successfully`

## ⚙️ Configuration

### Faster Sync

```bash
php artisan sync:update-worker --poll-interval=3 --batch-size=200
```

### Run as Service (Production)

**Linux (Supervisor):**
```bash
sudo nano /etc/supervisor/conf.d/update-sync-worker.conf
```

```ini
[program:update-sync-worker]
command=php /path/to/app/artisan sync:update-worker
autostart=true
autorestart=true
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start update-sync-worker
```

**Windows (NSSM):**
```powershell
nssm install UpdateSyncWorker "C:\php\php.exe" "C:\path\to\app\artisan sync:update-worker"
nssm start UpdateSyncWorker
```

## 🔍 Troubleshooting

### Worker Not Processing?

```bash
# Check if running
ps aux | grep sync:update-worker

# Check logs
tail -f storage/logs/laravel.log

# Test database connections
php artisan tinker
>>> DB::connection('mysql')->getPdo();
>>> DB::connection('pgsql')->getPdo();
```

### All Updates Failing?

```sql
-- Check error messages
SELECT error_message, COUNT(*) 
FROM alert_pg_update_log 
WHERE status=3 
GROUP BY error_message;
```

## 📚 Documentation

- **UPDATE_SYNC_READY.md** - Complete guide
- **UPDATE_SYNC_PARTITION_FIX.md** - Technical details
- **UPDATE_SYNC_PARTITION_ISSUE.md** - Problem analysis
- **docs/UPDATE_SYNC_WORKER.md** - Worker documentation

## ✨ What Changed

**Before (BROKEN):**
```php
// Tried to write to non-existent 'alerts' table
SyncedAlert::updateOrCreate(['id' => $alertId], $data);
```

**After (FIXED):**
```php
// Writes to correct partition table based on receivedtime
$date = $this->dateExtractor->extractDate($data['receivedtime']);
$partitionTable = $this->partitionManager->getPartitionTableName($date);
$this->partitionManager->ensurePartitionExists($date);
DB::connection('pgsql')->table($partitionTable)->upsert(...);
```

## 🎯 Expected Behavior

```
MySQL alert_pg_update_log:
  status=1 (pending)    → Decreasing
  status=2 (completed)  → Increasing
  status=3 (failed)     → Should be 0 or very few

PostgreSQL partition_registry:
  record_count → Increasing as updates sync
  
PostgreSQL partition tables:
  alerts_2026_01_08 → Contains updated alerts
  alerts_2026_01_09 → Contains updated alerts
  ...
```

## 🚦 Status Codes

- **status=1** - Pending (waiting to be processed)
- **status=2** - Completed (successfully synced)
- **status=3** - Failed (error occurred, check error_message)

## 💡 Pro Tips

1. **Monitor the pending queue** - Should stay low (< 1000)
2. **Check logs regularly** - Look for errors or warnings
3. **Tune batch size** - Larger = faster, but more memory
4. **Run as service** - Ensures worker always running
5. **Set up alerts** - Get notified if queue grows too large

---

**Ready to go! Start the worker and watch your updates sync to PostgreSQL partitions! 🎉**
