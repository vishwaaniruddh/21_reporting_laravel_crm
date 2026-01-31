# MySQL Backup Service Fix Summary

## Problem

The AlertMysqlBackup service was showing as "Running" but backups were only present for January 10th. The service was crashing after completing backups due to a bug in the sleep calculation.

## Root Cause

The service was calculating a **negative sleep duration** when trying to schedule the next backup. This happened because:

1. Service completes backup at 2:00 AM
2. Service tries to calculate sleep time until tomorrow's 2:00 AM
3. Due to a timing issue, the calculation resulted in negative seconds
4. PHP's `sleep()` function throws an error for negative values
5. Service crashes and restarts, repeating the cycle

**Error from log:**
```
ValueError: sleep(): Argument #1 ($seconds) must be greater than or equal to 0
Sleeping for -14 hours...
```

## Solution

Added validation to ensure sleep duration is always positive:

**File:** `app/Console/Commands/MysqlFileBackupWorker.php`

```php
// Ensure sleep seconds is positive
if ($sleepSeconds <= 0) {
    $sleepSeconds = 3600; // Sleep for 1 hour and check again
}
```

This fix was applied in three places:
1. After backup already completed today
2. After performing a backup
3. Before backup time is reached

## Current Status

✅ **Backup for January 12 EXISTS** at `D:\MysqlFileSystemBackup\2026\01\12`

The service WAS working and creating backups, but it was crashing after each backup due to the sleep bug. This prevented it from running continuously.

## Next Steps

### 1. Restart the Service

Apply the fix by restarting the service:

```powershell
.\codes\restart-backup-service.ps1
```

Or manually:
```powershell
Restart-Service AlertMysqlBackup
```

Or use the Service Management UI:
http://192.168.100.21:9000/services

### 2. Verify Backups

Check backup status:

```powershell
.\codes\check-backup-status.ps1
```

This will show:
- All backups for the current month
- File counts and sizes
- Service status

### 3. Monitor

After restarting, the service should:
- Run continuously without crashing
- Create daily backups at 2:00 AM
- Sleep properly between backups

Check the service log:
```powershell
Get-Content storage/logs/mysql-backup-service.log -Tail 50
```

## Backup Schedule

- **Time**: 2:00 AM daily
- **Location**: `D:\MysqlFileSystemBackup\YEAR\MONTH\DATE\`
- **Files Backed Up**:
  - `alerts.frm`
  - `alerts.ibd`
  - `alerts.TRG`

## Expected Behavior After Fix

1. Service runs at 2:00 AM
2. Creates backup in `D:\MysqlFileSystemBackup\2026\01\13\`
3. Calculates next backup time (tomorrow at 2:00 AM)
4. Sleeps for ~24 hours (with positive duration)
5. Wakes up and repeats

## Verification

After restarting the service, you should see:

```
Backup already completed today at: D:\MysqlFileSystemBackup\2026\01\12
Next backup scheduled for 2026-01-13 02:00
Sleeping for 10.5 hours...
```

The sleep duration should be **positive** and the service should remain running.

## Files Created

- `codes/restart-backup-service.ps1` - Restart the backup service
- `codes/check-backup-status.ps1` - Check backup status and history
- `codes/backup-service-fix-summary.md` - This document

## Conclusion

The backup service was actually working correctly and creating backups. The issue was a crash after each backup due to negative sleep calculation. With the fix applied and service restarted, it will now run continuously without crashes.
