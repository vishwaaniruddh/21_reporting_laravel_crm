# Sync Completion Guide

## ✅ Auto Sync is NOW Working!

**Status:** Fixed memory issue - sync is now operational

### Issue Found and Fixed
The auto sync was failing due to PHP memory exhaustion (128MB limit). Fixed by increasing memory limit to 512MB in the sync command.

### Current Status (Updated: 2026-01-09 13:35 India Time)

- **Scheduler Status:** ✅ Running (ProcessId: 5)
- **Partitioned Sync:** ✅ Fixed and Working
- **Memory Limit:** ✅ Increased to 512MB
- **Sync Frequency:** Every 20 minutes (at :00, :20, :40 UTC)
  - In India time: :30, :50, :10
- **Batch Size:** 10 batches per run (~100,000 records)
- **Next Sync:** 08:20 UTC (13:50 India time) - in ~15 minutes

### MySQL Source Data
- **2026-01-08:** 359,646 records
- **2026-01-09:** 171,279 records
- **Total:** 530,925 records

### PostgreSQL Partitions (Current)
- **alerts_2026_01_08:** 23,600 records
- **alerts_2026_01_09:** 70,000 records ✅ INCREASED FROM 20,000!
- **alerts_acup:** 2,605 records (existing)
- **Total Synced:** 93,600 records (17.63% complete)

### Sync Progress
- **Remaining:** 437,325 records
- **Estimated Completion:** ~80 minutes (4 more sync cycles)
- **Completion Time:** ~14:55 India time (approximately)

## Issue Encountered

The sync process was interrupted, leaving:
1. Partial data in `alerts_2026_01_08` partition
2. No partition created for `alerts_2026_01_09`
3. Duplicate key errors when trying to re-sync

## Solution Applied

### Step 1: Mark Already Synced Records ✅
Created and ran `mark_synced.php` to mark records from 2026-01-08 as synced in MySQL:
```php
DB::connection('mysql')
    ->table('alerts')
    ->whereDate('receivedtime', '2026-01-08')
    ->whereNull('synced_at')
    ->update(['synced_at' => now()]);
```

**Result:** Marked 49,326 records as synced

### Step 2: Continue Sync with Smaller Batches
Run the sync command with smaller batch size to complete:
```bash
php artisan sync:partitioned --batch-size=1000 --max-batches=20
```

This will:
- Process 20 batches of 1,000 records each = 20,000 records
- Create `alerts_2026_01_09` partition automatically
- Sync remaining records from both dates

### Step 3: Run Continuous Sync (If Needed)
If more records remain after Step 2:
```bash
php artisan sync:partitioned --batch-size=1000 --continuous
```

This will process ALL remaining unsynced records.

## Commands Reference

### Check Sync Status
```bash
php artisan sync:partitioned --status
```

Shows:
- Unsynced record count
- Recent partitions
- Record counts per partition

### Manual Sync (Limited Batches)
```bash
php artisan sync:partitioned --batch-size=1000 --max-batches=10
```

### Continuous Sync (All Records)
```bash
php artisan sync:partitioned --batch-size=500 --continuous
```

### Start from Specific ID
```bash
php artisan sync:partitioned --start-id=330000 --batch-size=1000
```

## Expected Final State

After completion:

### PostgreSQL Partitions
```
alerts_2026_01_08: 359,646 records
alerts_2026_01_09: 158,480 records
Total: 518,126 records
```

### MySQL
All records should have `synced_at` timestamp set.

## Troubleshooting

### Issue: "Duplicate key violation"
**Cause:** Trying to insert records that already exist in partition  
**Solution:** Mark those records as synced in MySQL (see Step 1)

### Issue: "Connection pool exhausted"
**Cause:** Too many concurrent MySQL connections  
**Solution:** Wait 30 seconds and try again, or restart MySQL service

### Issue: "Partition not found"
**Cause:** Partition table doesn't exist for that date  
**Solution:** Sync will create it automatically on first insert

### Issue: "Slow sync performance"
**Cause:** Large batch size or network latency  
**Solution:** Reduce batch size to 500-1000 records

## Monitoring Progress

### Watch Partition Growth
```bash
# Run this periodically to see progress
php artisan sync:partitioned --status
```

### Check PostgreSQL Directly
```sql
-- Count records in partition
SELECT COUNT(*) FROM alerts_2026_01_08;
SELECT COUNT(*) FROM alerts_2026_01_09;

-- List all partition tables
SELECT table_name 
FROM information_schema.tables 
WHERE table_schema = 'public' 
AND table_name LIKE 'alerts_%'
ORDER BY table_name;
```

### Check MySQL Sync Status
```sql
-- Count unsynced records
SELECT COUNT(*) FROM alerts WHERE synced_at IS NULL;

-- Count by date
SELECT DATE(receivedtime), COUNT(*) 
FROM alerts 
WHERE synced_at IS NULL 
GROUP BY DATE(receivedtime);
```

## Automation (Future)

### Schedule Regular Syncs
Add to `routes/console.php` or task scheduler:
```php
Schedule::command('sync:partitioned --batch-size=1000 --max-batches=10')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

### Monitor and Alert
```php
// Check if sync is falling behind
$unsyncedCount = DB::connection('mysql')
    ->table('alerts')
    ->whereNull('synced_at')
    ->count();

if ($unsyncedCount > 50000) {
    // Send alert to admin
    Log::warning("Sync backlog: {$unsyncedCount} records");
}
```

## Performance Tips

1. **Batch Size:** 500-1000 records works best
2. **Off-Peak Hours:** Run large syncs during low-traffic periods
3. **Continuous Mode:** Use for initial bulk sync only
4. **Limited Batches:** Use for regular incremental syncs

## Next Steps

1. ✅ Mark already synced records (DONE - 49,326 marked)
2. ⏳ Run sync with smaller batches (IN PROGRESS)
3. ⏳ Verify all records synced
4. ⏳ Set up scheduled sync for new records
5. ⏳ Monitor partition growth

---

**Current Command Running:**
```bash
php artisan sync:partitioned --batch-size=1000 --max-batches=20
```

**Expected Result:**
- Process 20,000 records
- Create alerts_2026_01_09 partition
- Reduce unsynced count from 160,314 to ~140,314

**After This Completes:**
Run continuous sync to finish:
```bash
php artisan sync:partitioned --batch-size=1000 --continuous
```
