# Timestamp Fix - Quick Start Guide

## What Was Fixed

The sync services were converting timestamps due to Laravel's Eloquent datetime casting. This caused PostgreSQL timestamps to differ from MySQL by the timezone offset.

**Fixed:** Both `AlertSyncService.php` and `BackAlertSyncService.php` now fetch raw data directly from the database, ensuring timestamps remain identical.

## Do You Need to Restart Services?

**YES!** You must restart the NSSM services for the code changes to take effect.

## Quick Restart (Recommended)

Run this script to restart all sync services:

```powershell
.\codes\restart-services-for-timestamp-fix.ps1
```

This will:
- Stop all Alert*, BackAlert*, and Sites* services
- Wait for clean shutdown
- Start all services with the new code
- Verify all services are running
- Show next steps

## Manual Restart (Alternative)

If you prefer to restart manually:

### 1. Check Current Services

```powershell
Get-Service Alert*,BackAlert*,Sites* | Format-Table Name, Status
```

### 2. Stop All Services

```powershell
Get-Service Alert*,BackAlert*,Sites* | Stop-Service -Force
```

### 3. Wait 5 Seconds

```powershell
Start-Sleep -Seconds 5
```

### 4. Start All Services

```powershell
Get-Service Alert*,BackAlert*,Sites* | Start-Service
```

### 5. Verify Status

```powershell
Get-Service Alert*,BackAlert*,Sites* | Format-Table Name, Status
```

## Which Services Are Affected?

The fix applies to these services:

### Alert Services
- **AlertUpdateSync** - Syncs alert updates from alert_pg_update_log
- **AlertInitialSync** - Initial sync of alerts (if running)
- **AlertPortal** - Web portal (uses sync services)
- **AlertPortalQueueWorker** - Queue worker for exports
- **AlertPortalQueueWorkerV2** - V2 queue worker
- **AlertCleanup** - Cleanup service (reads synced data)
- **AlertMysqlBackup** - Backup service (reads data)

### BackAlert Services
- **BackAlertCleanup** - BackAlert cleanup service

### Sites Services
- **SitesUpdateSync** - Sites sync service (if running)

## Verify the Fix

### 1. Test Timestamp Sync

```bash
php test_timestamp_sync_fix.php
```

Expected output:
```
✓ Timestamps match exactly - NO timezone conversion
✓ Validation PASSED - Timestamps are identical
✓ No timezone conversion pattern detected
```

### 2. Monitor Sync Logs

```powershell
Get-Content storage\logs\laravel.log -Tail 50 -Wait | Select-String "timestamp"
```

Look for:
- `Timestamp validation passed` - Good!
- `Timestamp validation failed` - Problem (shouldn't happen with fix)

### 3. Check for Mismatches

```bash
php codes\check-timestamp-mismatches-fast.php
```

New syncs after the fix should show zero mismatches.

## What Happens After Restart?

### Immediate Effect
- All new syncs use the fixed code
- Timestamps are preserved exactly as they are in MySQL
- No timezone conversion occurs

### Existing Data
- Already synced records are NOT affected
- Only new syncs and updates use the fix
- Old mismatches remain (but won't get worse)

### Validation
- TimestampValidator checks every new sync
- Logs warnings if any conversion detected
- Provides safety net against future issues

## Troubleshooting

### Services Won't Start

Check service logs:
```powershell
Get-Content storage\logs\update-sync-service.log -Tail 50
Get-Content storage\logs\initial-sync-service.log -Tail 50
```

### Still Seeing Timestamp Mismatches

1. Verify services were restarted:
   ```powershell
   Get-Service Alert* | Select-Object Name, Status
   ```

2. Check if old code is cached:
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```

3. Restart services again:
   ```powershell
   .\codes\restart-services-for-timestamp-fix.ps1
   ```

### Service Status Check

```powershell
.\codes\check-all-nssm-services.ps1
```

This shows:
- All service statuses
- Service logs
- Pending/completed sync counts
- Sync status

## Summary

| Step | Command | Purpose |
|------|---------|---------|
| 1 | `.\codes\restart-services-for-timestamp-fix.ps1` | Restart all services |
| 2 | `php test_timestamp_sync_fix.php` | Verify fix is working |
| 3 | `.\codes\check-all-nssm-services.ps1` | Check service status |
| 4 | Monitor logs | Watch for timestamp validation |

## Important Notes

✓ **Services must be restarted** - Code changes don't apply until restart  
✓ **All sync services affected** - Both alerts and backalerts  
✓ **Immediate effect** - New syncs use fixed code right away  
✓ **Existing data unchanged** - Only new syncs are affected  
✓ **Validation active** - TimestampValidator provides safety net  

## Need Help?

Check these files for more details:
- `Documents/TIMESTAMP_SYNC_FIX.md` - Complete technical documentation
- `test_timestamp_sync_fix.php` - Test script with detailed output
- `codes/check-all-nssm-services.ps1` - Service status checker
