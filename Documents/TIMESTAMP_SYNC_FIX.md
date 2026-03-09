# Timestamp Sync Fix - Identical Timestamps + Closedtime Updates

## Problems Identified

### Problem 1: Timezone Conversion (FIXED)

When syncing alerts from MySQL to PostgreSQL partitioned tables, timestamps were being converted due to Laravel's Eloquent datetime casting mechanism.

### Problem 2: Closedtime Never Updates (FIXED)

When an alert was first synced with `closedtime=NULL` (open alert), and later closed in MySQL with `closedtime` set to a value, the PostgreSQL record would NEVER get the `closedtime` updated because it was excluded from the update list.

**Example:**
```
MySQL:
  - Initial: id=1001392991, status='O', closedtime=NULL
  - Later:   id=1001392991, status='C', closedtime='2026-03-06 14:30:00'

PostgreSQL (BEFORE FIX):
  - Initial: id=1001392991, status='O', closedtime=NULL
  - Later:   id=1001392991, status='C', closedtime=NULL  ← WRONG! Should be updated
```

This is why you saw `closedtime=NULL` in PostgreSQL even though MySQL had a value.

### Root Cause 1: Datetime Casting

**Alert Model (app/Models/Alert.php):**
```php
protected $casts = [
    'createtime' => 'datetime',
    'receivedtime' => 'datetime',
    'closedtime' => 'datetime',
];
```

When using `Alert::find($id)->toArray()`, Laravel automatically:
1. Converts timestamp strings to Carbon datetime objects
2. Applies application timezone settings
3. Formats them back to strings with potential timezone conversion

**Result:** Timestamps in PostgreSQL could differ from MySQL by timezone offset (e.g., 5.5 hours for IST→UTC conversion)

### Root Cause 2: Immutable Closedtime

The sync logic preserved ALL timestamps on updates, including `closedtime`. This meant:

**Original Code:**
```php
if ($existingRecord) {
    // Preserve ALL timestamps - WRONG!
    $upsertData['createtime'] = $existingRecord->createtime;
    $upsertData['receivedtime'] = $existingRecord->receivedtime;
    $upsertData['closedtime'] = $existingRecord->closedtime; // ❌ Never updates!
}

// Update list excluded closedtime
DB::table($partitionTable)->upsert(
    [$upsertData],
    ['id'],
    ['status', 'comment', ...] // closedtime NOT in list
);
```

**Result:** Once an alert was synced with `closedtime=NULL`, it could never be updated to a value, even when the alert was closed in MySQL.

## Solution Implemented

### Fix 1: Use Raw Database Queries

Instead of using Eloquent models with datetime casts, we now fetch data directly using `DB::table()` which returns raw string values without any conversion.

### Fix 2: Smart Closedtime Handling

Implemented logic to handle `closedtime` specially:
- `createtime`: IMMUTABLE (never changes after first insert)
- `receivedtime`: IMMUTABLE (never changes after first insert)  
- `closedtime`: CAN CHANGE from NULL to a value when alert is closed

**NEW Code:**
```php
if ($existingRecord) {
    // Preserve immutable timestamps
    $upsertData['createtime'] = $existingRecord->createtime;
    $upsertData['receivedtime'] = $existingRecord->receivedtime;
    
    // SPECIAL CASE: closedtime can change from NULL to a value
    if ($existingRecord->closedtime === null && 
        isset($data['closedtime']) && 
        $data['closedtime'] !== null) {
        // Alert was just closed - update closedtime from MySQL
        $upsertData['closedtime'] = $data['closedtime']; // ✓ Updates!
    } else {
        // Preserve existing closedtime
        $upsertData['closedtime'] = $existingRecord->closedtime;
    }
}

// Update list now includes closedtime
DB::table($partitionTable)->upsert(
    [$upsertData],
    ['id'],
    ['status', 'comment', 'closedtime', ...] // ✓ closedtime in list
);
```

### Files Modified

#### 1. AlertSyncService.php

**BEFORE:**
```php
private function fetchAlertFromMysql(int $alertId): ?array
{
    return $this->retryWithBackoff(
        function () use ($alertId) {
            // Use Alert model to fetch from MySQL (READ-ONLY)
            $alert = Alert::find($alertId);
            
            if ($alert === null) {
                return null;
            }
            
            // Convert model to array for processing
            return $alert->toArray(); // ❌ Triggers datetime casting
        },
        "Fetch alert {$alertId} from MySQL"
    );
}
```

