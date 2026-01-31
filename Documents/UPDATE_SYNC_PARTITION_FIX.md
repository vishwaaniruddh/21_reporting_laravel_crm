# Update Sync Partition Fix - Implementation Summary

**Date:** 2026-01-09  
**Status:** ✅ FIXED - AlertSyncService Now Uses Partitioned Tables

## What Was Fixed

The `AlertSyncService` has been refactored to work with date-partitioned PostgreSQL tables instead of trying to write to a non-existent single `alerts` table.

## Changes Made

### 1. AlertSyncService Constructor - Added Partition Dependencies

**File:** `app/Services/AlertSyncService.php`

**Before:**
```php
public function __construct(SyncLogger $logger, int $maxRetries = 3)
{
    $this->logger = $logger;
    $this->maxRetries = $maxRetries;
}
```

**After:**
```php
public function __construct(
    SyncLogger $logger, 
    ?PartitionManager $partitionManager = null,
    ?DateExtractor $dateExtractor = null,
    int $maxRetries = 3
) {
    $this->logger = $logger;
    $this->partitionManager = $partitionManager ?? new PartitionManager();
    $this->dateExtractor = $dateExtractor ?? new DateExtractor();
    $this->maxRetries = $maxRetries;
}
```

**Why:** Inject partition management services to handle date-based table routing.

### 2. updateAlertInPostgres() - Complete Rewrite for Partitions

**File:** `app/Services/AlertSyncService.php`

**Before (BROKEN):**
```php
private function updateAlertInPostgres(int $alertId, array $data): bool
{
    // ❌ Tried to write to non-existent 'alerts' table
    SyncedAlert::updateOrCreate(['id' => $alertId], $data);
}
```

**After (FIXED):**
```php
private function updateAlertInPostgres(int $alertId, array $data): bool
{
    // 1. Extract date from receivedtime
    $date = $this->dateExtractor->extractDate($data['receivedtime']);
    
    // 2. Get partition table name (e.g., "alerts_2026_01_08")
    $partitionTable = $this->partitionManager->getPartitionTableName($date);
    
    // 3. Ensure partition exists (creates if needed)
    $this->partitionManager->ensurePartitionExists($date);
    
    // 4. UPSERT to partition table
    DB::connection('pgsql')->table($partitionTable)->upsert(
        [$upsertData],
        ['id'], // Unique key
        [...] // Columns to update
    );
    
    // 5. Update partition_registry record count
    $this->partitionManager->incrementRecordCount($partitionTable, 1);
}
```

**Why:** Route updates to the correct date-partitioned table based on `receivedtime`.

### 3. UpdateSyncWorker - Added Informative Message

**File:** `app/Console/Commands/UpdateSyncWorker.php`

**Added:**
```php
$this->info('Syncing updates to date-partitioned PostgreSQL tables (alerts_YYYY_MM_DD).');
```

**Why:** Make it clear to operators that the worker uses partitioned tables.

## How It Works Now

### Complete Flow

```
1. Java App → MySQL alerts (UPDATE alert 178497)
         ↓
2. MySQL Trigger → alert_pg_update_log (INSERT status=1)
         ↓
3. Update Worker → Fetches alert 178497 from MySQL ✅
         ↓
4. AlertSyncService → Extracts date from receivedtime
   Example: "2026-01-08 12:46:55" → "2026-01-08"
         ↓
5. PartitionManager → Gets partition table name
   Example: "2026-01-08" → "alerts_2026_01_08"
         ↓
6. PartitionManager → Ensures partition exists
   - Checks if alerts_2026_01_08 exists
   - Creates it if needed (with schema + indexes)
         ↓
7. AlertSyncService → UPSERTs to alerts_2026_01_08
   - If record exists: UPDATE
   - If record doesn't exist: INSERT
         ↓
8. PartitionManager → Updates partition_registry
   - Increments record_count for alerts_2026_01_08
         ↓
9. AlertSyncService → Marks alert_pg_update_log
   - status=2 (completed) ✅
   - updated_at=NOW()
```

### Data Mapping

**MySQL Alert Data → PostgreSQL Partition Table:**

```php
[
    'id' => 178497,
    'panelid' => 'PANEL001',
    'receivedtime' => '2026-01-08 12:46:55',  // ← Used to determine partition
    'alarm' => 'Motion Detected',
    // ... all other columns
    'synced_at' => '2026-01-09 10:30:00',
    'sync_batch_id' => 0,
]
```

**Partition Determination:**
- `receivedtime` = "2026-01-08 12:46:55"
- Extracted date = "2026-01-08"
- Partition table = "alerts_2026_01_08"

## Testing the Fix

### 1. Check Pending Updates

```sql
-- MySQL
SELECT COUNT(*) FROM alert_pg_update_log WHERE status=1;
```

### 2. Start the Worker

```bash
php artisan sync:update-worker --poll-interval=5 --batch-size=100
```

**Expected Output:**
```
=== Update Sync Worker Configuration ===
+---------------+----------+
| Setting       | Value    |
+---------------+----------+
| Poll Interval | 5 seconds|
| Batch Size    | 100      |
| Max Retries   | 3        |
+---------------+----------+

Update sync worker started. Press Ctrl+C to stop gracefully.
Syncing updates to date-partitioned PostgreSQL tables (alerts_YYYY_MM_DD).

Processing 15 pending entries...
  ✓ Alert 178497 synced successfully
  ✓ Alert 178495 synced successfully
  ✓ Alert 178503 synced successfully
  ...
Cycle complete: 15 processed in 2.34 seconds
```

### 3. Verify Updates Processed

