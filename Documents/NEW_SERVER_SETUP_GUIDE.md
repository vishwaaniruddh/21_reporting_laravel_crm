# Complete Setup Guide - Clone to New Server

This guide walks you through setting up the Alert Portal application on a new server from scratch.

## 📋 Prerequisites Checklist

Before starting, ensure you have:

- [ ] Windows Server 2016+ or Windows 10/11
- [ ] Administrator access
- [ ] Network access to MySQL server (192.168.100.21)
- [ ] Network access to PostgreSQL server (192.168.100.23)
- [ ] At least 8GB RAM (16GB recommended)
- [ ] 100GB free disk space

## 🔧 Step 1: Install Required Software

### 1.1 Install WAMP Server (includes PHP, MySQL, Apache)

```powershell
# Download WAMP from https://www.wampserver.com/en/
# Install to C:\wamp64 (recommended)
# During installation, select PHP 8.2+
```

**After installation:**
- Start WAMP
- Verify PHP is working: Open browser → http://localhost
- Should see WAMP homepage

### 1.2 Install Composer (PHP Package Manager)

```powershell
# Download from https://getcomposer.org/download/
# Run Composer-Setup.exe
# Select PHP executable: C:\wamp64\bin\php\php8.2.13\php.exe
```

**Verify installation:**
```powershell
composer --version
# Should show: Composer version 2.x.x
```

### 1.3 Install Node.js and npm

```powershell
# Download from https://nodejs.org/ (LTS version)
# Run installer (node-v18.x.x-x64.msi)
# Accept defaults
```

**Verify installation:**
```powershell
node --version
# Should show: v18.x.x

npm --version
# Should show: 9.x.x or higher
```

### 1.4 Install Git (Optional, for cloning)

```powershell
# Download from https://git-scm.com/download/win
# Run installer
# Accept defaults
```

### 1.5 Install NSSM (Windows Service Manager)

```powershell
# Open PowerShell as Administrator
Invoke-WebRequest -Uri "https://nssm.cc/release/nssm-2.24.zip" -OutFile "C:\nssm.zip"
Expand-Archive -Path "C:\nssm.zip" -DestinationPath "C:\nssm"

# Verify
Test-Path "C:\nssm\nssm-2.24\win64\nssm.exe"
# Should return: True
```

## 📦 Step 2: Clone/Copy Application

### Option A: Using Git

```powershell
# Navigate to web root
cd C:\wamp64\www

# Clone repository
git clone <repository-url> alert-portal
cd alert-portal
```

### Option B: Manual Copy

```powershell
# Copy entire project folder to:
# C:\wamp64\www\alert-portal

# Verify files exist
cd C:\wamp64\www\alert-portal
dir
# Should see: app, config, database, public, etc.
```

## ⚙️ Step 3: Configure Application

### 3.1 Create Environment File

```powershell
cd C:\wamp64\www\alert-portal

# Copy example file
copy .env.example .env

# Edit .env file
notepad .env
```

### 3.2 Configure .env File

Replace these values in `.env`:

```env
# Application Settings
APP_NAME="Alert Portal"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://YOUR_SERVER_IP:9000
APP_TIMEZONE=Asia/Karachi

# MySQL Database (Source - Alerts)
DB_CONNECTION=mysql
DB_HOST=192.168.100.21
DB_PORT=3306
DB_DATABASE=your_mysql_database_name
DB_USERNAME=your_mysql_username
DB_PASSWORD=your_mysql_password

# PostgreSQL Database (Reporting)
PGSQL_DB_CONNECTION=pgsql
PGSQL_DB_HOST=192.168.100.23
PGSQL_DB_PORT=5432
PGSQL_DB_DATABASE=your_postgres_database_name
PGSQL_DB_USERNAME=your_postgres_username
PGSQL_DB_PASSWORD=your_postgres_password

# Queue Configuration
QUEUE_CONNECTION=database

# Session
SESSION_DRIVER=database
SESSION_LIFETIME=120

# Logging
LOG_CHANNEL=stack
LOG_LEVEL=warning

# Sync Configuration
PIPELINE_SYNC_ENABLED=true
PIPELINE_PARTITIONED_SYNC_ENABLED=true
PIPELINE_CLEANUP_ENABLED=false
```