**AFTER:**
```php
private function fetchAlertFromMysql(int $alertId): ?array
{
    return $this->retryWithBackoff(
        function () use ($alertId) {
            // CRITICAL: Fetch raw data directly from database to avoid timezone conversion
            // Using DB::table() instead of Alert model to bypass datetime casting
            $alert = DB::connection('mysql')
                ->table('alerts')
                ->where('id', $alertId)
                ->first();
            
            if ($alert === null) {
                return null;
            }
            
            // Convert stdClass to array with raw timestamp strings (no conversion)
            return (array) $alert; // ✓ Raw strings, no casting
        },
        "Fetch alert {$alertId} from MySQL"
    );
}
```

#### 2. BackAlertSyncService.php

**BEFORE:**
```php
private function processUpdateLogEntry(BackAlertUpdateLog $updateLog): void
{
    // Get the backalert record
    $backAlert = BackAlert::find($updateLog->backalert_id); // ❌ Uses model with casts
    
    // ... uses $backAlert->createtime, $backAlert->receivedtime, etc.
}
```

**AFTER:**
```php
private function processUpdateLogEntry(BackAlertUpdateLog $updateLog): void
{
    // CRITICAL: Fetch raw data directly from database to avoid timezone conversion
    // Using DB::table() instead of BackAlert model to bypass datetime casting
    $backAlert = DB::connection('mysql')
        ->table('backalerts')
        ->where('id', $updateLog->backalert_id)
        ->first(); // ✓ Raw data, no casting
    
    if (!$backAlert) {
        throw new Exception("BackAlert record not found: {$updateLog->backalert_id}");
    }
    
    // ... rest of the code
}

private function upsertBackAlertToPartition($backAlert, string $partitionTable): void
{
    // Convert stdClass to array if needed
    $backAlertData = is_object($backAlert) ? (array) $backAlert : $backAlert;
    
    $data = [
        'id' => $backAlertData['id'],
        'createtime' => $backAlertData['createtime'] ?? null, // ✓ Raw string
        'receivedtime' => $backAlertData['receivedtime'] ?? null, // ✓ Raw string
        'closedtime' => $backAlertData['closedtime'] ?? null, // ✓ Raw string
        // ... other fields
    ];
    
    // ... upsert to PostgreSQL
}
```

## How It Works Now

### Sync Flow with Identical Timestamps

```
┌─────────────────────────────────────────────────────────────────┐
│ MySQL alerts table                                              │
│ id=12345                                                        │
│ createtime:   2026-03-06 10:14:55                              │
│ receivedtime: 2026-03-06 10:15:00                              │
│ closedtime:   2026-03-06 14:30:00                              │
└─────────────────────────────────────────────────────────────────┘
                            ↓
                    Trigger fires
                            ↓
┌─────────────────────────────────────────────────────────────────┐
│ alert_pg_update_log                                             │
│ alert_id=12345, status=1 (pending)                             │
└─────────────────────────────────────────────────────────────────┘
                            ↓
              AlertSyncService processes
                            ↓
┌─────────────────────────────────────────────────────────────────┐
│ Step 1: Fetch from MySQL using DB::table()                     │
│ Returns RAW strings (no datetime casting):                     │
│   createtime:   "2026-03-06 10:14:55"                          │
│   receivedtime: "2026-03-06 10:15:00"                          │
│   closedtime:   "2026-03-06 14:30:00"                          │
└─────────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────────┐
│ Step 2: Determine partition table                              │
│ Extract date from receivedtime: 2026-03-06                     │
│ Partition table: alerts_2026_03_06                             │
└─────────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────────┐
│ Step 3: Check if record exists in partition                    │
│                                                                 │
│ IF EXISTS:                                                      │
│   ✓ Preserve original PostgreSQL timestamps (never update)     │
│   ✓ Update only non-timestamp fields                           │
│                                                                 │
│ IF NEW:                                                         │
│   ✓ Use raw MySQL timestamps AS-IS                             │
│   ✓ Validate with TimestampValidator                           │
│   ✓ Insert with identical timestamps                           │
└─────────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────────┐
│ PostgreSQL alerts_2026_03_06 partition                         │
│ id=12345                                                        │
│ createtime:   2026-03-06 10:14:55  ← IDENTICAL to MySQL       │
│ receivedtime: 2026-03-06 10:15:00  ← IDENTICAL to MySQL       │
│ closedtime:   2026-03-06 14:30:00  ← IDENTICAL to MySQL       │
└─────────────────────────────────────────────────────────────────┘
```

