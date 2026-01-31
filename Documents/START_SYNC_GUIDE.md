# Alert Sync Process - Quick Start Guide

## Prerequisites

Before starting the sync, ensure:

1. ✅ **PostgreSQL tables created** - Done! (18 tables created)
2. ✅ **Roles and permissions created** - Done! (3 roles, 18 permissions)
3. ✅ **Superadmin user created** - Done! (superadmin@gmail.com / password)
4. ⚠️ **MySQL service running** - Need to start WAMP/MySQL
5. ⚠️ **PostgreSQL service running** - Should be running

## Option 1: Manual Sync (Recommended for First Time)

### Step 1: Start MySQL Service
Open WAMP control panel and start MySQL, or run:
```powershell
net start wampmysqld64
```

### Step 2: Run the Sync Starter Script
```powershell
.\codes\start-sync.ps1
```

This script will:
- Check MySQL and PostgreSQL connections
- Show current sync status
- Let you choose sync mode:
  - Single batch (10,000 records)
  - 10 batches (100,000 records)
  - Continuous (all records)

### Step 3: Monitor Progress
The script shows real-time progress with:
- Records synced
- Date groups processed
- Speed (records/second)
- Partition tables created

## Option 2: Windows Services with NSSM (Automated)

### Step 1: Check Current Services
```powershell
.\codes\check-services.ps1
```

### Step 2: Set Up Services (Run as Administrator)
```powershell
.\codes\setup-services.ps1
```

This creates 3 Windows services:
1. **AlertPortal** - Web interface at http://192.168.100.21:9000
2. **AlertInitialSync** - Syncs new alerts every 20 minutes
3. **AlertUpdateSync** - Syncs alert updates every 5 seconds

### Step 3: Manage Services
```powershell
# Check status
Get-Service Alert*

# Start all services
Get-Service Alert* | Start-Service

# Stop all services
Get-Service Alert* | Stop-Service

# View logs
Get-Content storage\logs\initial-sync-service.log -Tail 50 -Wait
```

## Manual Sync Commands

### Check Sync Status
```bash
php artisan sync:partitioned --status
```

### Sync Single Batch (10,000 records)
```bash
php artisan sync:partitioned
```

### Sync Multiple Batches
```bash
php artisan sync:partitioned --max-batches=10
```

### Continuous Sync (All Records)
```bash
php artisan sync:partitioned --continuous
```

### Custom Batch Size
```bash
php artisan sync:partitioned --batch-size=5000 --max-batches=20
```

## Monitoring Sync Progress

### Check PostgreSQL Partitions
```bash
php codes/check_sync.php
```

### View Sync Logs
```bash
# Laravel logs
Get-Content storage\logs\laravel.log -Tail 50 -Wait

# Service logs (if using NSSM)
Get-Content storage\logs\initial-sync-service.log -Tail 50 -Wait
```

### Database Queries

**Check unsynced records:**
```sql
-- MySQL
SELECT COUNT(*) FROM alerts WHERE synced_at IS NULL;
```

**Check partition tables:**
```sql
-- PostgreSQL
SELECT tablename, pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) AS size
FROM pg_tables 
WHERE schemaname = 'public' AND tablename LIKE 'alerts_%'
ORDER BY tablename;
```

## Troubleshooting

### MySQL Connection Failed
- Start WAMP/MySQL service
- Check `.env` file for correct MySQL credentials
- Test connection: `php artisan tinker --execute="DB::connection('mysql')->getPdo();"`

### PostgreSQL Connection Failed
- Start PostgreSQL service
- Check `.env` file for correct PostgreSQL credentials
- Test connection: `php artisan tinker --execute="DB::connection('pgsql')->getPdo();"`

### Sync Stuck or Slow
- Check available memory: `php artisan sync:partitioned --batch-size=5000`
- Monitor system resources
- Check logs for errors

### Partition Tables Not Created
- Ensure PostgreSQL migrations ran successfully
- Check partition registry: `SELECT * FROM partition_registry;`
- Manually create partitions if needed

## Reset Sync (Start Over)

To reset and re-sync all records:

```sql
-- MySQL: Clear sync markers
UPDATE alerts SET synced_at = NULL, sync_batch_id = NULL WHERE synced_at IS NOT NULL;
TRUNCATE TABLE sync_batches;

-- PostgreSQL: Clear synced data
TRUNCATE TABLE alerts CASCADE;
DELETE FROM partition_registry;
DELETE FROM sync_logs;
```

Then run sync again:
```bash
php artisan sync:partitioned --continuous
```

## Performance Tips

1. **Batch Size**: Default 10,000 is optimal for most systems
2. **Off-Peak Hours**: Sync runs faster during configured off-peak hours (10 PM - 6 AM)
3. **Memory**: Increase PHP memory limit if needed: `ini_set('memory_limit', '1G');`
4. **Continuous Mode**: Best for initial sync of large datasets
5. **Service Mode**: Best for ongoing automated syncing

## Stopping Sync

### Quick Stop
```powershell
.\codes\stop-sync.ps1
```

### Manual Stop
- Press **Ctrl+C** in the terminal
- Or stop services: `Get-Service Alert* | Stop-Service`

**See STOP_SYNC_GUIDE.md for detailed instructions**

## Next Steps

After sync completes:
1. Verify data in PostgreSQL partitions
2. Set up NSSM services for automated syncing
3. Access web portal at http://192.168.100.21:9000
4. Log in with superadmin@gmail.com / password
5. Monitor sync status from the dashboard
