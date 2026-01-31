# Update Sync System - Explanation & Fix

**Date:** 2026-01-09 15:00 India Time  
**Status:** ❌ NOT WORKING (Not Scheduled)

## How the Update System SHOULD Work

### Architecture Overview

```
MySQL alerts table (UPDATED)
         ↓
    [MySQL Trigger]
         ↓
MySQL alert_pg_update_log (status=1 pending)
         ↓
   [Update Sync Worker] ← **THIS IS MISSING!**
         ↓
PostgreSQL partition tables (UPDATED)
         ↓
MySQL alert_pg_update_log (status=2 completed)
```

### Components That Exist

1. **MySQL Trigger** ✅ (Assumed to exist)
   - Watches for UPDATEs on `mysql.alerts`
   - Inserts record into `mysql.alert_pg_update_log` with `status=1`

2. **AlertUpdateLog Model** ✅
   - File: `app/Models/AlertUpdateLog.php`
   - Maps to `mysql.alert_pg_update_log` table
   - Status values: 1=pending, 2=completed, 3=failed

3. **UpdateLogMonitor Service** ✅
   - File: `app/Services/UpdateLogMonitor.php`
   - Fetches pending entries (status=1) from log table
   - Only does SELECT queries (read-only)

4. **AlertSyncService** ✅
   - File: `app/Services/AlertSyncService.php`
   - Processes individual update:
     1. Reads alert data from MySQL (READ-ONLY)
     2. Updates PostgreSQL partition table (UPSERT)
     3. Marks log entry as processed (status=2 or 3)

5. **UpdateSyncWorker Command** ✅
   - File: `app/Console/Commands/UpdateSyncWorker.php`
   - Continuous worker that:
     - Fetches pending entries
     - Processes each entry
     - Sleeps between cycles
     - Logs metrics

### The Missing Piece

❌ **The UpdateSyncWorker is NOT scheduled in `routes/console.php`**

This means:
- The trigger creates log entries (status=1)
- But NO process is reading and processing them
- All updates stay pending forever
- PostgreSQL never gets updated

## Current State

Looking at your screenshot of `mysql.alert_pg_update_log`:

```
alert_id | status | created_at          | updated_at | error_message | retry_count
---------|--------|---------------------|------------|---------------|------------
178497   | 1      | 2026-01-08 12:46:55 | NULL       | NULL          | 0
178495   | 1      | 2026-01-08 12:46:55 | NULL       | NULL          | 0
178503   | 1      | 2026-01-08 12:46:55 | NULL       | NULL          | 0
...
```

**Analysis:**
- ✅ Trigger is working (records are being created)
- ❌ Worker is NOT running (all status=1, updated_at=NULL)
- ❌ No updates are being processed
- ❌ PostgreSQL has stale data

## How It Should Look When Working

```
alert_id | status | created_at          | updated_at          | error_message | retry_count
---------|--------|---------------------|---------------------|---------------|------------
178497   | 2      | 2026-01-08 12:46:55 | 2026-01-08 12:47:10 | NULL          | 0
178495   | 2      | 2026-01-08 12:46:55 | 2026-01-08 12:47:11 | NULL          | 0
178503   | 2      | 2026-01-08 12:46:55 | 2026-01-08 12:47:12 | NULL          | 0
```

**When working:**
- status = 2 (completed)
- updated_at = timestamp when processed
- error_message = NULL (if successful)

## Code Flow Explanation

### 1. UpdateLogMonitor (Fetches Pending)

```php
// app/Services/UpdateLogMonitor.php
public function fetchPendingEntries(): Collection
{
    return DB::connection('mysql')
        ->table('alert_pg_update_log')
        ->where('status', 1) // Pending only
        ->orderBy('created_at', 'asc') // Oldest first
        ->limit($this->batchSize)
        ->get();
}
```

### 2. AlertSyncService (Processes Update)

```php
// app/Services/AlertSyncService.php
public function syncAlert(int $logId, int $alertId): SyncResult
{
    try {
        // 1. Fetch alert from MySQL (READ-ONLY)
        $alert = DB::connection('mysql')
            ->table('alerts')
            ->where('id', $alertId)
            ->first();
        
        // 2. Determine partition table
        $partitionTable = $this->getPartitionTable($alert->receivedtime);
        
        // 3. UPSERT to PostgreSQL
        DB::connection('pgsql')
            ->table($partitionTable)
            ->upsert([...], ['id'], [...]);
        
        // 4. Mark as completed (status=2)
        $this->markLogEntryProcessed($logId, true);
        
        return new SyncResult(true, ...);
        
    } catch (\Exception $e) {
        // Mark as failed (status=3)
        $this->markLogEntryProcessed($logId, false, $e->getMessage());
        
        return new SyncResult(false, ...);
    }
}
```

### 3. UpdateSyncWorker (Continuous Loop)

```php
// app/Console/Commands/UpdateSyncWorker.php
protected function runCycle(): void
{
    // Fetch pending entries
    $entries = $this->monitor->fetchPendingEntries();
    
    if ($entries->isEmpty()) {
        $this->info('No pending entries');
        return;
    }
    
    // Process each entry
    foreach ($entries as $entry) {
        $result = $this->syncService->syncAlert(
            $entry->id,      // log ID
            $entry->alert_id // alert ID
        );
        
        if ($result->success) {
            $this->successCount++;
        } else {
            $this->failureCount++;
        }
    }
}
```

## Why This Design?

### Separation of Concerns

1. **Initial Sync** (DateGroupedSyncService):
   - Syncs NEW records from MySQL to PostgreSQL
   - Uses `synced_at` column to track
   - Runs every 20 minutes

2. **Update Sync** (UpdateSyncWorker):
   - Syncs UPDATED records from MySQL to PostgreSQL
   - Uses `alert_pg_update_log` table to track
   - Should run continuously

### Benefits

✅ **Decoupled**: Initial sync and update sync are independent  
✅ **Reliable**: Log-based approach ensures no updates are missed  
✅ **Traceable**: Every update is logged with status  
✅ **Recoverable**: Failed updates can be retried  
✅ **Auditable**: Full history of what was synced when  

## The Fix

We need to:

1. **Schedule the UpdateSyncWorker** in `routes/console.php`
2. **Start the worker** as a background process
3. **Monitor** that it's processing updates

---

**Summary:** The update sync system is fully implemented but NOT scheduled. All the code exists (trigger, models, services, worker) but the worker is never started, so all updates stay pending (status=1) and PostgreSQL never gets updated.
