# Windows Services - Quick Reference

## 🚀 One-Time Setup

### Step 1: Download NSSM

```powershell
# Run as Administrator
Invoke-WebRequest -Uri "https://nssm.cc/release/nssm-2.24.zip" -OutFile "C:\nssm.zip"
Expand-Archive -Path "C:\nssm.zip" -DestinationPath "C:\nssm"
```

### Step 2: Run Setup Script

```powershell
# Navigate to project
cd C:\path\to\your\project

# Run as Administrator
.\setup-services.ps1
```

**That's it!** Services are now installed and running.

## 📊 Check Status

### Quick Check

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

### Detailed Status

```powershell
nssm status AlertPortal
nssm status AlertInitialSync
nssm status AlertUpdateSync
```

### Open Services Manager

```powershell
services.msc
```

## 🔄 Control Services

### Start All

```powershell
Start-Service AlertPortal
Start-Service AlertInitialSync
Start-Service AlertUpdateSync
```

### Stop All

```powershell
Stop-Service AlertPortal
Stop-Service AlertInitialSync
Stop-Service AlertUpdateSync
```

### Restart All

```powershell
Restart-Service AlertPortal
Restart-Service AlertInitialSync
Restart-Service AlertUpdateSync
```

## 📝 View Logs

### Portal Logs (Live)

```powershell
Get-Content "storage\logs\portal-service.log" -Tail 50 -Wait
```

### Initial Sync Logs (Live)

```powershell
Get-Content "storage\logs\initial-sync-service.log" -Tail 50 -Wait
```

### Update Sync Logs (Live)

```powershell
Get-Content "storage\logs\update-sync-service.log" -Tail 50 -Wait
```

### View Last 100 Lines

```powershell
Get-Content "storage\logs\portal-service.log" -Tail 100
Get-Content "storage\logs\initial-sync-service.log" -Tail 100
Get-Content "storage\logs\update-sync-service.log" -Tail 100
```

## 🌐 Test Portal

### From Same Machine

```powershell
# Browser
Start-Process "http://192.168.100.21:9000"

# Command line
curl http://192.168.100.21:9000
```

### From Another Machine

Open browser: `http://192.168.100.21:9000`

## 🔍 Verify Sync is Working

### Check Initial Sync

```sql
-- MySQL - Should decrease over time
SELECT COUNT(*) as unsynced FROM alerts WHERE synced_at IS NULL;

-- PostgreSQL - Should increase over time
SELECT table_name, record_count FROM partition_registry ORDER BY partition_date DESC LIMIT 5;
```

### Check Update Sync

```sql
-- MySQL - status=1 should decrease, status=2 should increase
SELECT status, COUNT(*) FROM alert_pg_update_log GROUP BY status;
```

## 🛠️ Troubleshooting

### Service Won't Start

```powershell
# Check error log
Get-Content "storage\logs\portal-service-error.log" -Tail 50

# Check service configuration
nssm get AlertPortal AppDirectory
nssm get AlertPortal Application
nssm get AlertPortal AppParameters
```

### Portal Not Accessible

```powershell
# Check service is running
Get-Service AlertPortal

# Check firewall
Get-NetFirewallRule -DisplayName "Alert Portal"

# Test locally
curl http://192.168.100.21:9000

# Check if port is in use
netstat -ano | findstr :9000
```

### Workers Not Processing

```powershell
# Check service is running
Get-Service AlertInitialSync
Get-Service AlertUpdateSync

# Check logs
Get-Content "storage\logs\initial-sync-service.log" -Tail 50
Get-Content "storage\logs\update-sync-service.log" -Tail 50

# Check database connections
php artisan tinker
>>> DB::connection('mysql')->getPdo();
>>> DB::connection('pgsql')->getPdo();
```

## 🗑️ Remove Services (if needed)

```powershell
# Stop services
Stop-Service AlertPortal
Stop-Service AlertInitialSync
Stop-Service AlertUpdateSync

# Remove services
nssm remove AlertPortal confirm
nssm remove AlertInitialSync confirm
nssm remove AlertUpdateSync confirm
```

## 🔧 Modify Service Configuration

### Change Port

```powershell
# Stop service
nssm stop AlertPortal

# Update port
nssm set AlertPortal AppParameters "artisan serve --host=192.168.100.21 --port=8080"

# Update firewall
New-NetFirewallRule -DisplayName "Alert Portal 8080" -Direction Inbound -LocalPort 8080 -Protocol TCP -Action Allow

# Start service
nssm start AlertPortal
```

### Change Sync Interval

```powershell
# Stop service
nssm stop AlertInitialSync

# Update interval (e.g., every 10 minutes)
nssm set AlertInitialSync AppParameters "artisan sync:partitioned --poll-interval=10"

# Start service
nssm start AlertInitialSync
```

### Change Batch Size

```powershell
# Stop service
nssm stop AlertUpdateSync

# Update batch size
nssm set AlertUpdateSync AppParameters "artisan sync:update-worker --poll-interval=5 --batch-size=200"

# Start service
nssm start AlertUpdateSync
```

## 📋 Service Details

### AlertPortal
- **Purpose:** Web server for portal
- **URL:** http://192.168.100.21:9000
- **Command:** `php artisan serve --host=192.168.100.21 --port=9000`
- **Logs:** `storage\logs\portal-service.log`

### AlertInitialSync
- **Purpose:** Sync new alerts from MySQL to PostgreSQL
- **Interval:** Every 20 minutes (configurable)
- **Command:** `php artisan sync:partitioned --poll-interval=20`
- **Logs:** `storage\logs\initial-sync-service.log`

### AlertUpdateSync
- **Purpose:** Sync alert updates from MySQL to PostgreSQL
- **Interval:** Every 5 seconds (configurable)
- **Command:** `php artisan sync:update-worker --poll-interval=5 --batch-size=100`
- **Logs:** `storage\logs\update-sync-service.log`

## ✅ Benefits

- ✅ **Auto-start on boot** - Services start when Windows starts
- ✅ **Run without login** - Services run even when no one is logged in
- ✅ **Auto-restart on crash** - Services restart automatically if they fail
- ✅ **Background execution** - No terminal windows needed
- ✅ **Centralized management** - Manage from `services.msc`
- ✅ **Log rotation** - Automatic log file rotation (10MB max)

## 🎯 What Runs Always

1. **Portal** - Always accessible at http://192.168.100.21:9000
2. **Initial Sync** - Always syncing new alerts
3. **Update Sync** - Always syncing alert updates

**Even if:**
- You close all terminals
- You log out
- You restart the computer
- Someone closes a command prompt

**Services keep running!** 🎉

## 📞 Support

If services are not working:

1. Check service status: `Get-Service | Where-Object {$_.Name -like "Alert*"}`
2. Check logs: `Get-Content "storage\logs\*-service-error.log"`
3. Verify paths in setup script
4. Ensure MySQL and PostgreSQL are running
5. Check `.env` database configuration