**Important:** Replace:
- `YOUR_SERVER_IP` with your server's IP address
- Database credentials with actual values
- Keep `PIPELINE_CLEANUP_ENABLED=false` until you're ready

### 3.3 Install PHP Dependencies

```powershell
cd C:\wamp64\www\alert-portal

# Install Composer packages
composer install --no-dev --optimize-autoloader

# This will take 2-5 minutes
# Should see: "Generating optimized autoload files"
```

### 3.4 Install Node Dependencies

```powershell
# Install npm packages
npm install

# This will take 3-10 minutes
# Should see: "added XXX packages"
```

### 3.5 Generate Application Key

```powershell
php artisan key:generate

# Should see: "Application key set successfully."
```

### 3.6 Build Frontend Assets

```powershell
# Build for production
npm run build

# This will take 1-3 minutes
# Should see: "✓ built in XXXms"
```

## 🗄️ Step 4: Setup Databases

### 4.1 Test Database Connections

```powershell
# Test MySQL connection
php artisan db:show --database=mysql

# Should show database info (tables, size, etc.)

# Test PostgreSQL connection
php artisan db:show --database=pgsql

# Should show database info
```

**If connection fails:**
- Check `.env` credentials
- Verify network access to database servers
- Check firewall rules
- Ensure databases exist

### 4.2 Run PostgreSQL Migrations

```powershell
# Create tables in PostgreSQL
php artisan migrate --database=pgsql

# Should see:
# Migration table created successfully.
# Migrating: 2025_01_08_100000_create_sync_tracking_table
# Migrated:  2025_01_08_100000_create_sync_tracking_table
# ... (more migrations)
```

### 4.3 Seed Initial Data

```powershell
# Create roles, permissions, and superadmin user
php artisan db:seed --database=pgsql

# Should see:
# Seeding: RoleSeeder
# Seeded:  RoleSeeder
# Seeding: PermissionSeeder
# Seeded:  PermissionSeeder
# ... (more seeders)
```

### 4.4 Create Storage Link

```powershell
php artisan storage:link

# Should see: "The [public/storage] link has been connected to [storage/app/public]."
```

### 4.5 Setup MySQL Triggers (IMPORTANT!)

**Option A: Using Artisan Command (if available)**
```powershell
php artisan db:seed --class=CreateAlertsTriggers
php artisan db:seed --class=CreateBackAlertsTriggers
```

**Option B: Manual SQL Execution**

Connect to MySQL and run:

```sql
-- File: database/sql/create_alerts_triggers.sql
-- Copy and execute the entire file in MySQL

-- File: database/sql/create_backalerts_triggers.sql
-- Copy and execute the entire file in MySQL
```

**Verify triggers exist:**
```sql
-- In MySQL
SHOW TRIGGERS FROM your_database_name;

-- Should see:
-- alert_after_insert
-- alert_after_update
-- backalert_after_insert
-- backalert_after_update
```

## 🚀 Step 5: Test Application

### 5.1 Start Development Server (Test)

```powershell
cd C:\wamp64\www\alert-portal

# Start Laravel server
php artisan serve --host=0.0.0.0 --port=9000

# Should see:
# INFO  Server running on [http://0.0.0.0:9000].
# Press Ctrl+C to stop the server
```

### 5.2 Access Portal

Open browser and navigate to:
```
http://YOUR_SERVER_IP:9000
```

**You should see:**
- Login page with "Alert Portal" branding
- No errors

### 5.3 Login with Default Credentials

```
Email: superadmin@example.com
Password: Check database/seeders/SuperadminSeeder.php for password
```

**After successful login:**
- You should see the dashboard
- Navigation menu on the left
- No console errors (press F12 to check)

### 5.4 Stop Test Server

```powershell
# Press Ctrl+C in the terminal where server is running
```

## 🔧 Step 6: Create Windows Services

Now we'll set up the application to run 24/7 as Windows services.

### 6.1 Update Service Scripts

Edit the service creation scripts to match your paths:

**File: `codes/setup-services.ps1`**

