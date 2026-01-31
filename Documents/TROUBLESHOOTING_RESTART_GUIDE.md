# 🔧 Complete Troubleshooting & Restart Guide

**Alert System - Comprehensive Recovery Documentation**  
**Last Updated:** January 9, 2026

---

## Table of Contents

1. [Quick Diagnosis](#quick-diagnosis)
2. [Service Overview](#service-overview)
3. [Common Issues & Solutions](#common-issues--solutions)
4. [Restart Procedures](#restart-procedures)
5. [Database Issues](#database-issues)
6. [Sync Issues](#sync-issues)
7. [Frontend Issues](#frontend-issues)
8. [Backend Issues](#backend-issues)
9. [Complete System Reset](#complete-system-reset)
10. [Verification Steps](#verification-steps)

---

## Quick Diagnosis

### Run This First
```powershell
.\verify-services.ps1
```

This will show you:
- Which services are running/stopped
- Portal accessibility
- Vite dev server status
- Recent activity logs

---

## Service Overview

### All Services in the System

| Service Name | Display Name | Purpose | Port/Interval | Critical |
|--------------|--------------|---------|---------------|----------|
| AlertPortal | Alert System Portal | Web server (Laravel) | Port 9000 | YES |
| AlertViteDev | Alert Vite Dev Server | Frontend assets (React) | Port 5173 | YES |
| AlertInitialSync | Alert Initial Sync Worker | Sync new alerts MySQL→PostgreSQL | Every 20 min | YES |
| AlertUpdateSync | Alert Update Sync Worker | Sync alert updates MySQL→PostgreSQL | Every 5 sec | YES |

### Service Dependencies

```
AlertPortal (Backend)
    ↓
AlertViteDev (Frontend Assets)
    ↓
User sees working portal

AlertInitialSync → Syncs new alerts to PostgreSQL partitions
AlertUpdateSync → Syncs alert updates to PostgreSQL partitions
```

**Important:** Portal needs BOTH AlertPortal AND AlertViteDev to work properly!

---

## Common Issues & Solutions

### Issue 1: Portal Shows Blank Screen

**Symptoms:**
- Browser shows blank white page
- Console shows "WEBSOCKET CONNECTION REFUSED"
- URL loads but nothing displays

**Diagnosis:**
```powershell
# Check if Vite service is running
Get-Service AlertViteDev
```

**Solution:**
```powershell
# Restart Vite dev server
Restart-Service AlertViteDev

# Wait 5 seconds
Start-Sleep -Seconds 5

# Verify it's running
Get-Content "storage\logs\vite-dev-service.log" -Tail 10

# Refresh browser
```

**Expected Log Output:**
```
VITE v7.3.1  ready in 845 ms
➜  Local:   http://127.0.0.1:5173/
```

---

### Issue 2: Portal Not Accessible (Connection Refused)

**Symptoms:**
- Browser shows "Connection refused" or "Can't reach this page"
- http://192.168.100.21:9000 doesn't load

**Diagnosis:**
```powershell
# Check if portal service is running
Get-Service AlertPortal

# Test port connectivity
Test-NetConnection -ComputerName 192.168.100.21 -Port 9000
```

**Solution:**
```powershell
# Restart portal service
Restart-Service AlertPortal

# Wait 5 seconds
Start-Sleep -Seconds 5

# Check logs
Get-Content "storage\logs\portal-service.log" -Tail 10

# Test again
Test-NetConnection -ComputerName 192.168.100.21 -Port 9000
```

**Expected Log Output:**
```
INFO  Server running on [http://192.168.100.21:9000]
Press Ctrl+C to stop the server
```

---

### Issue 3: Sync Not Working (New Alerts Not Appearing)

**Symptoms:**
- New alerts in MySQL not appearing in PostgreSQL
- Portal shows old data

**Diagnosis:**
```powershell
# Check initial sync service
Get-Service AlertInitialSync

# Check recent sync activity
Get-Content "storage\logs\initial-sync-service.log" -Tail 30
```

**Solution:**
```powershell
# Restart initial sync service
Restart-Service AlertInitialSync

# Monitor the sync
Get-Content "storage\logs\initial-sync-service.log" -Tail 20 -Wait
```

**Expected Log Output:**
```
[2026-01-09 15:30:00] Running sync:partitioned...
Batches Processed      | 1
Total Records Synced   | 42
Status                 | ✓ Success
Next sync in 1200 seconds...
```

---

### Issue 4: Updates Not Syncing

**Symptoms:**
- Alert changes in MySQL not reflected in PostgreSQL
- `alert_pg_update_log` table shows status=1 (pending)

**Diagnosis:**
```powershell
# Check update sync service
Get-Service AlertUpdateSync

# Check update sync status
php check_update_sync_status.php
```

**Solution:**
```powershell
# Restart update sync service
Restart-Service AlertUpdateSync

# Wait and check status
Start-Sleep -Seconds 10
php check_update_sync_status.php

# Monitor live
Get-Content "storage\logs\update-sync-service.log" -Tail 20 -Wait
```

**Expected Output:**
```
Pending:   0
Completed: 150
Failed:    0
✓ No pending updates - system is up to date
```

---

### Issue 5: Database Connection Errors

**Symptoms:**
- Logs show "SQLSTATE[HY000] [2002]"
- "Connection refused" or "Too many connections"

**Diagnosis:**
```powershell
# Check error logs
Get-Content "storage\logs\portal-service-error.log" -Tail 50
Get-Content "storage\logs\initial-sync-service-error.log" -Tail 50
Get-Content "storage\logs\update-sync-service-error.log" -Tail 50
```

**Solution 1: Restart MySQL/PostgreSQL**
```powershell
# Restart WAMP (includes MySQL)
# Use WAMP control panel or:
net stop wampapache64
net stop wampmysqld64
Start-Sleep -Seconds 5
net start wampmysqld64
net start wampapache64

# Restart PostgreSQL
Restart-Service postgresql-x64-*
```

**Solution 2: Clear Connection Pool**
```powershell
# Restart all alert services to clear connections
Stop-Service AlertPortal, AlertInitialSync, AlertUpdateSync
Start-Sleep -Seconds 5
Start-Service AlertPortal, AlertInitialSync, AlertUpdateSync
```

---

### Issue 6: All Services Stopped

**Symptoms:**
- Nothing works
- All services show "Stopped" status

**Solution:**
```powershell
# Start all services
Start-Service AlertPortal
Start-Service AlertViteDev
Start-Service AlertInitialSync
Start-Service AlertUpdateSync

# Wait for startup
Start-Sleep -Seconds 10

# Verify
Get-Service | Where-Object {$_.Name -like "Alert*"}
```

---

## Restart Procedures

### Restart Individual Service

```powershell
# Restart portal (backend)
Restart-Service AlertPortal

# Restart Vite (frontend)
Restart-Service AlertViteDev

# Restart initial sync
Restart-Service AlertInitialSync

# Restart update sync
Restart-Service AlertUpdateSync
```

### Restart Frontend (Portal + Vite)

```powershell
# Stop both
Stop-Service AlertPortal
Stop-Service AlertViteDev

# Wait
Start-Sleep -Seconds 5

# Start both
Start-Service AlertViteDev
Start-Sleep -Seconds 3
Start-Service AlertPortal

# Verify
Start-Sleep -Seconds 5
Test-NetConnection -ComputerName 192.168.100.21 -Port 9000
Test-NetConnection -ComputerName 127.0.0.1 -Port 5173
```

### Restart Backend (All Sync Services)

```powershell
# Stop sync services
Stop-Service AlertInitialSync
Stop-Service AlertUpdateSync

# Wait
Start-Sleep -Seconds 5

# Start sync services
Start-Service AlertInitialSync
Start-Service AlertUpdateSync

# Verify
Get-Service AlertInitialSync, AlertUpdateSync
```

### Restart Everything

```powershell
# Stop all services
Stop-Service AlertPortal
Stop-Service AlertViteDev
Stop-Service AlertInitialSync
Stop-Service AlertUpdateSync

# Wait for clean shutdown
Start-Sleep -Seconds 10

# Start in order
Start-Service AlertViteDev
Start-Sleep -Seconds 3
Start-Service AlertPortal
Start-Sleep -Seconds 2
Start-Service AlertInitialSync
Start-Service AlertUpdateSync

# Wait for startup
Start-Sleep -Seconds 10

# Verify everything
.\verify-services.ps1
```

---

## Database Issues

### Check MySQL Connection

```powershell
# Test MySQL connectivity
php -r "try { \$pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', ''); echo 'MySQL: Connected\n'; } catch (Exception \$e) { echo 'MySQL: Failed - ' . \$e->getMessage() . '\n'; }"
```

### Check PostgreSQL Connection

```powershell
# Test PostgreSQL connectivity
php -r "try { \$pdo = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=esurv_pg', 'postgres', 'root'); echo 'PostgreSQL: Connected\n'; } catch (Exception \$e) { echo 'PostgreSQL: Failed - ' . \$e->getMessage() . '\n'; }"
```

### Check Partition Tables

```powershell
# List all partition tables
php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); \$tables = DB::connection('pgsql')->select(\"SELECT tablename FROM pg_tables WHERE tablename LIKE 'alerts_%' ORDER BY tablename\"); foreach(\$tables as \$t) echo \$t->tablename . PHP_EOL;"
```

### Check Partition Registry

```powershell
# View partition registry
php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); \$partitions = DB::connection('pgsql')->table('partition_registry')->orderBy('partition_date', 'desc')->limit(10)->get(['partition_name', 'partition_date', 'record_count']); foreach(\$partitions as \$p) echo \$p->partition_name . ' | ' . \$p->partition_date . ' | ' . \$p->record_count . ' records' . PHP_EOL;"
```

---

## Sync Issues

### Check Initial Sync Status

```powershell
# Run sync status command
php artisan sync:partitioned --status
```

**Expected Output:**
```
Current Sync Status:
Last Synced ID: 563000
Total Records in MySQL: 563500
Remaining to Sync: 500
```

### Manually Trigger Initial Sync

```powershell
# Run one batch manually
php artisan sync:partitioned --max-batches=1

# Run continuous sync manually (Ctrl+C to stop)
php artisan sync:partitioned --continuous
```

### Check Update Sync Status

```powershell
# Check pending updates
php check_update_sync_status.php
```

**Expected Output:**
```
Pending:   0
Completed: 150
Failed:    0
✓ No pending updates - system is up to date
```

### Manually Trigger Update Sync

```powershell
# Run update sync manually (Ctrl+C to stop)
php artisan sync:update-worker --poll-interval=5 --batch-size=100
```

### Clear Sync Metadata (CAUTION!)

```powershell
# This resets sync tracking - use only if sync is stuck
php clear_sync_metadata.php

# Then restart sync services
Restart-Service AlertInitialSync
Restart-Service AlertUpdateSync
```

---

## Frontend Issues

### Vite Not Compiling Assets

**Symptoms:**
- Portal loads but styles are broken
- JavaScript not working
- Console shows 404 errors for assets

**Solution:**
```powershell
# Stop Vite service
Stop-Service AlertViteDev

# Clear Vite cache
Remove-Item -Path "node_modules\.vite" -Recurse -Force -ErrorAction SilentlyContinue

# Reinstall dependencies (if needed)
npm install

# Start Vite service
Start-Service AlertViteDev

# Check logs
Get-Content "storage\logs\vite-dev-service.log" -Tail 20 -Wait
```

### React Components Not Loading

**Solution:**
```powershell
# Restart Vite with clean cache
Stop-Service AlertViteDev
Remove-Item -Path "node_modules\.vite" -Recurse -Force -ErrorAction SilentlyContinue
Start-Service AlertViteDev

# Hard refresh browser
# Press Ctrl+Shift+R or Ctrl+F5
```

### Hot Module Replacement (HMR) Not Working

**Solution:**
```powershell
# Restart Vite service
Restart-Service AlertViteDev

# Check Vite is listening
Test-NetConnection -ComputerName 127.0.0.1 -Port 5173

# Refresh browser
```

---

## Backend Issues

### Laravel Application Errors

**Check Application Logs:**
```powershell
# View Laravel logs
Get-Content "storage\logs\laravel.log" -Tail 50
```

**Clear Laravel Cache:**
```powershell
# Stop portal service
Stop-Service AlertPortal

# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Start portal service
Start-Service AlertPortal
```

### PHP Errors

**Check PHP Error Logs:**
```powershell
# Check portal errors
Get-Content "storage\logs\portal-service-error.log" -Tail 50

# Check sync errors
Get-Content "storage\logs\initial-sync-service-error.log" -Tail 50
Get-Content "storage\logs\update-sync-service-error.log" -Tail 50
```

### Session/Cookie Issues

**Clear Sessions:**
```powershell
# Stop portal
Stop-Service AlertPortal

# Clear sessions
Remove-Item -Path "storage\framework\sessions\*" -Force -ErrorAction SilentlyContinue

# Start portal
Start-Service AlertPortal
```

---

## Complete System Reset

### When to Use This
- Multiple services failing
- Database connections broken
- System behaving erratically
- After major configuration changes

### Full Reset Procedure

```powershell
Write-Host "=== Starting Complete System Reset ===" -ForegroundColor Cyan

# Step 1: Stop all services
Write-Host "Step 1: Stopping all services..." -ForegroundColor Yellow
Stop-Service AlertPortal
Stop-Service AlertViteDev
Stop-Service AlertInitialSync
Stop-Service AlertUpdateSync
Start-Sleep -Seconds 10

# Step 2: Clear caches
Write-Host "Step 2: Clearing caches..." -ForegroundColor Yellow
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
Remove-Item -Path "node_modules\.vite" -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item -Path "storage\framework\cache\*" -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item -Path "storage\framework\views\*" -Force -ErrorAction SilentlyContinue

# Step 3: Restart databases (if needed)
Write-Host "Step 3: Checking databases..." -ForegroundColor Yellow
# Restart WAMP/PostgreSQL if needed

# Step 4: Start services in order
Write-Host "Step 4: Starting services..." -ForegroundColor Yellow
Start-Service AlertViteDev
Start-Sleep -Seconds 5
Start-Service AlertPortal
Start-Sleep -Seconds 3
Start-Service AlertInitialSync
Start-Service AlertUpdateSync
Start-Sleep -Seconds 10

# Step 5: Verify
Write-Host "Step 5: Verifying system..." -ForegroundColor Yellow
.\verify-services.ps1

Write-Host "=== Reset Complete ===" -ForegroundColor Green
```

---

## Verification Steps

### After Any Restart

```powershell
# 1. Check all services are running
Get-Service | Where-Object {$_.Name -like "Alert*"}

# 2. Test portal connectivity
Test-NetConnection -ComputerName 192.168.100.21 -Port 9000

# 3. Test Vite connectivity
Test-NetConnection -ComputerName 127.0.0.1 -Port 5173

# 4. Check recent logs
Get-Content "storage\logs\portal-service.log" -Tail 5
Get-Content "storage\logs\vite-dev-service.log" -Tail 5
Get-Content "storage\logs\initial-sync-service.log" -Tail 5
Get-Content "storage\logs\update-sync-service.log" -Tail 5

# 5. Test portal in browser
# Open: http://192.168.100.21:9000

# 6. Check sync status
php check_update_sync_status.php
```

### Complete Health Check

```powershell
Write-Host "=== System Health Check ===" -ForegroundColor Cyan

# Services
Write-Host "`nServices:" -ForegroundColor Yellow
Get-Service | Where-Object {$_.Name -like "Alert*"} | Format-Table -AutoSize

# Connectivity
Write-Host "`nConnectivity:" -ForegroundColor Yellow
$portal = Test-NetConnection -ComputerName 192.168.100.21 -Port 9000 -WarningAction SilentlyContinue
$vite = Test-NetConnection -ComputerName 127.0.0.1 -Port 5173 -WarningAction SilentlyContinue
Write-Host "Portal (9000): $($portal.TcpTestSucceeded)"
Write-Host "Vite (5173): $($vite.TcpTestSucceeded)"

# Database
Write-Host "`nDatabase:" -ForegroundColor Yellow
php -r "try { \$pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', ''); echo 'MySQL: OK\n'; } catch (Exception \$e) { echo 'MySQL: FAILED\n'; }"
php -r "try { \$pdo = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=esurv_pg', 'postgres', 'root'); echo 'PostgreSQL: OK\n'; } catch (Exception \$e) { echo 'PostgreSQL: FAILED\n'; }"

# Sync Status
Write-Host "`nSync Status:" -ForegroundColor Yellow
php check_update_sync_status.php

Write-Host "`n=== Health Check Complete ===" -ForegroundColor Green
```

---

## Log File Locations

### Service Logs
```
storage/logs/portal-service.log          - Portal output
storage/logs/portal-service-error.log    - Portal errors
storage/logs/vite-dev-service.log        - Vite output
storage/logs/vite-dev-service-error.log  - Vite errors
storage/logs/initial-sync-service.log    - Initial sync output
storage/logs/initial-sync-service-error.log - Initial sync errors
storage/logs/update-sync-service.log     - Update sync output
storage/logs/update-sync-service-error.log  - Update sync errors
```

### Application Logs
```
storage/logs/laravel.log                 - Laravel application log
```

### View Logs
```powershell
# View last 50 lines
Get-Content "storage\logs\[log-file-name]" -Tail 50

# View and follow (live)
Get-Content "storage\logs\[log-file-name]" -Tail 20 -Wait

# Search logs
Select-String -Path "storage\logs\[log-file-name]" -Pattern "error" -Context 2
```

---

## Emergency Contacts & Resources

### Quick Commands Reference
```powershell
# Quick status check
.\verify-services.ps1

# Restart everything
Restart-Service AlertPortal, AlertViteDev, AlertInitialSync, AlertUpdateSync

# Check sync status
php check_update_sync_status.php

# View all logs
Get-ChildItem storage\logs\*-service.log | ForEach-Object { Write-Host "`n=== $($_.Name) ===" -ForegroundColor Cyan; Get-Content $_.FullName -Tail 5 }
```

### Configuration Files
```
.env                          - Environment configuration
config/database.php           - Database configuration
vite.config.js               - Vite configuration
package.json                 - Node dependencies
```

### Service Configuration
```powershell
# View service configuration
nssm dump AlertPortal
nssm dump AlertViteDev
nssm dump AlertInitialSync
nssm dump AlertUpdateSync
```

---

## Preventive Maintenance

### Daily Checks
```powershell
# Run verification
.\verify-services.ps1

# Check sync status
php check_update_sync_status.php
```

### Weekly Maintenance
```powershell
# Clear old logs (optional)
Get-ChildItem storage\logs\*.log | Where-Object {$_.Length -gt 100MB} | ForEach-Object { Clear-Content $_.FullName }

# Check partition count
php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo 'Partitions: ' . DB::connection('pgsql')->table('partition_registry')->count() . PHP_EOL;"
```

---

## Summary

### Quick Restart Commands

| Issue | Command |
|-------|---------|
| Portal not loading | `Restart-Service AlertPortal, AlertViteDev` |
| Blank screen | `Restart-Service AlertViteDev` |
| Sync not working | `Restart-Service AlertInitialSync, AlertUpdateSync` |
| Everything broken | `Restart-Service AlertPortal, AlertViteDev, AlertInitialSync, AlertUpdateSync` |

### Always Remember
1. **Check logs first** - They tell you what's wrong
2. **Restart services** - Fixes 90% of issues
3. **Verify after restart** - Use `.\verify-services.ps1`
4. **Check databases** - Ensure MySQL and PostgreSQL are running
5. **Clear caches** - When configuration changes

---

**Document Version:** 1.0  
**Last Updated:** January 9, 2026  
**System:** Alert Sync System with Windows Services
