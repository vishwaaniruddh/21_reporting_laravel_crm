# Closedtime Update Fix

## The Problem You Discovered

You found that alert ID `1001392991` had `closedtime` in MySQL but `closedtime=NULL` in PostgreSQL partition table `alerts_2026_03_06`.

```sql
-- MySQL
SELECT * FROM alerts WHERE id=1001392991;
-- Result: closedtime has a value (e.g., '2026-03-06 14:30:00')

-- PostgreSQL
SELECT * FROM alerts_2026_03_06 WHERE id=1001392991;
-- Result: closedtime is NULL  ← WRONG!
```

## Why This Happened

### The Sync Flow

1. **Alert Created (Open)**
   - MySQL: `id=1001392991, status='O', closedtime=NULL`
   - Trigger logs to `alert_pg_update_log`
   - Sync service syncs to PostgreSQL
   - PostgreSQL: `id=1001392991, status='O', closedtime=NULL` ✓

2. **Alert Closed**
   - MySQL: `id=1001392991, status='C', closedtime='2026-03-06 14:30:00'`
   - Trigger logs to `alert_pg_update_log`
   - Sync service processes update

3. **The Bug (OLD Code)**
   ```php
   if ($existingRecord) {
       // Preserve ALL timestamps - including closedtime
       $upsertData['closedtime'] = $existingRecord->closedtime; // NULL
   }
   
   // Update list EXCLUDED closedtime
   DB::table($partitionTable)->upsert(
       [$upsertData],
       ['id'],
       ['status', 'comment', 'closedBy', ...] // closedtime NOT here
   );
   ```
   
   Result: PostgreSQL still has `closedtime=NULL` even though MySQL has a value!

### The Root Cause

The sync logic treated `closedtime` as IMMUTABLE (never changing), just like `createtime` and `receivedtime`. But this is wrong because:

- `createtime`: Set once when alert is created, never changes ✓
- `receivedtime`: Set once when alert is received, never changes ✓
- `closedtime`: Starts as NULL, gets set when alert is closed ✗

## The Fix

### New Logic: Smart Closedtime Handling

```php
if ($existingRecord) {
    // Preserve truly immutable timestamps
    $upsertData['createtime'] = $existingRecord->createtime;
    $upsertData['receivedtime'] = $existingRecord->receivedtime;
    
    // SPECIAL CASE: closedtime can change from NULL to a value
    if ($existingRecord->closedtime === null && 
        isset($data['closedtime']) && 
        $data['closedtime'] !== null) {
        // Alert was just closed - update closedtime from MySQL
        $upsertData['closedtime'] = $data['closedtime'];
        
        $this->logger->logInfo('Alert closed - updating closedtime', [
            'alert_id' => $alertId,
            'new_closedtime' => $data['closedtime'],
        ]);
    } else {
        // Preserve existing closedtime (either NULL or already set)
        $upsertData['closedtime'] = $existingRecord->closedtime;
    }
}

// Update list now INCLUDES closedtime
DB::table($partitionTable)->upsert(
    [$upsertData],
    ['id'],
    [
        'status',
        'comment',
        'closedBy',
        'closedtime', // ✓ Now in the update list
        // ... other fields
    ]
);
```

### Timestamp Rules (NEW)

| Timestamp | Behavior | Can Change? |
|-----------|----------|-------------|
| `createtime` | Set on first insert | ❌ IMMUTABLE |
| `receivedtime` | Set on first insert | ❌ IMMUTABLE |
| `closedtime` | NULL → value when closed | ✓ NULL to value only |

### Closedtime State Transitions

```
NULL → '2026-03-06 14:30:00'  ✓ Allowed (alert closed)
NULL → NULL                    ✓ Allowed (still open)
'2026-03-06 14:30:00' → NULL   ❌ Not allowed (once set, preserved)
'2026-03-06 14:30:00' → '...'  ❌ Not allowed (once set, preserved)
```

## Files Modified

1. **app/Services/AlertSyncService.php**
   - Added smart closedtime handling logic
   - Added `closedtime` to upsert update list
   - Logs when closedtime is updated

