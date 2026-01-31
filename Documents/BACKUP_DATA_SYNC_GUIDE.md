# Backup Data Sync Guide

## Overview
This guide covers syncing **10.8 million records** from the `alerts_all_data` backup table to PostgreSQL partitioned tables with safe deletion.

## Current Status
- **Source**: `alerts_all_data` table (MySQL)
- **Target**: PostgreSQL partitioned tables (`alerts_YYYY_MM_DD`)
- **Total Records**: 10,876,150 records
- **Date Range**: 2026-01-16 to 2026-01-27
- **Estimated Batches**: ~21,752 (at 500 records/batch)

## Command Usage

### Check Status
```bash
php artisan sync:backup-data --status
```

### Test Small Batch (Recommended First)
```bash
php artisan sync:backup-data --batch-size=100 --max-batches=5
```

### Production Sync (Continuous with Deletion)
```bash
php artisan sync:backup-data --continuous --batch-size=500 --delete-after-sync
```

### Resume from Specific ID
```bash
php artisan sync:backup-data --start-id=34500000 --continuous --batch-size=500 --delete-after-sync
```

## NSSM Service: AlertBackupSync

### Service Configuration
- **Name**: AlertBackupSync
- **Display Name**: Alert Backup Data Sync Worker (Temporary)
- **Command**: `php artisan sync:backup-data --continuous --batch-size=500 --delete-after-sync`
- **Start Type**: Manual (SERVICE_DEMAND_START)
- **Logs**: 
  - Output: `storage/logs/backup-sync-service.log`
  - Errors: `storage/logs/backup-sync-service-error.log`

### Start the Service
```powershell
nssm start AlertBackupSync
```

### Stop the Service
```powershell
nssm stop AlertBackupSync
```

### Monitor Progress
```powershell
Get-Content storage\logs\backup-sync-service.log -Tail 20 -Wait
```

## Safety Features

### ✅ Safe Deletion
- Records are **only deleted** from `alerts_all_data` **after successful sync** to PostgreSQL
- Failed syncs do **not** trigger deletion
- Deletion happens in chunks of 1,000 records

### ✅ Transaction Safety
- Each date group sync is wrapped in a PostgreSQL transaction
- Rollback on failure prevents partial data

### ✅ Error Handling
- Failed date groups are logged but don't stop processing
- Memory cleanup every 10 batches
- Detailed progress reporting

### ✅ Resume Capability
- Can resume from any ID using `--start-id`
- Progress tracking with percentage completion

## Performance Optimization

### Batch Size Recommendations
- **Small Test**: 10-100 records
- **Production**: 500-1000 records
- **High Performance**: 1000-2000 records (monitor memory)

### Estimated Performance
- **Speed**: ~30-50 records/second
- **Duration**: ~60-90 hours for 10.8M records
- **Memory**: Optimized with chunking and cleanup

## Monitoring Commands

### Service Status
```powershell
Get-Service AlertBackupSync
```

### Real-time Logs
```powershell
Get-Content storage\logs\backup-sync-service.log -Tail 50 -Wait
```

### Check Remaining Records
```bash
php artisan sync:backup-data --status
```

### PostgreSQL Partition Status
```bash
php artisan sync:partitioned --status
```

## Important Notes

### ⚠️ Production Considerations
1. **Run during low-traffic hours** for optimal performance
2. **Monitor disk space** - PostgreSQL partitions will grow
3. **Database connections** - Ensure sufficient connection limits
4. **Backup before starting** - Always backup before large operations

### 🔄 Process Flow
1. **Fetch** batch from `alerts_all_data` (500 records)
2. **Group** by extracted date from `receivedtime`
3. **Create** partition tables if needed
4. **Insert** to PostgreSQL partitions (with transaction)
5. **Delete** from `alerts_all_data` (only if sync successful)
6. **Repeat** until all records processed

### 📊 Progress Tracking
- Batch-by-batch progress with timing
- Percentage completion
- Records processed vs deleted counts
- Average processing speed
- Estimated time remaining

## Troubleshooting

### Service Won't Start
```powershell
# Check service status
nssm status AlertBackupSync

# Check logs
Get-Content storage\logs\backup-sync-service-error.log -Tail 20
```

### Slow Performance
- Reduce batch size: `--batch-size=250`
- Check database connections
- Monitor system resources

### Resume After Interruption
1. Check status: `php artisan sync:backup-data --status`
2. Note the last processed ID from logs
3. Resume: `php artisan sync:backup-data --start-id=LAST_ID --continuous --delete-after-sync`

## Completion
When sync is complete:
1. **Verify**: `php artisan sync:backup-data --status` shows 0 records
2. **Remove Service**: `nssm remove AlertBackupSync confirm`
3. **Clean Logs**: Archive or delete service logs
4. **Verify Partitions**: Check PostgreSQL partition record counts

---
**Created**: January 27, 2026  
**Purpose**: Temporary service for one-time backup data migration  
**Status**: Ready for production use