# Service Management UI Guide

## Overview

The Service Management UI provides a web interface to manage all Windows NSSM services from the browser. This eliminates the need to use PowerShell commands and prevents services from being accidentally closed.

## Access

- **Location**: Table Management → Services
- **URL**: http://localhost:9000/services
- **Permission Required**: `services.manage` (assigned to superadmin by default)

## Managed Services

The UI manages the following NSSM services:

### 1. AlertInitialSync
- **Purpose**: Syncs alerts from MySQL to PostgreSQL partitioned tables
- **Command**: `php artisan schedule:work`
- **Status**: Should be running continuously

### 2. AlertUpdateSync
- **Purpose**: Processes alert updates from `alert_pg_update_log` table
- **Command**: `php artisan sync:update-worker --poll-interval=5 --batch-size=100`
- **Status**: Should be running continuously

### 3. AlertCleanup
- **Purpose**: Deletes old records from MySQL `alerts_2` table
- **Retention**: 48 hours (configurable in code)
- **Batch Size**: 100 records per batch
- **Sleep**: 2 seconds between batches
- **Status**: Should be running continuously

### 4. AlertMysqlBackup
- **Purpose**: Daily backup of MySQL data files
- **Schedule**: 2 AM daily
- **Backup Location**: `D:\MysqlFileSystemBackup\YEAR\MONTH\DATE\`
- **Files Backed Up**: alerts.frm, alerts.ibd, alerts.TRG
- **Status**: Should be running continuously

### 5. AlertPortal
- **Purpose**: Laravel application server
- **Port**: 9000
- **Host**: 192.168.100.21
- **Status**: Should be running continuously

### 6. AlertViteDev
- **Purpose**: Vite development server for frontend assets
- **Status**: Should be running during development

## Features

### Service Status
- Real-time status display (Running, Stopped, Not Found)
- Color-coded status badges:
  - Green: Running
  - Red: Stopped
  - Gray: Not Found
  - Yellow: Other states

### Service Actions

#### Start Service
- Starts a stopped service
- Button: Green "Start" button
- Only visible when service is stopped

#### Stop Service
- Stops a running service
- Button: Red "Stop" button
- Only visible when service is running

#### Restart Service
- Restarts a service (stop + start)
- Button: Gray "Restart" button
- Useful for applying configuration changes

#### View Logs
- Opens a modal with the last 100 lines of service logs
- Button: Gray "Logs" button
- Logs are displayed in a terminal-style view

### Auto-Refresh
- Service status automatically refreshes every 10 seconds
- Manual refresh available via "Refresh" button

## Log Files

Each service writes logs to `storage/logs/`:

- `initial-sync-service.log` - AlertInitialSync logs
- `update-sync-service.log` - AlertUpdateSync logs
- `cleanup-service.log` - AlertCleanup logs
- `mysql-backup-service.log` - AlertMysqlBackup logs
- `portal-service.log` - AlertPortal logs
- `vite-service.log` - AlertViteDev logs

## API Endpoints

The UI uses the following API endpoints:

- `GET /api/services` - List all services with status
- `POST /api/services/start` - Start a service
- `POST /api/services/stop` - Stop a service
- `POST /api/services/restart` - Restart a service
- `GET /api/services/logs` - Get service logs

## Troubleshooting

### Service Won't Start
1. Check service logs for errors
2. Verify the service command is correct in NSSM
3. Check if required files/directories exist
4. Ensure PHP and other dependencies are available

### Service Keeps Stopping
1. Check logs for crash errors
2. Verify system resources (CPU, memory, disk)
3. Check for configuration issues
4. Review Windows Event Viewer for system errors

### Can't Access UI
1. Verify you're logged in as superadmin
2. Check that `services.manage` permission is assigned
3. Ensure AlertPortal service is running
4. Check browser console for errors

## PowerShell Commands (Alternative)

If you need to manage services via PowerShell:

```powershell
# Check all services
.\codes\check-all-nssm-services.ps1

# Start a service
Start-Service AlertInitialSync

# Stop a service
Stop-Service AlertInitialSync

# Restart a service
Restart-Service AlertInitialSync

# Check service status
Get-Service Alert* | Select-Object Name, Status, StartType
```

## Configuration Changes

### Cleanup Service
To change retention period or table name:
- Edit: `app/Console/Commands/CleanupOldAlertsWorker.php`
- Line 35: `protected string $tableName = 'alerts_2';`
- Line 48: `protected int $retentionHours = 48;`
- Restart the AlertCleanup service after changes

### Backup Service
To change backup files or location:
- Edit: `app/Console/Commands/MysqlFileBackupWorker.php`
- Line 38: Source directory
- Line 45: Backup directory
- Lines 50-54: Files to backup
- Restart the AlertMysqlBackup service after changes

## Security Notes

- Only superadmin users can access the service management UI
- All service actions are logged
- Services run with system privileges
- Be careful when stopping critical services (AlertPortal, AlertInitialSync, AlertUpdateSync)

## Best Practices

1. **Monitor regularly**: Check service status daily
2. **Review logs**: Check logs when issues occur
3. **Restart carefully**: Only restart services when necessary
4. **Test changes**: Test configuration changes in development first
5. **Backup first**: Ensure backups are working before making changes
