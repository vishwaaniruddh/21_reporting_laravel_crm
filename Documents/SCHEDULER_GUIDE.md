# Laravel Scheduler Background Service Guide

## Overview

The Laravel scheduler runs your sync jobs automatically in the background. This guide shows you how to start, stop, and monitor the scheduler without keeping a PowerShell window open.

## Quick Start

### Start Scheduler (Recommended Method)

Run this command to start the scheduler as a background service:

```powershell
.\codes\start-scheduler-service.ps1
```

**Benefits:**
- ✅ Runs in background (no window needed)
- ✅ Continues after you close PowerShell
- ✅ More efficient (uses Laravel's `schedule:work`)
- ✅ Lower CPU usage
- ✅ Runs tasks exactly on schedule

### Check Status

```powershell
.\codes\check-scheduler-status.ps1
```

Shows:
- Whether scheduler is running
- Process details (PID, uptime, memory)
- Current sync status
- Pipeline configuration

### Stop Scheduler

```powershell
.\codes\stop-scheduler.ps1
```

Safely stops all scheduler processes.

## Available Scripts

### 1. `start-scheduler-service.ps1` (Recommended)
Starts the scheduler as a hidden background service using Laravel's `schedule:work` command.

**When to use:** For continuous background syncing without keeping a window open.

### 2. `start-scheduler.ps1` (Original)
Starts the scheduler in the current PowerShell window (requires window to stay open).

**When to use:** For debugging or when you want to see real-time output.

### 3. `start-scheduler-background.ps1` (Alternative)
Starts the original scheduler script as a hidden background process.

**When to use:** Alternative to service method if you prefer the loop-based approach.

### 4. `stop-scheduler.ps1`
Stops all running scheduler processes.

### 5. `check-scheduler-status.ps1`
Shows scheduler status and sync progress.

## Current Sync Configuration

Your scheduler is configured to run:

- **Partitioned Sync:** Every minute (continuous mode)
- **Batch Size:** 10 batches per run (~100,000 records/minute)
- **Off-Peak Preference:** Disabled (runs 24/7)

This means:
- Sync runs every minute automatically
- Processes ~100,000 records per minute
- Continues until all records are synced
- No need to manually trigger syncs

## Workflow

### Initial Setup (One Time)

1. Start the scheduler service:
   ```powershell
   .\codes\start-scheduler-service.ps1
   ```

2. Close PowerShell window (scheduler keeps running)

### Daily Operations

The scheduler runs automatically in the background. You don't need to do anything!

### Monitoring

Check status anytime:
```powershell
.\codes\check-scheduler-status.ps1
```

Or check sync progress:
```powershell
php artisan sync:partitioned --status
```

### Stopping (When Needed)

Only stop if you need to:
- Update configuration
- Restart the server
- Troubleshoot issues

```powershell
.\codes\stop-scheduler.ps1
```

## Troubleshooting

### Scheduler Not Running

**Check if it's running:**
```powershell
.\codes\check-scheduler-status.ps1
```

**Start it:**
```powershell
.\codes\start-scheduler-service.ps1
```

### Sync Not Progressing

**Check sync status:**
```powershell
php artisan sync:partitioned --status
```

**Check logs:**
```powershell
Get-Content storage/logs/laravel.log -Tail 50
```

**Restart scheduler:**
```powershell
.\codes\stop-scheduler.ps1
.\codes\start-scheduler-service.ps1
```

### Multiple Scheduler Instances

If you accidentally started multiple schedulers:

```powershell
.\codes\stop-scheduler.ps1
```

This will show all running instances and let you stop them.

### Scheduler Stops After Server Restart

The scheduler doesn't auto-start after a server reboot. You need to manually start it:

```powershell
.\codes\start-scheduler-service.ps1
```

**Optional:** Create a Windows Task Scheduler task to auto-start on boot (see Advanced Setup below).

## Advanced Setup: Auto-Start on Boot

To make the scheduler start automatically when Windows boots:

1. Open **Task Scheduler** (search in Start menu)

2. Click **Create Task** (not "Create Basic Task")

3. **General Tab:**
   - Name: `Laravel Scheduler`
   - Description: `Runs Laravel scheduled tasks`
   - Select: `Run whether user is logged on or not`
   - Check: `Run with highest privileges`

4. **Triggers Tab:**
   - Click **New**
   - Begin the task: `At startup`
   - Click **OK**

5. **Actions Tab:**
   - Click **New**
   - Action: `Start a program`
   - Program/script: `powershell.exe`
   - Add arguments: `-ExecutionPolicy Bypass -File "C:\path\to\your\project\codes\start-scheduler-service.ps1"`
   - Click **OK**

6. **Conditions Tab:**
   - Uncheck: `Start the task only if the computer is on AC power`

7. **Settings Tab:**
   - Check: `Allow task to be run on demand`
   - Check: `If the task fails, restart every: 1 minute`
   - Attempt to restart up to: `3 times`

8. Click **OK** and enter your Windows password

Now the scheduler will start automatically when Windows boots!

## Manual Sync (When Needed)

If you need to manually trigger a sync (scheduler handles this automatically):

```powershell
# Sync all remaining records
php artisan sync:partitioned --continuous

# Or sync specific number of batches
php artisan sync:partitioned --max-batches=10
```

## Configuration Files

- **Schedule Definition:** `routes/console.php`
- **Pipeline Config:** `config/pipeline.php`
- **Environment Variables:** `.env`

## Support

If you encounter issues:

1. Check scheduler status: `.\codes\check-scheduler-status.ps1`
2. Check logs: `storage/logs/laravel.log`
3. Verify databases are running (MySQL and PostgreSQL)
4. Restart scheduler: `.\codes\stop-scheduler.ps1` then `.\codes\start-scheduler-service.ps1`

