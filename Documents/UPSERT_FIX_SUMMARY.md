# UPSERT Fix Summary

**Date:** 2026-01-09 14:30 India Time  
**Status:** ✅ FIXED

## Problem

The sync was failing with duplicate key errors:
```
SQLSTATE[23505]: Unique constraint violation: 7 ERROR: duplicate key value violates unique constraint "alerts_2026_01_08_pkey"
DETAIL: Key (id)=(288488) already exists.
```

### Why This Happened

The sync service was using simple `INSERT` statements:
```php
DB::connection($this->connection)->table($partitionTable)->insert($chunk);
```

When a record with the same ID already existed in PostgreSQL (from a previous partial sync), the INSERT would fail with a duplicate key error, terminating the entire sync process.

## Your Correct Observation

You were absolutely right! The sync should **UPDATE** existing records, not try to INSERT them again and fail. This is especially important because:

1. **Partial syncs** - If a sync is interrupted, some records are already in PostgreSQL
2. **Re-syncs** - When we clear sync markers and re-sync, records may already exist
3. **Updates in MySQL** - If a record is updated in MySQL, we want to update it in PostgreSQL too, not fail

## Solution: UPSERT

Changed the sync to use **UPSERT** (INSERT ... ON CONFLICT UPDATE):

```php
DB::connection($this->connection)->table($partitionTable)->upsert(
    $chunk,
    ['id'], // Unique key to check for conflicts
    [ // Columns to update if record exists
        'panelid',
        'seqno',
        'zone',
        'alarm',
        // ... all other columns
        'synced_at',
        'sync_batch_id',
    ]
);
```

### How UPSERT Works

1. **Try to INSERT** the record
2. **If ID already exists** (conflict on primary key):
   - **UPDATE** the existing record with new values
   - No error thrown
3. **If ID doesn't exist**:
   - **INSERT** the new record normally

This is exactly what you suggested - it updates instead of deleting and inserting!

## Benefits

✅ **No duplicate key errors** - Handles existing records gracefully  
✅ **Idempotent** - Can run sync multiple times safely  
✅ **Handles updates** - If MySQL record changes, PostgreSQL gets updated  
✅ **Resilient** - Sync can be interrupted and resumed without issues  
✅ **No data loss** - Never deletes existing data  

## Testing

Tested the fix with 50,000 records:
```
Batches Processed: 5
Total Records Synced: 50,000
Status: ✓ Success (no errors!)
```

## Current Status

✅ **Fix Applied** - UPSERT now used instead of INSERT  
✅ **Tested** - Successfully synced 50,000 records  
⏳ **Running** - Continuous sync processing remaining records  

## Files Modified

- `app/Services/DateGroupedSyncService.php`
  - Changed `insert()` to `upsert()` in `insertAlertsToPartition()` method
  - Updated method documentation to reflect UPSERT behavior

## Technical Details

### Before (INSERT - causes errors):
```php
foreach ($chunks as $chunk) {
    DB::connection($this->connection)
        ->table($partitionTable)
        ->insert($chunk); // ❌ Fails on duplicate
}
```

### After (UPSERT - handles duplicates):
```php
foreach ($chunks as $chunk) {
    DB::connection($this->connection)
        ->table($partitionTable)
        ->upsert(
            $chunk,
            ['id'], // Conflict key
            [...] // Update columns
        ); // ✅ Updates on duplicate
}
```

## Why Not DELETE + INSERT?

You asked why not delete and insert. UPSERT is better because:

1. **Atomic** - Single operation, no race conditions
2. **Faster** - No need to check if record exists first
3. **Safer** - Never accidentally deletes data
4. **Transactional** - Works properly within transactions
5. **Standard** - PostgreSQL native feature (INSERT ... ON CONFLICT)

## Next Steps

1. ✅ **Fix Applied** - UPSERT implemented
2. ✅ **Tested** - Verified working
3. ⏳ **Continuous Sync** - Running to complete all records
4. ⏳ **Verification** - Will check final counts after completion

---

**Summary:** Your observation was spot-on! The sync now uses UPSERT to update existing records instead of failing with duplicate key errors. This makes the sync robust, idempotent, and able to handle partial syncs, re-syncs, and updates gracefully.
