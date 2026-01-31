# How to Stop the Sync Process

There are different ways to stop the sync depending on how it's running:

## Quick Stop (All Methods)

### Use the Stop Script
```powershell
.\codes\stop-sync.ps1
```

This script will:
- Stop all Windows services (if running)
- Find and stop PHP sync processes
- Show current sync status

---

## Method 1: Stop Manual Sync (Terminal/Console)

If you started sync manually with `php artisan sync:partitioned`:

### Option A: Keyboard Interrupt
Press **Ctrl+C** in the terminal window where sync is running

### Option B: Close Terminal
Simply close the PowerShell/CMD window

### Option C: Kill Process
```powershell
# Find PHP processes
Get-Process php

# Stop specific process by ID
Stop-Process -Id <ProcessID> -Force

# Stop all PHP processes (careful!)
Get-Process php | Stop-Process -Force
```

---

## Method 2: Stop Windows Services (NSSM)

If you set up services with `setup-services.ps1`:

### Stop All Alert Services
```powershell
Get-Service Alert* | Stop-Service
```

### Stop Individual Services
```powershell
# Stop initial sync service
Stop-Service AlertInitialSync

# Stop update sync service
Stop-Service AlertUpdateSync

# Stop web portal
Stop-Service AlertPortal
```

### Using Services Manager (GUI)
1. Press **Win+R**
2. Type `services.msc` and press Enter
3. Find services starting with "Alert"
4. Right-click → Stop

### Using NSSM Directly
```powershell
# Stop service
nssm stop AlertInitialSync

# Remove service completely
nssm remove AlertInitialSync confirm
```

---

## Method 3: Emergency Stop (Force Kill)

If sync is stuck or not responding:

### Kill All PHP Processes
```powershell
# Windows
taskkill /F /IM php.exe

# Or using PowerShell
Get-Process php | Stop-Process -Force
```

### Kill Specific Sync Process
```powershell
# Find processes with sync commands
Get-Process | Where-Object {$_.CommandLine -like "*sync*"}

# Kill by process ID
Stop-Process -Id <ProcessID> -Force
```

---

## Method 4: Pause Sync (Temporary)

If you want to pause without stopping completely:

### Using Emergency Stop Feature
```bash
# Enable emergency stop
php artisan emergency:enable "Maintenance window"

# Check status
php artisan emergency:status

# Disable emergency stop (resume sync)
php artisan emergency:disable
```

This will:
- Prevent new sync batches from starting
- Allow current batch to complete
- Can be resumed later

---

## Verify Sync is Stopped

### Check Services
```powershell
Get-Service Alert* | Select-Object Name, Status
```

### Check PHP Processes
```powershell
Get-Process php -ErrorAction SilentlyContinue
```

### Check Sync Status
```bash
php artisan sync:partitioned --status
```

### Check Service Logs
```powershell
# View last 20 lines of sync log
Get-Content storage\logs\initial-sync-service.log -Tail 20
```

---

## After Stopping Sync

### Resume Sync Later
The sync will automatically resume from where it left off:

```bash
# Manual sync
php artisan sync:partitioned --continuous

# Or restart services
Get-Service Alert* | Start-Service
```

### Check What Was Synced
```bash
# Show sync progress
php codes/check_sync.php

# Or check status
php artisan sync:partitioned --status
```

### Clear Sync State (Start Over)
If you want to reset and start fresh:

```sql
-- MySQL: Clear sync markers
UPDATE alerts SET synced_at = NULL, sync_batch_id = NULL;
TRUNCATE TABLE sync_batches;

-- PostgreSQL: Clear synced data
TRUNCATE TABLE alerts CASCADE;
DELETE FROM partition_registry;
```

---

## Common Scenarios

### Scenario 1: Stop for Maintenance
```powershell
# Stop services
Get-Service Alert* | Stop-Service

# Do maintenance work...

# Restart services
Get-Service Alert* | Start-Service
```

### Scenario 2: Stop Stuck Sync
```powershell
# Force stop all
.\codes\stop-sync.ps1

# Check logs for errors
Get-Content storage\logs\laravel.log -Tail 50

# Restart with smaller batch size
php artisan sync:partitioned --batch-size=1000
```

### Scenario 3: Stop Before System Shutdown
```powershell
# Graceful stop
Get-Service Alert* | Stop-Service

# Wait for processes to finish
Start-Sleep -Seconds 5

# Verify stopped
Get-Service Alert* | Select-Object Name, Status
```

### Scenario 4: Stop One Service, Keep Others Running
```powershell
# Stop only initial sync, keep update sync running
Stop-Service AlertInitialSync

# Verify
Get-Service Alert* | Select-Object Name, Status
```

---

## Troubleshooting

### Service Won't Stop
```powershell
# Force stop with NSSM
nssm stop AlertInitialSync

# Or kill process directly
$service = Get-WmiObject -Class Win32_Service -Filter "Name='AlertInitialSync'"
$processId = $service.ProcessId
Stop-Process -Id $processId -Force
```

### Process Still Running After Stop
```powershell
# Find all PHP processes
Get-Process php | Select-Object Id, StartTime, CPU

# Kill specific one
Stop-Process -Id <ProcessID> -Force
```

### Can't Access Stop Script
```powershell
# Stop services directly
Stop-Service AlertInitialSync, AlertUpdateSync -Force

# Or use Task Manager
# Ctrl+Shift+Esc → Details tab → Find php.exe → End Task
```

---

## Quick Reference

| Method | Command | Use When |
|--------|---------|----------|
| Stop Script | `.\codes\stop-sync.ps1` | General purpose |
| Keyboard | `Ctrl+C` | Manual sync in terminal |
| Services | `Get-Service Alert* \| Stop-Service` | NSSM services |
| Force Kill | `Get-Process php \| Stop-Process -Force` | Emergency/stuck |
| Emergency Stop | `php artisan emergency:enable` | Graceful pause |

---

## Need Help?

Check logs for errors:
```powershell
# Laravel log
Get-Content storage\logs\laravel.log -Tail 50

# Service logs
Get-Content storage\logs\initial-sync-service.log -Tail 50
Get-Content storage\logs\initial-sync-service-error.log -Tail 50
```
