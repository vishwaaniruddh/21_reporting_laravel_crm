# Windows Services Setup - Always Running

**Goal:** Run 3 services that start automatically on boot and run forever:
1. **Web Server** - Portal accessible at http://192.168.100.21:9000
2. **Initial Sync Worker** - Continuous alert syncing from MySQL to PostgreSQL
3. **Update Sync Worker** - Continuous update syncing for changed alerts

## Prerequisites

### 1. Download NSSM (Non-Sucking Service Manager)

Download from: https://nssm.cc/download

**Steps:**
1. Download `nssm-2.24.zip` (or latest version)
2. Extract to `C:\nssm`
3. Add to PATH or use full path

**Quick Install:**
```powershell
# Download and extract NSSM
Invoke-WebRequest -Uri "https://nssm.cc/release/nssm-2.24.zip" -OutFile "C:\nssm.zip"
Expand-Archive -Path "C:\nssm.zip" -DestinationPath "C:\nssm"

# Add to PATH (run as Administrator)
$env:Path += ";C:\nssm\nssm-2.24\win64"
[Environment]::SetEnvironmentVariable("Path", $env:Path, [System.EnvironmentVariableTarget]::Machine)
```

### 2. Find Your PHP Path

```powershell
# Find PHP executable
where.exe php

# Example output: C:\php\php.exe
```

### 3. Find Your Project Path

```powershell
# Navigate to your project
cd C:\path\to\your\project

# Get full path
pwd

# Example output: C:\xampp\htdocs\alert-system
```

## Service 1: Web Server (Portal)

### Create the Service

```powershell
# Run as Administrator
nssm install AlertPortal "C:\php\php.exe" "artisan serve --host=192.168.100.21 --port=9000"
```

### Configure the Service

```powershell
# Set working directory
nssm set AlertPortal AppDirectory "C:\path\to\your\project"

# Set display name
nssm set AlertPortal DisplayName "Alert System Portal"

# Set description
nssm set AlertPortal Description "Laravel web portal for Alert System accessible at http://192.168.100.21:9000"

# Set startup type to automatic
nssm set AlertPortal Start SERVICE_AUTO_START

# Set restart behavior (restart on failure)
nssm set AlertPortal AppExit Default Restart
nssm set AlertPortal AppRestartDelay 5000

# Set log files
nssm set AlertPortal AppStdout "C:\path\to\your\project\storage\logs\portal-service.log"
nssm set AlertPortal AppStderr "C:\path\to\your\project\storage\logs\portal-service-error.log"

# Rotate logs (10MB max)
nssm set AlertPortal AppStdoutCreationDisposition 4
nssm set AlertPortal AppStderrCreationDisposition 4
nssm set AlertPortal AppRotateFiles 1
nssm set AlertPortal AppRotateOnline 1
nssm set AlertPortal AppRotateBytes 10485760
```

### Start the Service

```powershell
nssm start AlertPortal
```

### Check Status

```powershell
nssm status AlertPortal

# Or use Windows Services
services.msc
```

## Service 2: Initial Sync Worker

### Create the Service

```powershell
# Run as Administrator
nssm install AlertInitialSync "C:\php\php.exe" "artisan sync:partitioned --poll-interval=20"
```

### Configure the Service

```powershell
# Set working directory
nssm set AlertInitialSync AppDirectory "C:\path\to\your\project"

# Set display name
nssm set AlertInitialSync DisplayName "Alert Initial Sync Worker"

# Set description
nssm set AlertInitialSync Description "Continuously syncs new alerts from MySQL to PostgreSQL partitioned tables"

# Set startup type to automatic
nssm set AlertInitialSync Start SERVICE_AUTO_START

# Set restart behavior
nssm set AlertInitialSync AppExit Default Restart
nssm set AlertInitialSync AppRestartDelay 5000

# Set log files
nssm set AlertInitialSync AppStdout "C:\path\to\your\project\storage\logs\initial-sync-service.log"
nssm set AlertInitialSync AppStderr "C:\path\to\your\project\storage\logs\initial-sync-service-error.log"

# Rotate logs
nssm set AlertInitialSync AppStdoutCreationDisposition 4
nssm set AlertInitialSync AppStderrCreationDisposition 4
nssm set AlertInitialSync AppRotateFiles 1
nssm set AlertInitialSync AppRotateOnline 1
nssm set AlertInitialSync AppRotateBytes 10485760
```

