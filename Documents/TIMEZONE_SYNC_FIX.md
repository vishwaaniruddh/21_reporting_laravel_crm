# Timezone Sync Issue - Root Cause & Fix

## Problem Identified

Alert data is being synced from MySQL to PostgreSQL with an unwanted timezone conversion:

- **MySQL (Source)**: `2026-03-04 19:28:17` (IST/Asia/Kolkata)
- **PostgreSQL (Target)**: `2026-03-04 13:58:17` (Converted to UTC)
- **Time Difference**: 5.5 hours (IST = UTC+5:30)

## Root Cause

1. **MySQL Timezone**: Set to `SYSTEM` (which is IST/Asia/Kolkata on your server)
2. **PostgreSQL Timezone**: Set to `Asia/Calcutta` (same as IST)
3. **Laravel/PHP**: Configured to use `Asia/Kolkata`
4. **Laravel Database Layer**: Was converting datetime values from IST to UTC automatically

The issue occurred because:
- Laravel's database layer was converting datetime values from MySQL (treated as IST) to UTC
- PostgreSQL then stored these UTC values as-is
- When querying, PostgreSQL returned the UTC values without converting back

## Solution Implemented

### Step 1: Configure Database Connections (COMPLETED)

Updated `config/database.php` to explicitly set timezone for both connections:

**MySQL Connection:**
```php
'timezone' => '+05:30', // Force IST timezone to prevent conversion
```

**PostgreSQL Connection:**
```php
'timezone' => 'Asia/Kolkata', // Match MySQL timezone to prevent conversion
```

This ensures Laravel treats timestamps in both databases as IST and doesn't perform automatic conversion.

### Step 2: Fix Existing Data (REQUIRED)

Existing records in PostgreSQL have incorrect timestamps (5.5 hours behind). Run the fix script:

```bash
php codes/fix-timezone-data.php
```

This script:
- Identifies all alert partition tables
- Adds 5.5 hours to all timestamp columns (createtime, receivedtime, closedtime, inserttime)
- Updates records to match the original MySQL timestamps
- Provides verification samples

### Step 3: Verify the Fix

After running the fix script, verify with:

```bash
php test_timezone_issue.php
```

Expected result: Times in MySQL and PostgreSQL should match exactly.

## Impact

- **Future Syncs**: Will now preserve timestamps exactly as they appear in MySQL
- **Existing Data**: Requires one-time correction using the fix script
- **No Data Loss**: Only timestamp values are adjusted, all other data remains unchanged

## Testing

The fix has been tested and verified:
- ✅ New records sync with correct timestamps
- ✅ Configuration prevents automatic timezone conversion
- ✅ Both databases now use consistent timezone handling

## Files Modified

1. `config/database.php` - Added timezone configuration
2. `codes/fix-timezone-data.php` - Script to correct existing data
3. `Documents/TIMEZONE_SYNC_FIX.md` - This documentation
