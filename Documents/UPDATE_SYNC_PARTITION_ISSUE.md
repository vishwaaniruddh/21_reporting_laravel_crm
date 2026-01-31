# Update Sync Partition Issue - CRITICAL BUG

**Date:** 2026-01-09  
**Status:** ❌ BROKEN - Update Worker Not Using Partitioned Tables

## The Problem

The `AlertSyncService` (used by the Update Sync Worker) is **NOT using the partitioned table structure**. It's trying to write to a non-existent single `alerts` table instead of the date-partitioned tables (`alerts_2026_01_08`, `alerts_2026_01_09`, etc.).

## Current vs Required Architecture

### ❌ What AlertSyncService is Currently Doing (WRONG)

```php
// app/Services/AlertSyncService.php - Line ~220
private function updateAlertInPostgres(int $alertId, array $data): bool
{
    // ❌ WRONG: Uses SyncedAlert model which targets single 'alerts' table
    SyncedAlert::updateOrCreate(
        ['id' => $alertId],
        $data
    );
}
```

**Problem:** This tries to write to `public.alerts` table which **DOES NOT EXIST** in PostgreSQL!

### ✅ What It SHOULD Be Doing (CORRECT)

```php
// Should work like DateGroupedSyncService
private function updateAlertInPostgres(int $alertId, array $data): bool
{
    // 1. Extract date from receivedtime
    $date = $this->dateExtractor->extractDate($data['receivedtime']);
    
    // 2. Get partition table name (e.g., "alerts_2026_01_08")
    $partitionTable = $this->partitionManager->getPartitionTableName($date);
    
    // 3. Ensure partition exists (create if needed)
    $this->partitionManager->ensurePartitionExists($date);
    
    // 4. UPSERT to the correct partition table
    DB::connection('pgsql')->table($partitionTable)->upsert(
        [$data],
        ['id'], // Unique key
        [...] // Columns to update
    );
}
```

## PostgreSQL Table Structure

### What EXISTS in PostgreSQL

```sql
-- Partitioned tables (these exist)
public.alerts_2026_01_08  ✅
public.alerts_2026_01_09  ✅
public.alerts_2026_01_10  ✅
...

-- Registry table (exists)
public.partition_registry  ✅
```

### What DOES NOT EXIST

```sql
-- Single alerts table (does NOT exist)
public.alerts  ❌
```

## How DateGroupedSyncService Does It Correctly

The initial sync service (`DateGroupedSyncService`) correctly handles partitions:

```php
// app/Services/DateGroupedSyncService.php

public function syncDateGroup(Carbon $date, Collection $alerts): DateGroupResult
{
    // 1. Get partition table name from date
    $partitionTable = $this->partitionManager->getPartitionTableName($date);
    
    // 2. Ensure partition exists (creates if needed)
    $this->partitionManager->ensurePartitionExists($date);
    
    // 3. Insert to partition table
    $this->insertAlertsToPartition($alerts, $syncBatch->id, $partitionTable);
}

private function insertAlertsToPartition(Collection $alerts, int $batchId, string $partitionTable): void
{
    // Prepare data
    $insertData = $alerts->map(function ($alert) use ($batchId, $now) {
        return [
            'id' => $alert->id,
            'panelid' => $alert->panelid,
            // ... all columns
            'receivedtime' => $alert->receivedtime,
            'synced_at' => $now,
            'sync_batch_id' => $batchId,
        ];
    })->toArray();
    
    // UPSERT to partition table
    DB::connection('pgsql')->table($partitionTable)->upsert(
        $insertData,
        ['id'], // Unique key
        [...] // Columns to update if exists
    );
}
```

## The Fix Required

The `AlertSyncService` needs to be refactored to:

1. **Inject PartitionManager and DateExtractor**
   ```php
   public function __construct(
       SyncLogger $logger, 
       PartitionManager $partitionManager,
       DateExtractor $dateExtractor,
       int $maxRetries = 3
   )
   ```

