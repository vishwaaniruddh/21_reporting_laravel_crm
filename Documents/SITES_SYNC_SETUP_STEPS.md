# Sites Sync - Quick Setup Steps

## What This System Does

✅ **Reads** from MySQL `sites`, `dvrsite`, `dvronline` tables (READ-ONLY)
✅ **Writes** to PostgreSQL `sites`, `dvrsite`, `dvronline` tables
✅ **Tracks** changes in MySQL `sites_pg_update_log` table
✅ **Never deletes or modifies** MySQL source data

## Setup Steps

### 1. Create the Log Table in MySQL

```bash
mysql -u root -p your_database_name < codes/create-sites-update-log-table.sql
```

This creates `sites_pg_update_log` table to track changes.

### 2. Create the Triggers in MySQL

```bash
mysql -u root -p your_database_name < codes/create-sites-triggers.sql
```

This creates 6 triggers:
- `sites_after_insert` / `sites_after_update`
- `dvrsite_after_insert` / `dvrsite_after_update`
- `dvronline_after_insert` / `dvronline_after_update`

### 3. Verify Triggers Were Created

```bash
mysql -u root -p your_database_name -e "SHOW TRIGGERS WHERE \`Table\` IN ('sites', 'dvrsite', 'dvronline');"
```

### 4. Start the Update Sync Worker

```bash
php artisan sites:update-worker
```

Or use PowerShell:
```powershell
.\codes\start-sites-update-worker.ps1
```

The worker will:
- Poll `sites_pg_update_log` every 5 seconds
- Read changed records from MySQL
- Update PostgreSQL tables
- Mark log entries as completed

## Testing

### You Manually Update MySQL

Go ahead and update any field in MySQL:

```sql
-- Example: Update a site
UPDATE sites SET City = 'New City Name' WHERE SN = 123;

-- Example: Update dvrsite
UPDATE dvrsite SET Status = 'Active' WHERE SN = 456;

-- Example: Update dvronline
UPDATE dvronline SET remark = 'Test update' WHERE id = 789;
```

### Check if Trigger Created Log Entry

```bash
php codes/check-sites-sync-status.php
```

Or manually:
```sql
SELECT * FROM sites_pg_update_log ORDER BY id DESC LIMIT 10;
```

You should see entries with `status=1` (pending).

### Watch the Worker Process Them

The worker will automatically:
1. Fetch pending entries (status=1)
2. Read the record from MySQL
3. Update PostgreSQL
4. Mark entry as completed (status=2)

### Verify PostgreSQL Was Updated

```bash
php codes/check-sites-sync-status.php
```

Check the "Database Record Counts" section to ensure MySQL and PostgreSQL counts match.

## Monitoring Commands

```bash
# Check sync status
php codes/check-sites-sync-status.php

# Check if worker is running (Windows PowerShell)
Get-Process -Name php | Where-Object { $_.CommandLine -like "*sites:update-worker*" }

# View Laravel logs
tail -f storage/logs/laravel.log

# Check pending entries
mysql -u root -p -e "SELECT COUNT(*) as pending FROM sites_pg_update_log WHERE status=1;"
```

## What Gets Synced

**ALL columns** from MySQL are synced to PostgreSQL, including:
- sites: All 51 columns (SN, Status, Phase, Customer, Bank, ATMID, etc.)
- dvrsite: All 44 columns (SN, Status, Phase, Customer, Bank, ATMID, etc.)
- dvronline: All 23 columns (id, ATMID, Address, Location, State, etc.)

**Excluded:** Only `synced_at` column is excluded to prevent trigger loops.

## Important Notes

⚠️ **MySQL is READ-ONLY**: The sync system NEVER updates, deletes, or modifies MySQL source tables (sites, dvrsite, dvronline).

⚠️ **Log Table is Updated**: Only `sites_pg_update_log` is updated (status changes from 1→2 or 1→3).

⚠️ **Automatic Sync**: Once triggers are installed, ANY update you make to MySQL will automatically sync to PostgreSQL.

⚠️ **Worker Must Run**: The worker must be running for automatic sync. If stopped, updates will queue up and process when restarted.

## Files Created

### SQL Scripts
- `codes/create-sites-update-log-table.sql` - Creates log table
- `codes/create-sites-triggers.sql` - Creates all 6 triggers

### PHP Services
- `app/Services/SitesUpdateSyncService.php` - Handles update sync
- `app/Services/SitesSyncService.php` - Handles full sync
- `app/Services/SitesSyncResult.php` - Result object

### Commands
- `app/Console/Commands/SitesUpdateSyncWorker.php` - Worker command
- `app/Console/Commands/SitesSyncCommand.php` - Manual sync command

### Monitoring Scripts
- `codes/check-sites-sync-status.php` - Status checker
- `codes/test-sites-trigger.php` - Trigger tester
- `codes/start-sites-update-worker.ps1` - Worker starter

### Configuration
- `config/sites-sync.php` - Configuration file

### Documentation
- `SITES_SYNC_GUIDE.md` - Complete guide
- `SITES_SYNC_SETUP_STEPS.md` - This file

## Quick Reference

```bash
# Setup (run once)
mysql -u root -p < codes/create-sites-update-log-table.sql
mysql -u root -p < codes/create-sites-triggers.sql

# Start worker (keep running)
php artisan sites:update-worker

# Monitor
php codes/check-sites-sync-status.php

# Manual full sync (if needed)
php artisan sites:sync --status
php artisan sites:sync
```

## You're Ready!

Now you can:
1. Update any field in MySQL `sites`, `dvrsite`, or `dvronline` tables
2. The trigger will automatically log it to `sites_pg_update_log`
3. The worker will automatically sync it to PostgreSQL
4. Check status anytime with `php codes/check-sites-sync-status.php`

**The system is completely READ-ONLY for MySQL source tables!**
