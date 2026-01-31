# Sites Sync System Guide

Complete guide for syncing `sites`, `dvrsite`, and `dvronline` tables from MySQL to PostgreSQL.

## Overview

The Sites Sync System uses a **trigger-based approach** similar to the alerts sync system:

1. **MySQL Triggers** automatically log INSERT/UPDATE operations to `sites_pg_update_log`
2. **Update Sync Worker** continuously polls the log table and syncs changes to PostgreSQL
3. **All columns** are synced (complete record synchronization)

## Architecture

```
MySQL Tables (sites, dvrsite, dvronline)
    │
    ├─ INSERT/UPDATE operations
    │
    ├─ Triggers fire automatically
    │
    └─ Insert entry into sites_pg_update_log (status=1)
         │
         ├─ SitesUpdateSyncWorker polls for status=1
         │
         ├─ Read record from MySQL (READ-ONLY)
         │
         ├─ UPSERT to PostgreSQL (INSERT or UPDATE)
         │
         └─ Update sites_pg_update_log (status=2)
```

## Setup Instructions

### Step 1: Create Update Log Table

Run the SQL script to create the log table:

```bash
mysql -u root -p < codes/create-sites-update-log-table.sql
```

Or manually:
```sql
source codes/create-sites-update-log-table.sql;
```

### Step 2: Create MySQL Triggers

Run the SQL script to create triggers for all three tables:

```bash
mysql -u root -p < codes/create-sites-triggers.sql
```

Or manually:
```sql
source codes/create-sites-triggers.sql;
```

### Step 3: Verify Setup

Check that triggers were created:
```sql
SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE 
FROM information_schema.TRIGGERS 
WHERE TRIGGER_SCHEMA = DATABASE() 
AND EVENT_OBJECT_TABLE IN ('sites', 'dvrsite', 'dvronline');
```

You should see 6 triggers:
- `sites_after_insert`
- `sites_after_update`
- `dvrsite_after_insert`
- `dvrsite_after_update`
- `dvronline_after_insert`
- `dvronline_after_update`

### Step 4: Test Triggers

Run the test script:
```bash
php codes/test-sites-trigger.php
```

This will make test updates and verify that log entries are created.

### Step 5: Start Update Sync Worker

Start the worker process:
```bash
php artisan sites:update-worker
```

Or use the PowerShell script:
```powershell
.\codes\start-sites-update-worker.ps1
```

**Worker Options:**
- `--poll-interval=5` - Seconds between polls (default: 5)
- `--batch-size=100` - Max entries per batch (default: 100)
- `--max-retries=3` - Max retry attempts (default: 3)

## Monitoring

### Check Sync Status

```bash
php codes/check-sites-sync-status.php
```

This shows:
- Pending/Completed/Failed counts
- Breakdown by table
- Recent log entries
- Failed entries with errors
- Database record counts

### Check Worker Status

```bash
# Check if worker is running
ps aux | grep "sites:update-worker"

# On Windows PowerShell
Get-Process -Name php | Where-Object { $_.CommandLine -like "*sites:update-worker*" }
```

### Monitor Logs

```bash
tail -f storage/logs/laravel.log
```

## Manual Sync Commands

### Full Sync (All Records)

Sync all records from MySQL to PostgreSQL:
```bash
php artisan sites:sync
```

Sync specific table:
```bash
php artisan sites:sync sites
php artisan sites:sync dvrsite
php artisan sites:sync dvronline
```

### Incremental Sync

Sync only new/unsynced records:
```bash
php artisan sites:sync --incremental
php artisan sites:sync sites --incremental
```

### Check Status

```bash
php artisan sites:sync --status
```

## Troubleshooting

### Problem: Triggers not firing

**Check if triggers exist:**
```sql
SHOW TRIGGERS LIKE 'sites';
SHOW TRIGGERS LIKE 'dvrsite';
SHOW TRIGGERS LIKE 'dvronline';
```

**Recreate triggers:**
```bash
mysql -u root -p < codes/create-sites-triggers.sql
```

### Problem: Log entries stuck at status=1

**Check if worker is running:**
```bash
ps aux | grep "sites:update-worker"
```

