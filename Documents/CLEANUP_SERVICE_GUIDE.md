# Alert Cleanup Service Guide

## Overview

The AlertCleanup service automatically deletes old records from MySQL to save disk space.

## ⚙️ Configuration (EDIT THESE)

### File: `app/Console/Commands/CleanupOldAlertsWorker.php`

**Line 35 - TABLE NAME:**
```php
protected string $tableName = 'alerts_2';
```
Change `'alerts_2'` to your target table:
- `'alerts'` - Main alerts table
- `'alerts_2'` - Backup alerts table
- `'alerts_backup'` - Any other table

**Line 48 - RETENTION HOURS:**
```php
protected int $retentionHours = 48;
```
Change `48` to number of hours to keep:
- `48` - Delete records older than 48 hours (2 days) [DEFAULT]
- `24` - Delete records older than 24 hours (1 day)
- `168` - Delete records older than 168 hours (7 days)
- `720` - Delete records older than 720 hours (30 days)

## 🚀 Create the Service

**Run as Administrator:**
```powershell
.\codes\create-cleanup-service.ps1
```

This will:
1. Create a Windows service named `AlertCleanup`
2. Configure it to run automatically on boot
3. Set it to check every hour for old records
4. Ask if you want to start it now

## 📊 What It Does

- **Checks:** Every 1 hour (configurable)
- **Deletes:** Records from `alerts_2` older than 48 hours (2 days)
- **Batch Size:** 1000 records at a time (prevents database locks)
- **Logs:** All deletions to `storage/logs/cleanup-service.log`

## 🔧 Manage the Service

### Check Status
```powershell
Get-Service AlertCleanup
```

### Start Service
```powershell
Start-Service AlertCleanup
```

### Stop Service
```powershell
Stop-Service AlertCleanup
```

### Restart Service (after config changes)
```powershell
Restart-Service AlertCleanup
```

### View Logs
```powershell
Get-Content storage\logs\cleanup-service.log -Tail 50 -Wait
```

## 📝 Change Configuration

### Change Table Name or Retention Hours

1. **Edit the file:**
   ```
   app\Console\Commands\CleanupOldAlertsWorker.php
   ```

2. **Change Line 35 (Table Name):**
   ```php
   protected string $tableName = 'your_table_name';
   ```

3. **Change Line 48 (Retention Hours):**
   ```php
   protected int $retentionHours = 168;  // Change to your desired hours
   ```

4. **Restart the service:**
   ```powershell
   Restart-Service AlertCleanup
   ```

### Change Check Interval

To change how often it checks (default: 1 hour):

1. **Stop the service:**
   ```powershell
   Stop-Service AlertCleanup
   ```

2. **Update NSSM configuration:**
   ```powershell
   # For 30 minutes (1800 seconds)
   nssm set AlertCleanup AppParameters "artisan" "cleanup:old-alerts-worker" "--check-interval=1800"
   
   # For 2 hours (7200 seconds)
   nssm set AlertCleanup AppParameters "artisan" "cleanup:old-alerts-worker" "--check-interval=7200"
   ```

3. **Start the service:**
   ```powershell
   Start-Service AlertCleanup
   ```

## 🧪 Test Before Running

**Dry run mode** (shows what would be deleted without actually deleting):

```powershell
php artisan cleanup:old-alerts-worker --dry-run
```

This will:
- Show how many records would be deleted
- Show the cutoff date
- NOT actually delete anything

## ⚠️ Safety Features

1. **Batch Deletion:** Deletes 1000 records at a time to prevent long locks
2. **Logging:** All deletions are logged with timestamps
3. **Graceful Shutdown:** Can be stopped safely with Ctrl+C or service stop
4. **Auto-Restart:** Service restarts automatically if it crashes

## 📋 All Services Summary

After setup, you'll have these services:

| Service | Purpose | Frequency |
|---------|---------|-----------|
| **AlertInitialSync** | Sync new alerts to PostgreSQL | Every minute |
| **AlertUpdateSync** | Sync alert updates to PostgreSQL | Every 5 seconds |
| **AlertCleanup** | Delete old records from MySQL | Every hour |
| **AlertPortal** | Web interface | Always running |

## 🔍 Troubleshooting

### Service won't start
Check error log:
```powershell
Get-Content storage\logs\cleanup-service-error.log -Tail 50
```

### No records being deleted
1. Check if records exist older than retention period
2. Check service is running: `Get-Service AlertCleanup`
3. Check logs: `Get-Content storage\logs\cleanup-service.log -Tail 50`

### Want to delete from different table
1. Edit `app\Console\Commands\CleanupOldAlertsWorker.php` line 35
2. Change `$tableName = 'alerts_2'` to your table
3. Restart service: `Restart-Service AlertCleanup`

## 📞 Quick Commands

```powershell
# Check all services
.\codes\check-all-nssm-services.ps1

# View cleanup logs
Get-Content storage\logs\cleanup-service.log -Tail 50 -Wait

# Test cleanup (dry run)
php artisan cleanup:old-alerts-worker --dry-run

# Manual cleanup (one-time)
php artisan cleanup:old-alerts-worker --check-interval=0

# Stop cleanup service
Stop-Service AlertCleanup

# Start cleanup service
Start-Service AlertCleanup
```

