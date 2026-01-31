# MySQL to PostgreSQL Update Synchronization Worker

## Overview

The Update Sync Worker is a continuous synchronization service that monitors a MySQL update log table (`alert_pg_update_log`) and propagates changes to a PostgreSQL alerts table. The worker operates as a long-running Laravel console command that ensures near real-time data consistency between the two databases.

### Architecture

- **Java Application** writes/updates records in MySQL alerts table (source of truth)
- **MySQL alert_pg_update_log** tracks which alerts need syncing to PostgreSQL
- **Sync Worker** reads from MySQL alerts (read-only) and updates PostgreSQL alerts (target)
- **Worker** updates MySQL alert_pg_update_log to mark entries as processed

**Important**: The MySQL alerts table is READ-ONLY from the sync worker's perspective. All modifications to alerts are made by the Java application.

## Command Usage

### Basic Usage

```bash
php artisan sync:update-worker
```

### Command Options

```bash
php artisan sync:update-worker [options]
```

#### Available Options

| Option | Description | Default | Example |
|--------|-------------|---------|---------|
| `--poll-interval` | Seconds between polls when no entries found | 5 | `--poll-interval=10` |
| `--batch-size` | Maximum entries to process per batch | 100 | `--batch-size=50` |
| `--max-retries` | Maximum retries for failed operations | 3 | `--max-retries=5` |

### Usage Examples

**Run with default settings:**
```bash
php artisan sync:update-worker
```

**Run with custom poll interval (check every 10 seconds):**
```bash
php artisan sync:update-worker --poll-interval=10
```

**Run with smaller batch size for lower resource usage:**
```bash
php artisan sync:update-worker --batch-size=25
```

**Run with increased retry attempts:**
```bash
php artisan sync:update-worker --max-retries=5
```

**Combine multiple options:**
```bash
php artisan sync:update-worker --poll-interval=3 --batch-size=200 --max-retries=5
```

## Configuration

The sync worker can be configured via the `config/update-sync.php` configuration file.

### Configuration File

```php
<?php

return [
    // Default poll interval in seconds
    'poll_interval' => env('UPDATE_SYNC_POLL_INTERVAL', 5),
    
    // Default batch size for processing entries
    'batch_size' => env('UPDATE_SYNC_BATCH_SIZE', 100),
    
    // Maximum retry attempts for failed operations
    'max_retries' => env('UPDATE_SYNC_MAX_RETRIES', 3),
    
    // Retry backoff configuration
    'retry' => [
        'base_delay' => 1000,      // Base delay in milliseconds
        'max_delay' => 60000,      // Maximum delay in milliseconds
        'multiplier' => 2,         // Exponential backoff multiplier
    ],
];
```

### Environment Variables

You can override configuration values using environment variables in your `.env` file:

```env
# Update sync worker configuration
UPDATE_SYNC_POLL_INTERVAL=5
UPDATE_SYNC_BATCH_SIZE=100
UPDATE_SYNC_MAX_RETRIES=3
```

### Configuration Options Explained

- **poll_interval**: How long the worker waits (in seconds) between polls when no pending entries are found. Lower values provide faster sync but use more resources.

- **batch_size**: Maximum number of log entries to process in a single batch. Higher values improve throughput but use more memory.

- **max_retries**: Number of times to retry a failed operation before marking it as permanently failed. Applies to connection errors and query failures.

- **retry.base_delay**: Initial delay (in milliseconds) before the first retry attempt.

- **retry.max_delay**: Maximum delay (in milliseconds) between retry attempts, regardless of exponential backoff calculation.

- **retry.multiplier**: Exponential backoff multiplier. Delay calculation: `min(base_delay * (multiplier ^ attempt), max_delay)`

## Running the Worker

### Development

For development and testing, run the worker directly:

```bash
php artisan sync:update-worker
```

Press `Ctrl+C` to stop the worker gracefully.

### Production Deployment

For production, the worker should be managed by a process supervisor to ensure it runs continuously and restarts on failure.

#### Using Supervisor (Recommended)

Create a supervisor configuration file at `/etc/supervisor/conf.d/update-sync-worker.conf`:

```ini
[program:update-sync-worker]
process_name=%(program_name)s
command=php /path/to/your/app/artisan sync:update-worker
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/your/app/storage/logs/update-sync-worker.log
stopwaitsecs=60
```

Then reload supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start update-sync-worker
```

#### Using systemd

Create a systemd service file at `/etc/systemd/system/update-sync-worker.service`:

```ini
[Unit]
Description=MySQL to PostgreSQL Update Sync Worker
After=network.target mysql.service postgresql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/your/app
ExecStart=/usr/bin/php /path/to/your/app/artisan sync:update-worker
Restart=always
RestartSec=10
StandardOutput=append:/path/to/your/app/storage/logs/update-sync-worker.log
StandardError=append:/path/to/your/app/storage/logs/update-sync-worker.log