### Start the Service

```powershell
nssm start AlertInitialSync
```

## Service 3: Update Sync Worker

### Create the Service

```powershell
# Run as Administrator
nssm install AlertUpdateSync "C:\php\php.exe" "artisan sync:update-worker --poll-interval=5 --batch-size=100"
```

### Configure the Service

```powershell
# Set working directory
nssm set AlertUpdateSync AppDirectory "C:\path\to\your\project"

# Set display name
nssm set AlertUpdateSync DisplayName "Alert Update Sync Worker"

# Set description
nssm set AlertUpdateSync Description "Continuously syncs alert updates from MySQL to PostgreSQL partitioned tables"

# Set startup type to automatic
nssm set AlertUpdateSync Start SERVICE_AUTO_START

# Set restart behavior
nssm set AlertUpdateSync AppExit Default Restart
nssm set AlertUpdateSync AppRestartDelay 5000

# Set log files
nssm set AlertUpdateSync AppStdout "C:\path\to\your\project\storage\logs\update-sync-service.log"
nssm set AlertUpdateSync AppStderr "C:\path\to\your\project\storage\logs\update-sync-service-error.log"

# Rotate logs
nssm set AlertUpdateSync AppStdoutCreationDisposition 4
nssm set AlertUpdateSync AppStderrCreationDisposition 4
nssm set AlertUpdateSync AppRotateFiles 1
nssm set AlertUpdateSync AppRotateOnline 1
nssm set AlertUpdateSync AppRotateBytes 10485760
```

### Start the Service

```powershell
nssm start AlertUpdateSync
```

## All-in-One Setup Script

Save this as `setup-services.ps1` and run as Administrator:

```powershell
# ============================================
# Alert System Windows Services Setup
# Run as Administrator
# ============================================

# Configuration
$PHP_PATH = "C:\php\php.exe"
$PROJECT_PATH = "C:\path\to\your\project"  # CHANGE THIS!
$NSSM_PATH = "C:\nssm\nssm-2.24\win64\nssm.exe"

# Check if running as Administrator
$currentPrincipal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "ERROR: This script must be run as Administrator!" -ForegroundColor Red
    exit 1
}

Write-Host "=== Alert System Services Setup ===" -ForegroundColor Cyan
Write-Host ""

# Verify paths
if (-not (Test-Path $PHP_PATH)) {
    Write-Host "ERROR: PHP not found at $PHP_PATH" -ForegroundColor Red
    exit 1
}

if (-not (Test-Path $PROJECT_PATH)) {
    Write-Host "ERROR: Project not found at $PROJECT_PATH" -ForegroundColor Red
    exit 1
}

if (-not (Test-Path $NSSM_PATH)) {
    Write-Host "ERROR: NSSM not found at $NSSM_PATH" -ForegroundColor Red
    Write-Host "Download from: https://nssm.cc/download" -ForegroundColor Yellow
    exit 1
}

Write-Host "✓ PHP found: $PHP_PATH" -ForegroundColor Green
Write-Host "✓ Project found: $PROJECT_PATH" -ForegroundColor Green
Write-Host "✓ NSSM found: $NSSM_PATH" -ForegroundColor Green
Write-Host ""

# Function to create service
function Create-AlertService {
    param(
        [string]$ServiceName,
        [string]$DisplayName,
        [string]$Description,
        [string]$Command,
        [string]$LogPrefix
    )
    
    Write-Host "Creating service: $DisplayName..." -ForegroundColor Yellow
    
    # Remove service if it exists
    & $NSSM_PATH stop $ServiceName 2>$null
    & $NSSM_PATH remove $ServiceName confirm 2>$null
    
    # Install service
    & $NSSM_PATH install $ServiceName $PHP_PATH $Command
    
    # Configure service
    & $NSSM_PATH set $ServiceName AppDirectory $PROJECT_PATH
    & $NSSM_PATH set $ServiceName DisplayName $DisplayName
    & $NSSM_PATH set $ServiceName Description $Description
    & $NSSM_PATH set $ServiceName Start SERVICE_AUTO_START
    & $NSSM_PATH set $ServiceName AppExit Default Restart
    & $NSSM_PATH set $ServiceName AppRestartDelay 5000
    
    # Set log files
    $LogDir = Join-Path $PROJECT_PATH "storage\logs"
    & $NSSM_PATH set $ServiceName AppStdout (Join-Path $LogDir "$LogPrefix-service.log")
    & $NSSM_PATH set $ServiceName AppStderr (Join-Path $LogDir "$LogPrefix-service-error.log")
    
    # Rotate logs
    & $NSSM_PATH set $ServiceName AppStdoutCreationDisposition 4
    & $NSSM_PATH set $ServiceName AppStderrCreationDisposition 4
    & $NSSM_PATH set $ServiceName AppRotateFiles 1
    & $NSSM_PATH set $ServiceName AppRotateOnline 1
    & $NSSM_PATH set $ServiceName AppRotateBytes 10485760
    
    # Start service
    & $NSSM_PATH start $ServiceName
    
    Write-Host "✓ Service created and started: $DisplayName" -ForegroundColor Green
    Write-Host ""
}

# Create Service 1: Web Portal
Create-AlertService `
    -ServiceName "AlertPortal" `
    -DisplayName "Alert System Portal" `
    -Description "Laravel web portal accessible at http://192.168.100.21:9000" `
    -Command "artisan serve --host=192.168.100.21 --port=9000" `
    -LogPrefix "portal"

# Create Service 2: Initial Sync Worker
Create-AlertService `
    -ServiceName "AlertInitialSync" `
    -DisplayName "Alert Initial Sync Worker" `
    -Description "Continuously syncs new alerts from MySQL to PostgreSQL" `
    -Command "artisan sync:partitioned --poll-interval=20" `
    -LogPrefix "initial-sync"

# Create Service 3: Update Sync Worker
Create-AlertService `
    -ServiceName "AlertUpdateSync" `
    -DisplayName "Alert Update Sync Worker" `
    -Description "Continuously syncs alert updates from MySQL to PostgreSQL" `
    -Command "artisan sync:update-worker --poll-interval=5 --batch-size=100" `
    -LogPrefix "update-sync"

Write-Host "=== Setup Complete ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Services created:" -ForegroundColor Green
Write-Host "  1. AlertPortal - http://192.168.100.21:9000"
Write-Host "  2. AlertInitialSync - Syncing new alerts"
Write-Host "  3. AlertUpdateSync - Syncing alert updates"
Write-Host ""
Write-Host "Check status:" -ForegroundColor Yellow
Write-Host "  nssm status AlertPortal"
Write-Host "  nssm status AlertInitialSync"
Write-Host "  nssm status AlertUpdateSync"
Write-Host ""
Write-Host "View logs:" -ForegroundColor Yellow
Write-Host "  $PROJECT_PATH\storage\logs\"
Write-Host ""
Write-Host "Manage services:" -ForegroundColor Yellow
Write-Host "  services.msc"
Write-Host ""
```

## Quick Commands

### Check Service Status

```powershell
# Check all services
nssm status AlertPortal
nssm status AlertInitialSync
nssm status AlertUpdateSync

# Or open Services Manager
services.msc
```

### Start/Stop Services

```powershell
# Start
nssm start AlertPortal
nssm start AlertInitialSync
nssm start AlertUpdateSync