**Start the worker:**
```bash
php artisan sites:update-worker
```

**Check for errors in failed entries:**
```sql
SELECT * FROM sites_pg_update_log WHERE status = 3 ORDER BY id DESC LIMIT 10;
```

### Problem: Worker keeps failing

**Check error messages:**
```bash
php codes/check-sites-sync-status.php
```

**Check Laravel logs:**
```bash
tail -f storage/logs/laravel.log
```

**Common issues:**
- PostgreSQL connection issues
- Column mismatch between MySQL and PostgreSQL
- Data type conversion errors

### Problem: Record counts don't match

**Check sync status:**
```bash
php artisan sites:sync --status
```

**Run full sync:**
```bash
php artisan sites:sync
```

## Log Table Maintenance

### Clean Old Completed Entries

Delete completed entries older than 30 days:
```sql
DELETE FROM sites_pg_update_log 
WHERE status = 2 
AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

### Retry Failed Entries

Reset failed entries to retry:
```sql
UPDATE sites_pg_update_log 
SET status = 1, retry_count = 0, error_message = NULL 
WHERE status = 3 
AND id IN (SELECT id FROM (SELECT id FROM sites_pg_update_log WHERE status = 3 LIMIT 10) AS tmp);
```

### View Log Statistics

```sql
SELECT 
    table_name,
    status,
    CASE status
        WHEN 1 THEN 'Pending'
        WHEN 2 THEN 'Completed'
        WHEN 3 THEN 'Failed'
    END as status_name,
    COUNT(*) as count
FROM sites_pg_update_log
GROUP BY table_name, status
ORDER BY table_name, status;
```

## Configuration

Edit `config/sites-sync.php`:

```php
return [
    'poll_interval' => env('SITES_SYNC_POLL_INTERVAL', 5),
    'batch_size' => env('SITES_SYNC_BATCH_SIZE', 100),
    'max_retries' => env('SITES_SYNC_MAX_RETRIES', 3),
];
```

Or set in `.env`:
```env
SITES_SYNC_POLL_INTERVAL=5
SITES_SYNC_BATCH_SIZE=100
SITES_SYNC_MAX_RETRIES=3
```

## Running as a Service

### Using NSSM (Windows)

```powershell
nssm install SitesUpdateSync "C:\path\to\php.exe" "artisan sites:update-worker"
nssm set SitesUpdateSync AppDirectory "C:\path\to\project"
nssm start SitesUpdateSync
```

### Using Supervisor (Linux)

Create `/etc/supervisor/conf.d/sites-update-sync.conf`:
```ini
[program:sites-update-sync]
command=php /path/to/project/artisan sites:update-worker
directory=/path/to/project
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/project/storage/logs/sites-sync-worker.log
```

Then:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start sites-update-sync
```

## Important Notes

⚠️ **MySQL is READ-ONLY**: The sync system only performs SELECT operations on MySQL source tables. Updates are only made to the log table.

⚠️ **Trigger Exclusions**: The triggers exclude `synced_at` column changes to avoid infinite loops.

⚠️ **All Columns Synced**: Every column (except `synced_at`) is synchronized to PostgreSQL.

⚠️ **UPSERT Strategy**: The system uses INSERT or UPDATE based on whether the record exists in PostgreSQL.

## Files Reference

- **SQL Scripts:**
  - `codes/create-sites-update-log-table.sql` - Log table creation
  - `codes/create-sites-triggers.sql` - Trigger creation

- **Services:**
  - `app/Services/SitesUpdateSyncService.php` - Update sync logic
  - `app/Services/SitesSyncService.php` - Full sync logic
  - `app/Services/SitesSyncResult.php` - Result object

- **Commands:**
  - `app/Console/Commands/SitesUpdateSyncWorker.php` - Update worker
  - `app/Console/Commands/SitesSyncCommand.php` - Manual sync command

- **Testing/Monitoring:**
  - `codes/test-sites-trigger.php` - Test triggers
  - `codes/check-sites-sync-status.php` - Check status
  - `codes/start-sites-update-worker.ps1` - Start worker script

- **Configuration:**
  - `config/sites-sync.php` - Sync configuration