```powershell
# Open in notepad
notepad codes\setup-services.ps1

# Update these lines:
$PHP_PATH = "C:\wamp64\bin\php\php8.2.13\php.exe"
$PROJECT_PATH = "C:\wamp64\www\alert-portal"
$NSSM_PATH = "C:\nssm\nssm-2.24\win64\nssm.exe"
```

### 6.2 Create All Services

```powershell
# Open PowerShell as Administrator
cd C:\wamp64\www\alert-portal

# Run setup script
.\codes\setup-services.ps1

# This creates 8 services:
# 1. AlertPortal - Web application
# 2. AlertPortalInitialSync - Initial data sync
# 3. AlertPortalUpdateSync - Real-time alert updates
# 4. AlertPortalBackAlertUpdateSync - BackAlert updates
# 5. AlertPortalSitesUpdateSync - Sites sync
# 6. AlertPortalCleanup - Old data cleanup
# 7. AlertPortalBackAlertCleanup - BackAlert cleanup
# 8. AlertPortalQueueWorker - Background jobs
```

**Expected output:**
```
=== Creating Alert Portal Services ===

✓ Creating AlertPortal service...
✓ Creating AlertPortalInitialSync service...
✓ Creating AlertPortalUpdateSync service...
✓ Creating AlertPortalBackAlertUpdateSync service...
✓ Creating AlertPortalSitesUpdateSync service...
✓ Creating AlertPortalCleanup service...
✓ Creating AlertPortalBackAlertCleanup service...
✓ Creating AlertPortalQueueWorker service...

✓ All services created successfully!
```

### 6.3 Start All Services

```powershell
# Start all services
.\codes\start-all-services.ps1

# Or start individually:
Start-Service AlertPortal
Start-Service AlertPortalInitialSync
Start-Service AlertPortalUpdateSync
Start-Service AlertPortalBackAlertUpdateSync
Start-Service AlertPortalSitesUpdateSync
Start-Service AlertPortalCleanup
Start-Service AlertPortalBackAlertCleanup
Start-Service AlertPortalQueueWorker
```

### 6.4 Verify Services Are Running

```powershell
Get-Service AlertPortal* | Format-Table Name, Status, DisplayName -AutoSize

# All should show Status: Running
```

**Expected output:**
```
Name                              Status  DisplayName
----                              ------  -----------
AlertPortal                       Running Alert Portal
AlertPortalBackAlertCleanup       Running Alert Portal BackAlert Cleanup
AlertPortalBackAlertUpdateSync    Running Alert Portal BackAlert Update Sync
AlertPortalCleanup                Running Alert Portal Cleanup
AlertPortalInitialSync            Running Alert Portal Initial Sync
AlertPortalQueueWorker            Running Alert Portal Queue Worker
AlertPortalSitesUpdateSync        Running Alert Portal Sites Update Sync
AlertPortalUpdateSync             Running Alert Portal Update Sync
```

## ✅ Step 7: Verification

### 7.1 Access Portal

```
http://YOUR_SERVER_IP:9000
```

Should be accessible without running any manual commands.

### 7.2 Check Sync Status

**Login to portal → Navigate to Dashboard**

You should see:
- Total alerts count increasing
- Recent alerts appearing
- Statistics updating

### 7.3 Check Service Logs

```powershell
# Portal logs
Get-Content storage\logs\portal-service.log -Tail 20

# Initial sync logs
Get-Content storage\logs\initial-sync-service.log -Tail 20

# Update sync logs
Get-Content storage\logs\update-sync-service.log -Tail 20

# Queue worker logs
Get-Content storage\logs\queue-worker-service.log -Tail 20
```

**Look for:**
- No ERROR messages
- "Synced X records" messages
- "Processing batch" messages

### 7.4 Check Database Sync

**MySQL (Source):**
```sql
-- Check if triggers are logging updates
SELECT COUNT(*) FROM alert_pg_update_log WHERE status = 1;
-- Should show pending updates

SELECT COUNT(*) FROM alert_pg_update_log WHERE status = 2;
-- Should increase over time (completed updates)
```

**PostgreSQL (Reporting):**
```sql
-- Check partition tables exist
SELECT table_name FROM partition_registry ORDER BY partition_date DESC LIMIT 10;

-- Check data is being synced
SELECT COUNT(*) FROM alerts_2026_03_04;  -- Today's partition
-- Should show records
```

