# Production-Ready Setup - Complete Guide

## 🎯 Goal

Make your Alert System run **24/7** with:
1. Portal always accessible at http://192.168.100.21:9000
2. Continuous alert syncing (new alerts)
3. Continuous update syncing (changed alerts)

**All running as Windows Services that:**
- Start automatically on boot
- Run in background (no terminal needed)
- Restart automatically if they crash
- Keep running even if you log out

## 📋 Prerequisites

- Windows Server or Windows 10/11
- PHP installed
- MySQL running
- PostgreSQL running
- Project deployed
- Administrator access

## 🚀 Quick Setup (5 Minutes)

### Step 1: Download NSSM

```powershell
# Open PowerShell as Administrator
# Right-click PowerShell → Run as Administrator

# Download NSSM
Invoke-WebRequest -Uri "https://nssm.cc/release/nssm-2.24.zip" -OutFile "C:\nssm.zip"
Expand-Archive -Path "C:\nssm.zip" -DestinationPath "C:\nssm"
```

### Step 2: Navigate to Project

```powershell
cd C:\path\to\your\project
```

### Step 3: Edit Setup Script

Open `setup-services.ps1` and update these lines:

```powershell
$PHP_PATH = "C:\php\php.exe"  # Your PHP path
$PROJECT_PATH = (Get-Location).Path  # Current directory (auto-detected)
$NSSM_PATH = "C:\nssm\nssm-2.24\win64\nssm.exe"  # NSSM path
```

**Find your PHP path:**
```powershell
where.exe php
# Example output: C:\php\php.exe
```

### Step 4: Run Setup Script

```powershell
# Still as Administrator
.\setup-services.ps1
```

**Expected Output:**
```
=== Alert System Services Setup ===

✓ PHP found: C:\php\php.exe
✓ Project found: C:\path\to\your\project
✓ NSSM found: C:\nssm\nssm-2.24\win64\nssm.exe

Creating service: Alert System Portal...
✓ Service created and started: Alert System Portal

Creating service: Alert Initial Sync Worker...
✓ Service created and started: Alert Initial Sync Worker

Creating service: Alert Update Sync Worker...
✓ Service created and started: Alert Update Sync Worker

✓ Firewall rule created for port 9000

=== Setup Complete ===

Services created and started:
  1. AlertPortal - http://192.168.100.21:9000
  2. AlertInitialSync - Syncing new alerts every 20 minutes
  3. AlertUpdateSync - Syncing alert updates every 5 seconds
```

### Step 5: Verify Services

```powershell
Get-Service | Where-Object {$_.Name -like "Alert*"}
```

**Expected:**
```
Status   Name               DisplayName
------   ----               -----------
Running  AlertPortal        Alert System Portal
Running  AlertInitialSync   Alert Initial Sync Worker
Running  AlertUpdateSync    Alert Update Sync Worker
```

### Step 6: Test Portal

Open browser: http://192.168.100.21:9000

**Should see:** Your Alert System portal

## ✅ Verification Checklist

### 1. Services Running

```powershell
Get-Service | Where-Object {$_.Name -like "Alert*"} | Format-Table -AutoSize
```

All should show `Running`

### 2. Portal Accessible

```powershell
# From same machine
curl http://192.168.100.21:9000

# From browser
Start-Process "http://192.168.100.21:9000"
```

### 3. Initial Sync Working

```sql
-- MySQL - Unsynced count should decrease
SELECT COUNT(*) as unsynced FROM alerts WHERE synced_at IS NULL;

-- PostgreSQL - Record count should increase
SELECT table_name, record_count FROM partition_registry ORDER BY partition_date DESC LIMIT 5;
```

### 4. Update Sync Working

```sql
-- MySQL - status=1 should decrease, status=2 should increase
SELECT status, COUNT(*) FROM alert_pg_update_log GROUP BY status;
```

### 5. Logs Generating

```powershell
# Check log files exist and are being written
Get-ChildItem "storage\logs\*-service.log" | Select-Object Name, Length, LastWriteTime
```