# Stop
nssm stop AlertPortal
nssm stop AlertInitialSync
nssm stop AlertUpdateSync

# Restart
nssm restart AlertPortal
nssm restart AlertInitialSync
nssm restart AlertUpdateSync
```

### View Logs

```powershell
# Portal logs
Get-Content "C:\path\to\your\project\storage\logs\portal-service.log" -Tail 50 -Wait

# Initial sync logs
Get-Content "C:\path\to\your\project\storage\logs\initial-sync-service.log" -Tail 50 -Wait

# Update sync logs
Get-Content "C:\path\to\your\project\storage\logs\update-sync-service.log" -Tail 50 -Wait
```

### Remove Services (if needed)

```powershell
# Stop and remove
nssm stop AlertPortal
nssm remove AlertPortal confirm

nssm stop AlertInitialSync
nssm remove AlertInitialSync confirm

nssm stop AlertUpdateSync
nssm remove AlertUpdateSync confirm
```

## Verify Everything is Working

### 1. Check Services are Running

```powershell
Get-Service | Where-Object {$_.Name -like "Alert*"}
```

**Expected Output:**
```
Status   Name               DisplayName
------   ----               -----------
Running  AlertPortal        Alert System Portal
Running  AlertInitialSync   Alert Initial Sync Worker
Running  AlertUpdateSync    Alert Update Sync Worker
```

### 2. Test Portal Access

Open browser: http://192.168.100.21:9000

**Should see:** Your Laravel application

### 3. Check Sync Workers

```sql
-- MySQL - Check initial sync is working
SELECT COUNT(*) as unsynced FROM alerts WHERE synced_at IS NULL;

-- MySQL - Check update sync is working
SELECT status, COUNT(*) FROM alert_pg_update_log GROUP BY status;

-- PostgreSQL - Check data is syncing
SELECT table_name, record_count FROM partition_registry ORDER BY partition_date DESC LIMIT 5;
```

## Firewall Configuration

If portal is not accessible from other machines:

```powershell
# Run as Administrator
New-NetFirewallRule -DisplayName "Alert Portal" -Direction Inbound -LocalPort 9000 -Protocol TCP -Action Allow
```

## Troubleshooting

### Service Won't Start

```powershell
# Check service status
nssm status AlertPortal

# View service configuration
nssm get AlertPortal AppDirectory
nssm get AlertPortal Application
nssm get AlertPortal AppParameters

# Check logs
Get-Content "C:\path\to\your\project\storage\logs\portal-service-error.log"
```

### Portal Not Accessible

1. Check service is running: `nssm status AlertPortal`
2. Check firewall: `Get-NetFirewallRule -DisplayName "Alert Portal"`
3. Test locally: `curl http://192.168.100.21:9000`
4. Check logs: `storage\logs\portal-service.log`

### Workers Not Processing

1. Check service is running: `nssm status AlertInitialSync`
2. Check database connections in `.env`
3. Check logs: `storage\logs\initial-sync-service.log`
4. Verify MySQL and PostgreSQL are running

## Benefits of Windows Services

✅ **Auto-start on boot** - Services start automatically when Windows starts
✅ **Run without login** - Services run even when no user is logged in
✅ **Auto-restart on failure** - Services automatically restart if they crash
✅ **Background execution** - Services run in the background, no terminal needed
✅ **Centralized management** - Manage all services from `services.msc`
✅ **Log rotation** - Automatic log file rotation to prevent disk fill

## Summary

After running the setup script, you will have:

1. **AlertPortal** - Web server always accessible at http://192.168.100.21:9000
2. **AlertInitialSync** - Continuously syncing new alerts every 20 minutes
3. **AlertUpdateSync** - Continuously syncing alert updates every 5 seconds

All services will:
- Start automatically on system boot
- Run in the background (no terminal needed)
- Restart automatically if they crash
- Keep running even if you close all terminals
- Keep running even if you log out
- Survive system restarts

**Your system is now production-ready and will run 24/7!** 🎉
