# Timestamp Validation Added to Sync Services

## Overview

Added automatic timestamp validation and preservation to all sync services to prevent timezone conversion issues and ensure timestamp immutability during MySQL → PostgreSQL synchronization.

## What Was Added

### 1. New Service: `TimestampValidator`

Location: `app/Services/TimestampValidator.php`

**Features:**
- Validates timestamps before insert/update to PostgreSQL
- Compares source (MySQL) and target (PostgreSQL) timestamps
- Detects timezone conversion (e.g., 5.5-hour IST→UTC conversion)
- Logs validation failures with detailed information
- Allows 1-second tolerance for rounding differences

**Validated Columns:**
- `createtime`
- `receivedtime`
- `closedtime`
- `inserttime`

### 2. Timestamp Preservation Logic

**CRITICAL BEHAVIOR:**
- **First Insert**: Timestamps are copied from MySQL and validated
- **Subsequent Updates**: Original timestamps are PRESERVED (never changed)
- **Immutability**: Once set, createtime/receivedtime/closedtime never change

This ensures data integrity - timestamps represent when the alert was originally created/received, not when it was last synced.

### 3. Updated Services

#### AlertSyncService
- Added `TimestampValidator` dependency
- Checks if record exists before upsert
- **New records**: Validates timestamps from MySQL
- **Existing records**: Preserves original timestamps from PostgreSQL
- Throws exception if validation fails (prevents bad data)
- Logs preservation actions

#### DateGroupedSyncService
- Added `TimestampValidator` dependency
- Bulk checks existing records before batch insert
- **New records**: Validates each alert's timestamps
- **Existing records**: Preserves original timestamps
- Throws exception if any alert fails validation
- Entire batch rolls back on validation failure

## How It Works

### For New Records (First Insert)

1. **Fetch from MySQL:**
   - Get alert data including timestamps

2. **Prepare for PostgreSQL:**
   - Copy all fields including timestamps

3. **Validate Timestamps:**
   - Compare MySQL vs prepared PostgreSQL data
   - Check for timezone conversion (>1 second difference)
   - Fail if mismatch detected

4. **Insert:**
   - Save to PostgreSQL with validated timestamps

### For Existing Records (Updates)

1. **Check Existence:**
   - Query PostgreSQL for existing record by ID

2. **Preserve Original Timestamps:**
   - Use existing createtime, receivedtime, closedtime
   - Do NOT use values from MySQL

3. **Update Other Fields:**
   - Update status, comment, closedBy, etc.
   - Timestamps remain unchanged

4. **Upsert:**
   - Save with preserved timestamps
   - Update list excludes timestamp columns

### Validation Process

1. **Before Insert/Update:**
   - Service prepares data for PostgreSQL
   - Calls `TimestampValidator::validateBeforeSync()`
   - Compares MySQL timestamps with prepared PostgreSQL data

2. **Validation Check:**
   - Parses both timestamps using Carbon
   - Calculates time difference in seconds
   - Allows ≤1 second difference (for rounding)
   - Fails if difference > 1 second

3. **On Validation Failure:**
   - Logs warning with details (alert ID, column, values, difference)
   - Throws exception to prevent insert
   - Transaction rolls back (no bad data saved)

4. **On Validation Success:**
   - Logs debug message with timestamp values
   - Proceeds with insert/update

### Example Validation Failure Log

```
[WARNING] Timestamp validation failed
{
    "alert_id": 40614003,
    "column": "receivedtime",
    "mysql_value": "2026-03-04 19:28:12",
    "pgsql_value": "2026-03-04 13:58:12",
    "difference": 5.5
}
```

### Example Preservation Log

```
[INFO] Preserving original timestamps for existing record
{
    "alert_id": 40614003,
    "partition_table": "alerts_2026_03_04",
    "original_createtime": "2026-03-04 10:00:00",
    "original_receivedtime": "2026-03-04 10:00:05",
    "original_closedtime": null
}
```

## Impact on NSSM Services

### Services Affected

1. **AlertInitialSyncNew** (Initial sync service)
   - Uses `DateGroupedSyncService`
   - Now validates all timestamps during batch insert
   - Batch fails if any alert has timestamp mismatch

2. **AlertUpdateSync** (Update sync service)
   - Uses `AlertSyncService`
   - Now validates timestamps for each update
   - Update fails if timestamp mismatch detected

3. **AlertBackupSync** (Backup sync service)
   - Uses similar sync logic
   - Same validation applies

### Behavior Changes

**Before:**
- Timestamps synced without validation
- Timezone conversion could occur silently
- Bad data would be saved to PostgreSQL

**After:**
- Timestamps validated before every insert/update
- Timezone conversion detected immediately
- Bad data prevented from being saved
- Detailed logs for troubleshooting

## Configuration

### Timezone Settings (Already Applied)

In `config/database.php`:

```php
'mysql' => [
    'timezone' => '+05:30',  // IST
    // ...
],

'pgsql' => [
    'timezone' => 'Asia/Kolkata',  // IST
    // ...
],
```

These settings prevent automatic timezone conversion by Laravel.

### Validation Tolerance

Default: 1 second (defined in `TimestampValidator::MAX_TIME_DIFF_SECONDS`)

This allows for minor rounding differences but catches real timezone conversions.

## Testing

### Manual Test

Run the test script to verify validation works:

```bash
php test_timezone_fix.php
```

Expected: ✅ SUCCESS! Times match exactly

### Monitor Logs

Check for validation failures in logs:

```bash
Get-Content storage\logs\laravel.log -Tail 100 | Select-String "Timestamp validation"
```

## Benefits

1. **Early Detection**: Catches timezone issues immediately during sync
2. **Data Integrity**: Prevents incorrect timestamps from being saved
3. **Debugging**: Detailed logs help identify configuration issues
4. **Automatic**: No manual intervention required
5. **Safe**: Rolls back transactions on validation failure

## Rollback (If Needed)

If validation causes issues, you can temporarily disable it by:

1. Comment out validation calls in:
   - `app/Services/AlertSyncService.php` (line ~380)
   - `app/Services/DateGroupedSyncService.php` (line ~520)

2. Restart NSSM services

**Note:** Only disable if absolutely necessary. Validation prevents data corruption.

## Next Steps

1. ✅ Configuration updated (timezone settings)
2. ✅ Validation added to sync services
3. ⚠️ **Run data fix script**: `php codes/fix-timezone-data.php`
4. ⚠️ **Restart NSSM services** to apply changes
5. ✅ Monitor logs for validation failures

## Files Modified

- `app/Services/TimestampValidator.php` (NEW)
- `app/Services/AlertSyncService.php` (UPDATED)
- `app/Services/DateGroupedSyncService.php` (UPDATED)
- `config/database.php` (UPDATED - timezone settings)