## 📊 Monitoring

### Real-Time Log Monitoring

```powershell
# Portal logs
Get-Content "storage\logs\portal-service.log" -Tail 50 -Wait

# Initial sync logs
Get-Content "storage\logs\initial-sync-service.log" -Tail 50 -Wait

# Update sync logs
Get-Content "storage\logs\update-sync-service.log" -Tail 50 -Wait
```

### Service Status Dashboard

```powershell
# Open Windows Services Manager
services.msc
```

Look for:
- Alert System Portal
- Alert Initial Sync Worker
- Alert Update Sync Worker

All should be:
- Status: Running
- Startup Type: Automatic

### Database Monitoring

```sql
-- MySQL - Sync progress
SELECT 
    COUNT(*) as total_alerts,
    COUNT(synced_at) as synced,
    COUNT(*) - COUNT(synced_at) as pending
FROM alerts;

-- MySQL - Update sync status
SELECT 
    status,
    CASE status
        WHEN 1 THEN 'Pending'
        WHEN 2 THEN 'Completed'
        WHEN 3 THEN 'Failed'
    END as status_name,
    COUNT(*) as count
FROM alert_pg_update_log
GROUP BY status;

-- PostgreSQL - Partition growth
SELECT 
    table_name,
    partition_date,
    record_count,
    last_updated
FROM partition_registry
ORDER BY partition_date DESC
LIMIT 10;
```

## 🔧 Common Tasks

### Restart All Services

```powershell
Restart-Service AlertPortal
Restart-Service AlertInitialSync
Restart-Service AlertUpdateSync
```

### Stop All Services

```powershell
Stop-Service AlertPortal
Stop-Service AlertInitialSync
Stop-Service AlertUpdateSync
```

### Start All Services

```powershell
Start-Service AlertPortal
Start-Service AlertInitialSync
Start-Service AlertUpdateSync
```

### View Service Configuration

```powershell
nssm get AlertPortal AppDirectory
nssm get AlertPortal Application
nssm get AlertPortal AppParameters
```

### Change Configuration

```powershell
# Example: Change portal port to 8080
nssm stop AlertPortal
nssm set AlertPortal AppParameters "artisan serve --host=192.168.100.21 --port=8080"
nssm start AlertPortal

# Example: Change sync interval to 10 minutes
nssm stop AlertInitialSync
nssm set AlertInitialSync AppParameters "artisan sync:partitioned --poll-interval=10"
nssm start AlertInitialSync
```

## 🛠️ Troubleshooting

### Service Won't Start

**Check error logs:**
```powershell
Get-Content "storage\logs\portal-service-error.log" -Tail 50
Get-Content "storage\logs\initial-sync-service-error.log" -Tail 50
Get-Content "storage\logs\update-sync-service-error.log" -Tail 50
```

**Common issues:**
1. PHP path incorrect → Update `$PHP_PATH` in setup script
2. Project path incorrect → Update `$PROJECT_PATH` in setup script
3. Database not running → Start MySQL and PostgreSQL
4. `.env` misconfigured → Check database credentials

### Portal Not Accessible

**Check firewall:**
```powershell
Get-NetFirewallRule -DisplayName "Alert Portal"
```

**If missing, create rule:**
```powershell
New-NetFirewallRule -DisplayName "Alert Portal" -Direction Inbound -LocalPort 9000 -Protocol TCP -Action Allow
```

**Check port is not in use:**
```powershell
netstat -ano | findstr :9000
```

### Workers Not Processing

**Check database connections:**
```powershell
php artisan tinker
>>> DB::connection('mysql')->getPdo();
>>> DB::connection('pgsql')->getPdo();
>>> exit
```

**Check `.env` file:**
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password