2. **Extract date from alert data**
   ```php
   $date = $this->dateExtractor->extractDate($alertData['receivedtime']);
   ```

3. **Get partition table name**
   ```php
   $partitionTable = $this->partitionManager->getPartitionTableName($date);
   ```

4. **Ensure partition exists**
   ```php
   $this->partitionManager->ensurePartitionExists($date);
   ```

5. **UPSERT to partition table (not SyncedAlert model)**
   ```php
   DB::connection('pgsql')->table($partitionTable)->upsert(
       [$alertData],
       ['id'],
       [/* all columns */]
   );
   ```

6. **Update partition_registry record count**
   ```php
   $this->partitionManager->incrementRecordCount($partitionTable, 1);
   ```

## Why This Matters

### Current Behavior (BROKEN)

```
Java App → MySQL alerts (UPDATE alert 178497)
         ↓
MySQL Trigger → alert_pg_update_log (INSERT status=1)
         ↓
Update Worker → Fetches alert 178497 from MySQL ✅
         ↓
Update Worker → Tries to write to public.alerts ❌ (DOES NOT EXIST!)
         ↓
ERROR: relation "alerts" does not exist
         ↓
alert_pg_update_log → status=3 (failed) ❌
```

### Required Behavior (CORRECT)

```
Java App → MySQL alerts (UPDATE alert 178497)
         ↓
MySQL Trigger → alert_pg_update_log (INSERT status=1)
         ↓
Update Worker → Fetches alert 178497 from MySQL ✅
         ↓
Update Worker → Extracts date from receivedtime (2026-01-08) ✅
         ↓
Update Worker → Gets partition table (alerts_2026_01_08) ✅
         ↓
Update Worker → Ensures partition exists ✅
         ↓
Update Worker → UPSERTs to alerts_2026_01_08 ✅
         ↓
Update Worker → Updates partition_registry record count ✅
         ↓
Update Worker → Marks alert_pg_update_log status=2 ✅
```

## Impact

**Without this fix:**
- ❌ Update worker will FAIL on every alert
- ❌ All updates will be marked as failed (status=3)
- ❌ PostgreSQL will NEVER receive updates
- ❌ Data will be stale in PostgreSQL
- ❌ Reports will show outdated information

**With this fix:**
- ✅ Updates will sync to correct partition tables
- ✅ PostgreSQL will stay in sync with MySQL
- ✅ Reports will show current data
- ✅ System will work as designed

## Code Comparison

### Initial Sync (CORRECT - Uses Partitions)

```php
// DateGroupedSyncService.php
$partitionTable = $this->partitionManager->getPartitionTableName($date);
$this->partitionManager->ensurePartitionExists($date);
DB::connection('pgsql')->table($partitionTable)->upsert(...);
```

### Update Sync (WRONG - Ignores Partitions)

```php
// AlertSyncService.php
SyncedAlert::updateOrCreate(['id' => $alertId], $data);
// ❌ This targets 'alerts' table which doesn't exist!
```

## Next Steps

1. **Create a spec** for refactoring AlertSyncService to use partitions
2. **Update AlertSyncService** to match DateGroupedSyncService partition logic
3. **Test** the update worker with partitioned tables
4. **Deploy** and monitor

## Related Files

- `app/Services/AlertSyncService.php` - Needs refactoring
- `app/Services/DateGroupedSyncService.php` - Reference implementation
- `app/Services/PartitionManager.php` - Partition management
- `app/Services/DateExtractor.php` - Date extraction
- `app/Console/Commands/UpdateSyncWorker.php` - Worker command
- `app/Models/SyncedAlert.php` - Should NOT be used (targets wrong table)

## Summary

The Update Sync Worker is fully implemented but has a **critical bug**: it's trying to write to a non-existent single `alerts` table instead of the date-partitioned tables. The fix requires refactoring `AlertSyncService` to use the same partition logic as `DateGroupedSyncService`.
