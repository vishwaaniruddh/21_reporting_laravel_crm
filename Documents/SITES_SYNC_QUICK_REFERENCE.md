# Sites Sync - Quick Reference

## Setup NSSM Service (One-Time)

```powershell
# Run this script to create the Windows service
.\codes\create-sites-update-service.ps1
```

This will:
- Create service named `SitesUpdateSync`
- Set it to start automatically on boot
- Configure logging to `storage/logs/sites-update-worker-*.log`
- Start the service immediately

## Check All Services

```powershell
# Check status of ALL services (Alerts + Sites)
.\codes\check-all-nssm-services.ps1
```

This shows:
- All Alert* and Sites* services
- Their status (Running/Stopped)
- Alert update log status
- Sites update log status
- Sync status

## Manage Sites Update Service

```powershell
# Check status
nssm status SitesUpdateSync

# Start service
nssm start SitesUpdateSync

# Stop service
nssm stop SitesUpdateSync

# Restart service
nssm restart SitesUpdateSync

# View service configuration
nssm edit SitesUpdateSync
```

## Check Sites Sync Status

```bash
# Detailed status report
php codes/check-sites-sync-status.php
```

Shows:
- Pending/Completed/Failed counts
- Breakdown by table (sites, dvrsite, dvronline)
- Recent log entries
- Failed entries with errors
- Database record counts (MySQL vs PostgreSQL)

## View Logs

```powershell
# View worker stdout log (last 50 lines)
Get-Content storage\logs\sites-update-worker-stdout.log -Tail 50

# Watch log in real-time
Get-Content storage\logs\sites-update-worker-stdout.log -Tail 50 -Wait

# View worker stderr log (errors)
Get-Content storage\logs\sites-update-worker-stderr.log -Tail 50

# View Laravel log
Get-Content storage\logs\laravel.log -Tail 50 -Wait
```

## Manual Testing

### Test Triggers
```bash
php codes/test-sites-trigger.php
```

### Manually Update MySQL (for testing)
```sql
-- Update a site
UPDATE sites SET City = 'Test City' WHERE SN = 17;

-- Update dvrsite
UPDATE dvrsite SET Status = 'Active' WHERE SN = 1;

-- Update dvronline
UPDATE dvronline SET remark = 'Test remark' WHERE id = 1;
```

### Check if Log Entry Created
```sql
SELECT * FROM sites_pg_update_log ORDER BY id DESC LIMIT 10;
```

## Manual Sync Commands

```bash
# Check sync status
php artisan sites:sync --status

# Full sync all tables
php artisan sites:sync

# Sync specific table
php artisan sites:sync sites
php artisan sites:sync dvrsite
php artisan sites:sync dvronline

# Incremental sync (only new/changed records)
php artisan sites:sync --incremental
```

## Troubleshooting

### Service Won't Start
```powershell
# Check service status
nssm status SitesUpdateSync

# View error log
Get-Content storage\logs\sites-update-worker-stderr.log -Tail 50

# Try running manually to see errors
php artisan sites:update-worker
```

### Entries Stuck at Pending
```bash
# Check if service is running
nssm status SitesUpdateSync

# Check for errors
php codes/check-sites-sync-status.php

# View failed entries
mysql -u root -p -e "SELECT * FROM sites_pg_update_log WHERE status=3 LIMIT 10;"
```

### Reset Failed Entries
```sql
-- Reset failed entries to retry
UPDATE sites_pg_update_log 
SET status = 1, retry_count = 0, error_message = NULL 
WHERE status = 3;
```

### Clean Old Completed Entries
```sql
-- Delete completed entries older than 30 days
DELETE FROM sites_pg_update_log 
WHERE status = 2 
AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

## Service Management

### Stop All Services
```powershell
Get-Service Alert*,Sites* | Stop-Service
```

### Start All Services
```powershell
Get-Service Alert*,Sites* | Start-Service
```

### Restart All Services
```powershell
Get-Service Alert*,Sites* | Restart-Service
```

## File Locations

### Service Configuration
- Service Name: `SitesUpdateSync`
- Display Name: `Sites Update Sync Worker`
- Command: `php artisan sites:update-worker --poll-interval=5 --batch-size=100 --max-retries=3`

### Logs
- `storage/logs/sites-update-worker-stdout.log` - Worker output
- `storage/logs/sites-update-worker-stderr.log` - Worker errors
- `storage/logs/laravel.log` - Laravel application log

### Scripts
- `codes/create-sites-update-service.ps1` - Create NSSM service
- `codes/check-all-nssm-services.ps1` - Check all services
- `codes/check-sites-sync-status.php` - Check sync status
- `codes/test-sites-trigger.php` - Test triggers

### Database
- MySQL: `sites_pg_update_log` table
- MySQL: Triggers on `sites`, `dvrsite`, `dvronline`
- PostgreSQL: `sites`, `dvrsite`, `dvronline` tables

## Configuration

Edit `config/sites-sync.php` or `.env`:

```env
SITES_SYNC_POLL_INTERVAL=5
SITES_SYNC_BATCH_SIZE=100
SITES_SYNC_MAX_RETRIES=3
```

## Important Notes

⚠️ **MySQL is READ-ONLY**: The sync system NEVER modifies MySQL source tables (sites, dvrsite, dvronline)

⚠️ **Service Auto-Starts**: The service is configured to start automatically on system boot

⚠️ **Log Rotation**: Logs rotate daily and when they reach 10MB

⚠️ **Auto-Restart**: Service automatically restarts if it crashes

## Quick Commands Summary

```powershell
# Setup (one-time)
.\codes\create-sites-update-service.ps1

# Check everything
.\codes\check-all-nssm-services.ps1

# Check sites sync
php codes\check-sites-sync-status.php

# Manage service
nssm status SitesUpdateSync
nssm start SitesUpdateSync
nssm stop SitesUpdateSync
nssm restart SitesUpdateSync

# View logs
Get-Content storage\logs\sites-update-worker-stdout.log -Tail 50 -Wait
```