[Install]
WantedBy=multi-user.target
```

Then enable and start the service:

```bash
sudo systemctl daemon-reload
sudo systemctl enable update-sync-worker
sudo systemctl start update-sync-worker
```

## Monitoring

### Log Files

The worker logs all activities to Laravel's standard logging system. Check the logs at:

```bash
tail -f storage/logs/laravel.log
```

### Log Levels

The worker uses different log levels for different events:

- **INFO**: Normal operations (cycle start/complete, successful syncs)
- **WARNING**: Recoverable issues (retries, temporary failures)
- **ERROR**: Failures requiring attention (permanent failures, data errors)
- **CRITICAL**: System-level issues (database unavailable, out of memory)

### Key Log Messages

**Worker Startup:**
```
[INFO] Update sync worker started with poll_interval=5, batch_size=100, max_retries=3
```

**Processing Cycle:**
```
[INFO] Starting sync cycle with 15 pending entries
[INFO] Sync cycle complete: processed=15, failed=0, duration=2.34s
```

**Alert Sync:**
```
[INFO] Alert synced successfully: alert_id=123, duration=0.15s
[ERROR] Alert sync failed: alert_id=456, error="Alert not found in MySQL"
```

### Monitoring Metrics

Monitor these key metrics for system health:

1. **Pending Entry Count**: Number of unprocessed log entries
2. **Processing Rate**: Entries processed per second
3. **Failure Rate**: Percentage of failed sync operations
4. **Cycle Duration**: Time to process a batch
5. **Retry Count**: Number of operations requiring retries

### Health Checks

Check if the worker is running:

```bash
# Using supervisor
sudo supervisorctl status update-sync-worker

# Using systemd
sudo systemctl status update-sync-worker

# Check process directly
ps aux | grep "sync:update-worker"
```

Check pending entries in the database:

```sql
-- MySQL
SELECT COUNT(*) as pending_count 
FROM alert_pg_update_log 
WHERE status = 1;

-- Check for old pending entries (potential issues)
SELECT COUNT(*) as old_pending 
FROM alert_pg_update_log 
WHERE status = 1 
  AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR);
```

## Troubleshooting

### Common Issues

#### 1. Worker Not Processing Entries

**Symptoms**: Log entries remain in status=1, no processing activity in logs

**Possible Causes**:
- Worker not running
- Database connection issues
- Configuration errors

**Solutions**:
```bash
# Check if worker is running
ps aux | grep "sync:update-worker"

# Check database connections
php artisan tinker
>>> DB::connection('mysql')->getPdo();
>>> DB::connection('pgsql')->getPdo();

# Restart the worker
sudo supervisorctl restart update-sync-worker
```

#### 2. High Failure Rate

**Symptoms**: Many log entries with status=3, error messages in logs

**Possible Causes**:
- Alerts deleted from MySQL but log entries remain
- Schema mismatches between MySQL and PostgreSQL
- Network issues

**Solutions**:
```bash
# Check error messages in logs
grep "Alert sync failed" storage/logs/laravel.log | tail -20

# Verify alert exists in MySQL
mysql -e "SELECT * FROM alerts WHERE id=<alert_id>;"

# Check PostgreSQL schema
psql -c "\d alerts"
```

#### 3. Slow Processing

**Symptoms**: Long cycle durations, growing backlog of pending entries

**Possible Causes**:
- Batch size too small
- Database performance issues
- Network latency

**Solutions**:
```bash
# Increase batch size
php artisan sync:update-worker --batch-size=200

# Check database performance
# MySQL
SHOW PROCESSLIST;

# PostgreSQL
SELECT * FROM pg_stat_activity;

# Check network latency
ping <database-host>
```

#### 4. Memory Issues

**Symptoms**: Worker crashes, out of memory errors

**Possible Causes**:
- Batch size too large
- Memory leaks
- Large alert records

**Solutions**:
```bash
# Reduce batch size
php artisan sync:update-worker --batch-size=25

# Monitor memory usage
watch -n 1 'ps aux | grep sync:update-worker'

# Check PHP memory limit
php -i | grep memory_limit
```

#### 5. Connection Errors

**Symptoms**: "Connection refused" or "Connection timeout" errors

**Possible Causes**:
- Database server down
- Network issues
- Connection pool exhausted

**Solutions**:
```bash
# Test database connections
php artisan tinker
>>> DB::connection('mysql')->select('SELECT 1');
>>> DB::connection('pgsql')->select('SELECT 1');