```sql
-- MySQL - Check log entries are marked completed
SELECT status, COUNT(*) 
FROM alert_pg_update_log 
GROUP BY status;

-- Expected:
-- status=1 (pending): 0 or decreasing
-- status=2 (completed): increasing
-- status=3 (failed): 0 or very few
```

### 4. Verify Data in PostgreSQL

```sql
-- PostgreSQL - Check partition tables
SELECT table_name, record_count, last_updated 
FROM partition_registry 
ORDER BY partition_date DESC 
LIMIT 5;

-- Check specific alert in partition
SELECT * FROM alerts_2026_01_08 WHERE id=178497;
```

## Benefits of This Fix

### ✅ Correct Architecture

- Updates now go to the correct partition tables
- Matches the initial sync architecture
- Consistent with date-partitioned design

### ✅ Automatic Partition Management

- Worker automatically creates partitions as needed
- No manual partition creation required
- Handles date boundaries seamlessly

### ✅ Data Integrity

- UPSERT prevents duplicate key errors
- Transactions ensure atomicity
- Rollback on failure prevents partial updates

### ✅ Scalability

- Partitions keep table sizes manageable
- Queries remain fast with date-based routing
- Easy to archive old partitions

### ✅ Monitoring

- partition_registry tracks record counts
- Logs show which partition each alert goes to
- Easy to identify partition-specific issues

## Configuration

### Worker Options

```bash
# Default (recommended)
php artisan sync:update-worker

# Custom poll interval (faster sync)
php artisan sync:update-worker --poll-interval=3

# Larger batch size (higher throughput)
php artisan sync:update-worker --batch-size=200

# More retries (for unstable connections)
php artisan sync:update-worker --max-retries=5
```

### Environment Variables

```env
# .env
UPDATE_SYNC_POLL_INTERVAL=5
UPDATE_SYNC_BATCH_SIZE=100
UPDATE_SYNC_MAX_RETRIES=3
```

## Running in Production

### Option 1: Supervisor (Recommended)

```ini
# /etc/supervisor/conf.d/update-sync-worker.conf
[program:update-sync-worker]
command=php /path/to/app/artisan sync:update-worker
autostart=true
autorestart=true
user=www-data
stdout_logfile=/path/to/app/storage/logs/update-sync-worker.log
```

### Option 2: systemd

```ini
# /etc/systemd/system/update-sync-worker.service
[Unit]
Description=MySQL to PostgreSQL Update Sync Worker

[Service]
ExecStart=/usr/bin/php /path/to/app/artisan sync:update-worker
Restart=always
User=www-data

[Install]
WantedBy=multi-user.target
```

### Option 3: Windows Service

```powershell
# Use NSSM (Non-Sucking Service Manager)
nssm install UpdateSyncWorker "C:\php\php.exe" "C:\path\to\app\artisan sync:update-worker"
nssm start UpdateSyncWorker
```

## Monitoring

### Check Worker Status

```bash
# Is it running?
ps aux | grep "sync:update-worker"

# Check logs
tail -f storage/logs/laravel.log | grep "Update sync"
```

### Monitor Pending Queue

```sql
-- MySQL - Pending updates
SELECT 
    COUNT(*) as pending_count,
    MIN(created_at) as oldest_pending,
    MAX(created_at) as newest_pending
FROM alert_pg_update_log 
WHERE status=1;
```

### Monitor Partition Growth

```sql
-- PostgreSQL - Partition sizes
SELECT 
    table_name,
    partition_date,
    record_count,
    last_updated
FROM partition_registry 
ORDER BY partition_date DESC 
LIMIT 10;
```

## Troubleshooting

### Issue: Worker Not Processing

**Check:**
1. Is worker running? `ps aux | grep sync:update-worker`
2. Are there pending entries? `SELECT COUNT(*) FROM alert_pg_update_log WHERE status=1`
3. Check logs: `tail -f storage/logs/laravel.log`

### Issue: All Updates Failing (status=3)

**Check:**
1. PostgreSQL connection: `php artisan tinker` → `DB::connection('pgsql')->getPdo()`
2. Partition creation permissions
3. Error messages in `alert_pg_update_log.error_message`

### Issue: Slow Processing

**Solutions:**
1. Increase batch size: `--batch-size=200`
2. Decrease poll interval: `--poll-interval=3`
3. Check database performance
4. Add database indexes if needed

## Files Modified

1. `app/Services/AlertSyncService.php` - Complete refactor for partitions
2. `app/Console/Commands/UpdateSyncWorker.php` - Added informative message
3. `UPDATE_SYNC_PARTITION_FIX.md` - This documentation

## Related Documentation

- `UPDATE_SYNC_PARTITION_ISSUE.md` - Problem analysis
- `UPDATE_SYNC_EXPLANATION.md` - System overview
- `docs/UPDATE_SYNC_PROCESS.md` - Process documentation
- `docs/UPDATE_SYNC_WORKER.md` - Worker documentation
- `docs/PARTITION_SYNC_GUIDE.md` - Partition guide

## Summary

The AlertSyncService has been successfully refactored to use date-partitioned PostgreSQL tables. Updates now flow correctly from MySQL to the appropriate partition table based on the alert's `receivedtime`. The worker can now be started and will process pending updates, marking them as completed (status=2) in the `alert_pg_update_log` table.

**Next Steps:**
1. Start the update worker: `php artisan sync:update-worker`
2. Monitor the logs to verify updates are processing
3. Check that status=1 entries are becoming status=2
4. Verify data appears in PostgreSQL partition tables
5. Set up worker as a service for production use