2. **app/Services/BackAlertSyncService.php**
   - Same fix for backalerts table
   - Added check for existing record
   - Smart closedtime handling

## Testing

### Test Script

```bash
php test_closedtime_update.php
```

This script:
- Finds a closed alert in MySQL
- Checks if it exists in PostgreSQL partition
- Compares closedtime values
- Reports any mismatches
- Shows statistics

### Expected Results After Fix

```
Testing with Alert ID: 1001392991

--- MySQL Data ---
createtime:   2026-03-06 10:14:55
receivedtime: 2026-03-06 10:15:00
closedtime:   2026-03-06 14:30:00
closedBy:     admin

--- PostgreSQL Data (Current) ---
createtime:   2026-03-06 10:14:55
receivedtime: 2026-03-06 10:15:00
closedtime:   2026-03-06 14:30:00  ← Now matches!
closedby:     admin

--- Comparison ---
✓ createtime matches
✓ receivedtime matches
✓ closedtime matches

=== Result: ✓ ALL TIMESTAMPS MATCH ===
```

## How to Apply the Fix

### 1. Restart Services

```powershell
.\codes\restart-services-for-timestamp-fix.ps1
```

This applies the new code to all running sync services.

### 2. Existing Mismatches

For alerts that were already synced with NULL closedtime:

**Option A: Wait for Natural Update**
- When the alert is updated in MySQL (any field change)
- The trigger will log to `alert_pg_update_log`
- Sync service will process and update closedtime

**Option B: Force Re-sync**
- Trigger an update in MySQL:
  ```sql
  UPDATE alerts 
  SET comment = CONCAT(comment, ' ') 
  WHERE closedtime IS NOT NULL 
    AND id IN (SELECT id FROM alerts WHERE closedtime IS NOT NULL LIMIT 1000);
  ```
- This creates log entries for re-sync
- Sync service will process and fix closedtime

**Option C: Bulk Fix Script**
- Create a script to find all mismatches
- Force re-sync for those specific alerts
- Monitor progress

### 3. Verify Fix

```bash
# Test specific alert
php test_closedtime_update.php

# Check for mismatches
php codes/check-timestamp-mismatches-fast.php
```

## Impact

### Before Fix
- Closed alerts in MySQL appeared as open in PostgreSQL
- Reports showed incorrect open alert counts
- `closedtime` was always NULL in PostgreSQL for alerts closed after initial sync
- Data integrity issue

### After Fix
- ✓ Closed alerts sync correctly
- ✓ `closedtime` updates from NULL to value when alert is closed
- ✓ Reports show accurate data
- ✓ Data integrity maintained

### Existing Data
- Already synced records with NULL closedtime will be fixed on next update
- New syncs work correctly immediately
- No data loss, just delayed sync for existing records

## Monitoring

### Check Sync Logs

```powershell
Get-Content storage\logs\laravel.log -Tail 50 -Wait | Select-String "closed"
```

Look for:
```
Alert closed - updating closedtime
BackAlert closed - updating closedtime
```

### Statistics Query

```sql
-- MySQL: Count closed alerts
SELECT COUNT(*) FROM alerts WHERE closedtime IS NOT NULL;

-- PostgreSQL: Count closed alerts across all partitions
SELECT 
    tablename,
    (SELECT COUNT(*) FROM tablename WHERE closedtime IS NOT NULL) as closed_count
FROM pg_tables 
WHERE tablename LIKE 'alerts_%';
```

## Summary

| Issue | Status | Solution |
|-------|--------|----------|
| Timezone conversion | ✓ FIXED | Use raw DB queries |
| closedtime never updates | ✓ FIXED | Smart closedtime handling |
| createtime changes | ✓ PREVENTED | Immutable on updates |
| receivedtime changes | ✓ PREVENTED | Immutable on updates |

The fix ensures:
1. Timestamps are identical (no timezone conversion)
2. `closedtime` can update from NULL to a value
3. `createtime` and `receivedtime` remain immutable
4. Data integrity is maintained