# Check database server status
sudo systemctl status mysql
sudo systemctl status postgresql

# Verify connection settings in .env
cat .env | grep DB_
```

### Debug Mode

Enable debug logging for more detailed information:

```env
# .env
LOG_LEVEL=debug
```

Then restart the worker and check logs:

```bash
tail -f storage/logs/laravel.log
```

### Manual Testing

Test the sync process manually:

```bash
# Insert a test log entry
mysql -e "INSERT INTO alert_pg_update_log (alert_id, status) VALUES (123, 1);"

# Run worker for one cycle (stop with Ctrl+C after one cycle)
php artisan sync:update-worker --poll-interval=1

# Check if entry was processed
mysql -e "SELECT * FROM alert_pg_update_log WHERE alert_id=123;"

# Verify PostgreSQL was updated
psql -c "SELECT * FROM alerts WHERE id=123;"
```

### Getting Help

If issues persist:

1. Check Laravel logs: `storage/logs/laravel.log`
2. Check supervisor/systemd logs
3. Verify database connectivity and permissions
4. Review configuration in `config/update-sync.php`
5. Check for schema mismatches between MySQL and PostgreSQL

## Performance Tuning

### Optimizing Throughput

**Increase Batch Size**: Process more entries per cycle
```bash
php artisan sync:update-worker --batch-size=500
```

**Reduce Poll Interval**: Check for new entries more frequently
```bash
php artisan sync:update-worker --poll-interval=1
```

**Database Indexes**: Ensure proper indexes exist
```sql
-- MySQL
CREATE INDEX idx_status_created ON alert_pg_update_log(status, created_at);
CREATE INDEX idx_alert_id ON alert_pg_update_log(alert_id);

-- PostgreSQL
CREATE INDEX idx_alerts_id ON alerts(id);
```

### Optimizing Resource Usage

**Decrease Batch Size**: Use less memory
```bash
php artisan sync:update-worker --batch-size=25
```

**Increase Poll Interval**: Reduce CPU usage when idle
```bash
php artisan sync:update-worker --poll-interval=30
```

### Connection Pooling

Ensure connection pooling is configured in `config/database.php`:

```php
'mysql' => [
    // ...
    'options' => [
        PDO::ATTR_PERSISTENT => true,
    ],
],

'pgsql' => [
    // ...
    'options' => [
        PDO::ATTR_PERSISTENT => true,
    ],
],
```

## Security Considerations

1. **Database Permissions**: The worker requires:
   - MySQL: SELECT on `alerts`, SELECT/UPDATE on `alert_pg_update_log`
   - PostgreSQL: SELECT/INSERT/UPDATE on `alerts`

2. **Credentials**: Store database credentials securely in `.env` file with appropriate file permissions:
   ```bash
   chmod 600 .env
   ```

3. **Network**: Use encrypted connections for database access in production:
   ```env
   DB_SSLMODE=require
   ```

## Maintenance

### Cleaning Up Old Log Entries

Periodically clean up old processed log entries to prevent table bloat:

```sql
-- Delete entries older than 30 days with status=2 (completed)
DELETE FROM alert_pg_update_log 
WHERE status = 2 
  AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

Consider creating a scheduled task for this:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('db:query', [
        'DELETE FROM alert_pg_update_log WHERE status = 2 AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)'
    ])->daily();
}
```

### Monitoring Disk Space

Monitor disk space for log files:

```bash
du -sh storage/logs/
```

Configure log rotation in `config/logging.php`:

```php
'daily' => [
    'driver' => 'daily',
    'path' => storage_path('logs/laravel.log'),
    'level' => 'debug',
    'days' => 14,
],
```

## FAQ

**Q: Can I run multiple worker instances?**
A: Yes, but ensure they use different batch sizes or implement distributed locking to prevent duplicate processing.

**Q: What happens if the worker crashes mid-batch?**
A: Unprocessed entries remain in status=1 and will be picked up in the next cycle. Partially processed entries may need manual verification.

**Q: How do I stop the worker gracefully?**
A: Send SIGTERM signal (Ctrl+C or `supervisorctl stop`). The worker will complete the current batch before shutting down.

**Q: Can I modify the MySQL alerts table from the worker?**
A: No. The MySQL alerts table is READ-ONLY from the worker's perspective. Only the Java application should modify it.

**Q: What's the difference between status values?**
A: Status 1 = Pending, Status 2 = Completed successfully, Status 3 = Failed permanently after retries.

**Q: How do I handle schema changes?**
A: Update both MySQL and PostgreSQL schemas, then restart the worker. Ensure column names and types remain compatible.