### 7.5 Test Report Generation

1. Login to portal
2. Navigate to "Reports" → "Daily Reports"
3. Select today's date
4. Click "View Report"
5. Should see data and statistics

### 7.6 Test CSV Export

1. Navigate to "Downloads"
2. Click "Request Export" for today's date
3. Wait 10-30 seconds
4. Refresh page
5. Download link should appear
6. Click download - CSV file should download

## 🔥 Step 8: Firewall Configuration

### 8.1 Allow Portal Port

```powershell
# Open PowerShell as Administrator

# Create firewall rule for port 9000
New-NetFirewallRule -DisplayName "Alert Portal" `
    -Direction Inbound `
    -LocalPort 9000 `
    -Protocol TCP `
    -Action Allow

# Verify rule exists
Get-NetFirewallRule -DisplayName "Alert Portal"
```

### 8.2 Test from Another Computer

From another computer on the network:
```
http://YOUR_SERVER_IP:9000
```

Should be accessible.

## 🔒 Step 9: Security Hardening

### 9.1 Change Default Credentials

1. Login as superadmin
2. Navigate to "Users"
3. Edit superadmin user
4. Change password to a strong password
5. Save

### 9.2 Create Additional Users

1. Navigate to "Users" → "Add User"
2. Fill in details
3. Assign appropriate role (admin, manager, viewer)
4. Save

### 9.3 Disable Debug Mode

Edit `.env`:
```env
APP_DEBUG=false
LOG_LEVEL=warning
```

Restart portal service:
```powershell
Restart-Service AlertPortal
```

### 9.4 Secure Database Connections

Ensure database users have:
- Strong passwords
- Limited permissions (no DROP, CREATE USER, etc.)
- Access only from specific IPs

## 📊 Step 10: Monitoring Setup

### 10.1 Setup Log Monitoring

Create a monitoring script:

**File: `codes/monitor-services.ps1`**
```powershell
# Monitor all services
while ($true) {
    Clear-Host
    Write-Host "=== Alert Portal Services Status ===" -ForegroundColor Cyan
    Write-Host ""
    
    Get-Service AlertPortal* | Format-Table Name, Status, DisplayName -AutoSize
    
    Write-Host ""
    Write-Host "Press Ctrl+C to stop monitoring" -ForegroundColor Yellow
    
    Start-Sleep -Seconds 5
}
```

Run it:
```powershell
.\codes\monitor-services.ps1
```

### 10.2 Setup Email Alerts (Optional)

Edit `.env`:
```env
MAIL_MAILER=smtp
MAIL_HOST=your_smtp_host
MAIL_PORT=587
MAIL_USERNAME=your_email
MAIL_PASSWORD=your_password
MAIL_FROM_ADDRESS=alerts@yourdomain.com

PIPELINE_EMAIL_NOTIFICATIONS=true
PIPELINE_NOTIFICATION_EMAIL=admin@yourdomain.com
```

Restart services:
```powershell
Restart-Service AlertPortal*
```

## 🎯 Step 11: Performance Optimization

### 11.1 Optimize PHP Configuration

Edit `php.ini` (C:\wamp64\bin\php\php8.2.13\php.ini):

```ini
memory_limit = 2048M
max_execution_time = 300
upload_max_filesize = 100M
post_max_size = 100M
```

### 11.2 Optimize Laravel

```powershell
# Cache configuration
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views
php artisan view:cache
```

### 11.3 Adjust Batch Sizes

Edit `config/update-sync.php`:
```php
'batch_size' => 100,  // Increase to 200 for faster sync
'poll_interval' => 5,  // Keep at 5 seconds
```

Restart update sync service:
```powershell
Restart-Service AlertPortalUpdateSync
```

## 🧪 Step 12: Testing Checklist

- [ ] Portal accessible from browser
- [ ] Login works with superadmin credentials
- [ ] Dashboard shows data
- [ ] All 8 services running
- [ ] Initial sync processing (check logs)
- [ ] Update sync processing (check logs)
- [ ] Queue worker processing jobs
- [ ] Daily reports generate correctly
- [ ] CSV exports work (non-blocking)
- [ ] Excel reports download
- [ ] Sites page loads
- [ ] User management works
- [ ] Services survive server restart
- [ ] Firewall allows external access
- [ ] No errors in logs

