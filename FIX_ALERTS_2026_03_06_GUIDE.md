# Fix alerts_2026_03_06 Table - Quick Guide

## Problem

The `alerts_2026_03_06` partition table has **420,652 alerts** where the `receivedtime` date doesn't match the partition date.

Example:
- Partition: `alerts_2026_03_06` (should contain alerts from 2026-03-06)
- But contains alerts with: `receivedtime = 2026-03-07 18:01:44`
- MySQL has correct value: `receivedtime = 2026-03-06 03:31:44`

This happened because of timezone conversion during the sync process.

## Solution

Three scripts are available to fix this:

### Option 1: Batched Fix (Recommended for Testing)
```bash
php fix_alerts_2026_03_06_batched.php
```
- Fixes 100 alerts per batch
- Stops after 100 batches (10,000 alerts)
- Good for testing and verification
- Run multiple times to continue

### Option 2: Continuous Fix (Recommended for Full Fix)
```bash
php fix_alerts_2026_03_06_continuous.php
```
- Fixes 500 alerts per batch
- Runs until all alerts are fixed
- Shows progress every 10 batches
- Estimated time: ~30-60 minutes for 420K alerts

### Option 3: Single Table Fix (Original)
```bash
php fix_alerts_2026_03_06_only.php
```
- Tries to fix all at once
- May run out of memory with large datasets

## Progress So Far

- **Initial count:** 420,652 alerts with wrong dates
- **Fixed so far:** 10,100 alerts
- **Remaining:** 410,552 alerts

## How It Works

For each alert with wrong date:
1. Fetch correct timestamps from MySQL
2. Update PostgreSQL using explicit `::timestamp` casting
3. This prevents timezone conversion
4. Verify the fix

## Verification

Check remaining wrong dates:
```sql
SELECT COUNT(*) 
FROM alerts_2026_03_06 
WHERE DATE(receivedtime) != '2026-03-06';
```

Check sample of fixed alerts:
```sql
SELECT id, receivedtime 
FROM alerts_2026_03_06 
ORDER BY id 
LIMIT 10;
```

## Important Notes

1. **The sync services have been fixed** - New alerts will sync correctly
2. **This only fixes existing data** - Historical data that was already synced incorrectly
3. **Safe to run multiple times** - Script only fixes alerts that still have wrong dates
4. **No data loss** - Only updates timestamps to match MySQL source

## After Fixing

1. Verify all dates are correct:
   ```sql
   SELECT COUNT(*) FROM alerts_2026_03_06 WHERE DATE(receivedtime) != '2026-03-06';
   ```
   Should return: 0

2. Restart sync services with the fixed code:
   ```powershell
   .\codes\restart-services-for-timestamp-fix.ps1
   ```

3. Monitor new syncs to ensure no new wrong dates appear

## Other Affected Tables

The dry run found wrong dates in 38 partition tables:
- alerts_2026_03_06: 420,652 alerts
- alerts_2026_03_05: 100+ alerts
- alerts_2026_03_04: 46 alerts
- ... and 35 more tables

After fixing alerts_2026_03_06, you can use the same scripts for other tables by modifying the table name.

## Performance

Based on the test run:
- **Speed:** ~100-200 alerts per second
- **Time for 420K alerts:** 35-70 minutes
- **Database load:** Minimal (batched updates with delays)
- **Memory usage:** Low (processes in small batches)

## Troubleshooting

**Script stops at batch 100:**
- This is the safety limit in the batched version
- Run it again to continue, or use the continuous version

**"Not found in MySQL" errors:**
- Some alerts may have been deleted from MySQL
- This is normal, script will skip them

**Slow performance:**
- Increase batch size (edit `$batchSize` variable)
- Check database server load
- Ensure good network connection between servers

## Related Files

- `fix_alerts_2026_03_06_batched.php` - Batched fix with safety limit
- `fix_alerts_2026_03_06_continuous.php` - Continuous fix until complete
- `fix_alerts_2026_03_06_only.php` - Single-run fix (may have memory issues)
- `fix_wrong_partition_dates.php` - Fix all partition tables
- `TIMESTAMP_CONVERSION_FIX.md` - Root cause analysis