DB_CONNECTION_PGSQL=pgsql
DB_HOST_PGSQL=127.0.0.1
DB_PORT_PGSQL=5432
DB_DATABASE_PGSQL=your_pg_database
DB_USERNAME_PGSQL=your_pg_username
DB_PASSWORD_PGSQL=your_pg_password
```

## 🔄 System Restart Behavior

### What Happens on Restart

1. **Windows boots**
2. **Services start automatically** (in order):
   - AlertPortal
   - AlertInitialSync
   - AlertUpdateSync
3. **Portal becomes accessible** at http://192.168.100.21:9000
4. **Sync workers start processing**

### Test Restart

```powershell
# Restart computer
Restart-Computer

# After restart, check services
Get-Service | Where-Object {$_.Name -like "Alert*"}
```

All should be `Running`

## 📈 Performance Tuning

### Increase Sync Speed

```powershell
# Stop service
nssm stop AlertInitialSync

# Increase frequency (every 10 minutes instead of 20)
nssm set AlertInitialSync AppParameters "artisan sync:partitioned --poll-interval=10"

# Start service
nssm start AlertInitialSync
```

### Increase Update Batch Size

```powershell
# Stop service
nssm stop AlertUpdateSync

# Process 200 updates at a time instead of 100
nssm set AlertUpdateSync AppParameters "artisan sync:update-worker --poll-interval=5 --batch-size=200"

# Start service
nssm start AlertUpdateSync
```

### Reduce Portal Memory Usage

Edit `.env`:
```env
APP_DEBUG=false
LOG_LEVEL=warning
```

Restart portal:
```powershell
Restart-Service AlertPortal
```

## 🔒 Security Considerations

### Firewall Rules

```powershell
# Check existing rules
Get-NetFirewallRule | Where-Object {$_.DisplayName -like "*Alert*"}

# Allow only specific IP range (example)
New-NetFirewallRule -DisplayName "Alert Portal - Internal Only" `
    -Direction Inbound `
    -LocalPort 9000 `
    -Protocol TCP `
    -Action Allow `
    -RemoteAddress 192.168.100.0/24
```

### Service Account

By default, services run as `Local System`. For better security, create a dedicated service account:

```powershell
# Create service account (run as Administrator)
$password = ConvertTo-SecureString "YourStrongPassword" -AsPlainText -Force
New-LocalUser -Name "AlertServiceUser" -Password $password -Description "Alert System Service Account"

# Grant permissions to project directory
icacls "C:\path\to\your\project" /grant "AlertServiceUser:(OI)(CI)F" /T

# Update service to use this account
nssm set AlertPortal ObjectName ".\AlertServiceUser" "YourStrongPassword"
nssm set AlertInitialSync ObjectName ".\AlertServiceUser" "YourStrongPassword"
nssm set AlertUpdateSync ObjectName ".\AlertServiceUser" "YourStrongPassword"

# Restart services
Restart-Service AlertPortal
Restart-Service AlertInitialSync
Restart-Service AlertUpdateSync
```

## 📚 Documentation Files

- **WINDOWS_SERVICES_SETUP.md** - Detailed setup guide
- **SERVICES_QUICK_REFERENCE.md** - Quick command reference
- **setup-services.ps1** - Automated setup script
- **PRODUCTION_READY_SETUP.md** - This file

## 🎉 Success Criteria

Your system is production-ready when:

✅ All 3 services show `Running` status
✅ Portal accessible at http://192.168.100.21:9000
✅ Initial sync processing (unsynced count decreasing)
✅ Update sync processing (status=2 count increasing)
✅ Services survive system restart
✅ Services run without any terminal open
✅ Logs being generated without errors

## 🚀 You're Done!

Your Alert System is now running 24/7 as Windows Services!

**What you have:**
- ✅ Portal always accessible
- ✅ Continuous alert syncing
- ✅ Continuous update syncing
- ✅ Auto-start on boot
- ✅ Auto-restart on crash
- ✅ Background execution
- ✅ Centralized management

**No more:**
- ❌ Keeping terminals open
- ❌ Manually starting processes
- ❌ Worrying about crashes
- ❌ Losing sync on logout

**Your system is production-ready! 🎉**