## 📚 Additional Resources

### Documentation Files

- `Documents/PRODUCTION_READY_SETUP.md` - Production deployment
- `Documents/SERVICES_QUICK_REFERENCE.md` - Service commands
- `Documents/TROUBLESHOOTING_RESTART_GUIDE.md` - Troubleshooting
- `Documents/QUEUE_EXPORTS_QUICK_START.md` - Queue system
- `README.md` - Complete application documentation

### Quick Commands Reference

```powershell
# Check all services
Get-Service AlertPortal* | Format-Table Name, Status -AutoSize

# Start all services
.\codes\start-all-services.ps1

# Stop all services
.\codes\stop-all-services.ps1

# Restart all services
Get-Service AlertPortal* | Restart-Service

# View logs
Get-Content storage\logs\portal-service.log -Tail 50 -Wait

# Clear cache
php artisan cache:clear
php artisan config:clear
php artisan view:clear

# Check database connections
php artisan db:show --database=mysql
php artisan db:show --database=pgsql
```

## 🆘 Troubleshooting Common Issues

### Issue: Services won't start

**Solution:**
```powershell
# Check service logs
Get-Content storage\logs\*-service-error.log -Tail 50

# Verify PHP path
where.exe php

# Verify project path
cd C:\wamp64\www\alert-portal
pwd

# Recreate service
nssm remove AlertPortal confirm
.\codes\setup-services.ps1
```

### Issue: Portal shows blank page

**Solution:**
```powershell
# Check Laravel logs
Get-Content storage\logs\laravel.log -Tail 50

# Rebuild assets
npm run build

# Clear cache
php artisan cache:clear
php artisan config:clear
php artisan view:clear

# Restart service
Restart-Service AlertPortal
```

### Issue: Database connection failed

**Solution:**
1. Check `.env` credentials
2. Test connection manually:
```powershell
php artisan tinker
>>> DB::connection('mysql')->getPdo();
>>> DB::connection('pgsql')->getPdo();
```
3. Check firewall on database servers
4. Verify network connectivity:
```powershell
Test-NetConnection -ComputerName 192.168.100.21 -Port 3306
Test-NetConnection -ComputerName 192.168.100.23 -Port 5432
```

### Issue: Sync not working

**Solution:**
```powershell
# Check if triggers exist in MySQL
# Run this SQL in MySQL:
SHOW TRIGGERS FROM your_database_name;

# If missing, recreate triggers
php artisan db:seed --class=CreateAlertsTriggers

# Check sync service logs
Get-Content storage\logs\update-sync-service.log -Tail 50

# Restart sync services
Restart-Service AlertPortalUpdateSync
Restart-Service AlertPortalInitialSync
```

### Issue: CSV exports not generating

**Solution:**
```powershell
# Check queue worker is running
Get-Service AlertPortalQueueWorker

# Check queue worker logs
Get-Content storage\logs\queue-worker-service.log -Tail 50

# Check failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Restart queue worker
Restart-Service AlertPortalQueueWorker
```

## 🎉 Setup Complete!

Your Alert Portal is now fully configured and running on the new server!

**What you have:**
- ✅ Laravel + React application running
- ✅ 8 Windows services for automation
- ✅ Real-time MySQL → PostgreSQL sync
- ✅ Partition-based data management
- ✅ Queue-based CSV exports
- ✅ Role-based access control
- ✅ Comprehensive reporting
- ✅ Auto-start on server boot
- ✅ Auto-restart on crash

**Next Steps:**
1. Change default passwords
2. Create user accounts for your team
3. Configure email notifications (optional)
4. Setup regular database backups
5. Monitor logs for first 24 hours
6. Test all features thoroughly

**Support:**
- Check `Documents/` folder for detailed guides
- Review logs in `storage/logs/`
- Refer to `README.md` for feature documentation

---

**Setup Date:** March 4, 2026  
**Version:** 1.0.0  
**Server:** YOUR_SERVER_IP:9000