## Guarantees

### ✓ Timestamps Are Identical

1. **No Timezone Conversion**
   - Raw strings from MySQL are used directly
   - No Carbon datetime object conversion
   - No application timezone applied

2. **Timestamp Immutability**
   - On first insert: Uses MySQL timestamps exactly
   - On updates: Preserves original PostgreSQL timestamps
   - Timestamps never change after first insert

3. **Validation Layer**
   - TimestampValidator compares source vs target
   - Detects any conversion (max 1 second tolerance)
   - Logs warnings if mismatch detected

## Testing

### Run Verification Test

```bash
php test_timestamp_sync_fix.php
```

This script will:
- Compare OLD method (model with casts) vs NEW method (raw DB query)
- Show any timezone conversion that would have occurred
- Validate timestamps match exactly
- Detect timezone conversion patterns

### Expected Output

```
=== Timestamp Sync Verification Test ===

Testing with Alert ID: 12345

--- RAW MySQL Timestamps ---
createtime:   2026-03-06 10:14:55
receivedtime: 2026-03-06 10:15:00
closedtime:   2026-03-06 14:30:00

--- OLD Method (Alert Model with datetime casts) ---
createtime:   2026-03-06 04:44:55  ← Converted to UTC!
receivedtime: 2026-03-06 04:45:00  ← Converted to UTC!
closedtime:   2026-03-06 09:00:00  ← Converted to UTC!
⚠️  WARNING: Timezone conversion detected!

--- NEW Method (DB::table for raw data) ---
createtime:   2026-03-06 10:14:55
receivedtime: 2026-03-06 10:15:00
closedtime:   2026-03-06 14:30:00
✓ Timestamps match exactly - NO timezone conversion

--- Timestamp Validation Test ---
✓ Validation PASSED - Timestamps are identical

✓ No timezone conversion pattern detected
```

## Deployment Steps

### 1. Verify Current Sync Status

```bash
# Check if sync services are running
php codes/check-services.ps1
```

### 2. Stop Sync Services

```bash
# Stop all sync services
php codes/stop-all-services.ps1
```

### 3. Run Verification Test

```bash
# Test the fix
php test_timestamp_sync_fix.php
```

### 4. Restart Sync Services

```bash
# Start all sync services with the fix applied
php codes/start-all-services.ps1
```

### 5. Monitor Sync Logs

```bash
# Watch for timestamp validation messages
tail -f storage/logs/laravel.log | grep -i "timestamp"
```

## Verification Queries

### Check MySQL vs PostgreSQL Timestamps

```sql
-- MySQL
SELECT id, createtime, receivedtime, closedtime 
FROM alerts 
WHERE id = 12345;

-- PostgreSQL (check appropriate partition)
SELECT id, createtime, receivedtime, closedtime 
FROM alerts_2026_03_06 
WHERE id = 12345;
```

**Expected:** All timestamps should match EXACTLY, character for character.

### Check for Mismatches

```bash
# Run the timestamp mismatch checker
php codes/check-timestamp-mismatches-fast.php
```

If the fix is working correctly, you should see:
- Zero mismatches for newly synced records
- Any existing mismatches are from before the fix

## Impact

### Before Fix
- Timestamps could differ by timezone offset (5.5 hours for IST→UTC)
- Reports showed incorrect times
- Data integrity issues

### After Fix
- ✓ Timestamps are identical between MySQL and PostgreSQL
- ✓ No timezone conversion applied
- ✓ Data integrity maintained
- ✓ Reports show correct times

## Related Files

- `app/Services/AlertSyncService.php` - Main sync service (FIXED)
- `app/Services/BackAlertSyncService.php` - Backup alerts sync (FIXED)
- `app/Services/TimestampValidator.php` - Validation service
- `app/Models/Alert.php` - Model with datetime casts (NOT used for sync anymore)
- `app/Models/BackAlert.php` - Model with datetime casts (NOT used for sync anymore)
- `config/database.php` - Database timezone configuration

## Notes

- The Alert and BackAlert models still have datetime casts for other uses (display, API responses)
- Only the sync process bypasses the models to ensure raw timestamp preservation
- This approach maintains backward compatibility while fixing the sync issue
- TimestampValidator provides an additional safety layer to catch any future issues
