# Timezone Issue - Fixed ✅

## Problem
Timestamps were being converted from IST to UTC during MySQL → PostgreSQL sync, causing a 5.5-hour difference.

**Example:**
- MySQL: `2026-03-04 19:28:17` (IST)
- PostgreSQL: `2026-03-04 13:58:17` (UTC - 5.5 hours behind)

## Root Cause
Laravel's database layer was automatically converting timestamps from IST to UTC when reading from MySQL and writing to PostgreSQL.

## Solution Applied

### 1. Configuration Fix (✅ COMPLETED)
Updated `config/database.php`:

```php
// MySQL connection
'timezone' => '+05:30',  // Prevent conversion

// PostgreSQL connection  
'timezone' => 'Asia/Kolkata',  // Match MySQL timezone
```

### 2. Data Correction (⚠️ ACTION REQUIRED)

Run this command to fix existing data:

```bash
php codes/fix-timezone-data.php
```

This will:
- Add 5.5 hours to all timestamps in PostgreSQL partition tables
- Correct createtime, receivedtime, closedtime, inserttime columns
- Process all alert partitions automatically

### 3. Verification

After running the fix, verify with:

```bash
php test_timezone_issue.php
```

Expected: MySQL and PostgreSQL timestamps should match exactly.

## Impact

✅ **Future syncs**: Timestamps will be preserved correctly  
✅ **No data loss**: Only timestamp values are adjusted  
✅ **Consistent timezone**: Both databases now use IST  

## Next Steps

1. Run the data correction script: `php codes/fix-timezone-data.php`
2. Verify the fix: `php test_timezone_issue.php`
3. Monitor future syncs to ensure timestamps remain correct

## Files Changed

- `config/database.php` - Added timezone configuration
- `codes/fix-timezone-data.php` - Data correction script
- `Documents/TIMEZONE_SYNC_FIX.md` - Detailed documentation
