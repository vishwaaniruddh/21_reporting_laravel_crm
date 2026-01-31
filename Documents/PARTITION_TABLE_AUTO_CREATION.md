# Partition Table Auto-Creation

## How It Works

Partition tables (e.g., `alerts_2026_01_10`) are created **automatically** by the sync worker when processing alerts.

## The Sync Process

### 1. Normal Flow (Automatic)
When alerts are created in MySQL through the normal application flow:
1. Alert is inserted into MySQL `alerts` table
2. A trigger or application code creates an entry in `alert_pg_update_log` table with `status=1` (pending)
3. The UpdateSyncWorker (running continuously) polls `alert_pg_update_log` for pending entries
4. Worker processes each entry:
   - Reads alert data from MySQL `alerts` table
   - Determines the partition table name from `receivedtime` (e.g., `alerts_2026_01_10`)
   - **Creates the partition table if it doesn't exist**
   - Inserts/updates the alert in PostgreSQL partition table
   - Marks the log entry as processed (`status=2`)

### 2. Manual Alert Creation
If you manually insert an alert into MySQL `alerts` table:
- **The partition table will NOT be created automatically**
- You must also create an entry in `alert_pg_update_log` to trigger the sync

## Manual Sync Trigger

If you manually create an alert and need to sync it:

```sql
-- Insert into alert_pg_update_log to trigger sync
INSERT INTO alert_pg_update_log (alert_id, status, created_at, updated_at)
VALUES (YOUR_ALERT_ID, 1, NOW(), NOW());
```

Or use this PHP script:

```php
<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$alertId = 673304; // Your alert ID

DB::connection('mysql')->table('alert_pg_update_log')->insert([
    'alert_id' => $alertId,
    'status' => 1, // 1 = pending
    'created_at' => now(),
    'updated_at' => now(),
]);

echo "Sync triggered for alert ID: $alertId\n";
```

## Verification

Check if a partition table exists:

```sql
-- PostgreSQL
SELECT EXISTS (
    SELECT FROM information_schema.tables 
    WHERE table_schema = 'public' 
    AND table_name = 'alerts_2026_01_10'
);
```

Check if an alert was synced:

```sql
-- Check MySQL sync status
SELECT synced_at, sync_batch_id 
FROM alerts 
WHERE id = YOUR_ALERT_ID;

-- Check PostgreSQL partition table
SELECT * FROM alerts_2026_01_10 
WHERE id = YOUR_ALERT_ID;
```

## Sync Worker Status

The UpdateSyncWorker should be running continuously. Check if it's running:

```bash
# Windows
tasklist | findstr php

# Check logs
tail -f storage/logs/laravel.log | grep "Update sync"
```

Start the worker if not running:

```bash
php artisan sync:update-worker
```

## Key Points

✅ **Partition tables are created automatically** when the sync worker processes alerts
✅ **No manual table creation needed** for normal operations
✅ **Manual alerts require** an entry in `alert_pg_update_log` to trigger sync
✅ **The sync worker must be running** for automatic table creation
✅ **Table naming convention**: `alerts_YYYY_MM_DD` based on `receivedtime`

## Related Files

- `app/Console/Commands/UpdateSyncWorker.php` - The sync worker command
- `app/Services/AlertSyncService.php` - Handles individual alert syncing
- `app/Services/PartitionManager.php` - Creates partition tables
- `database/migrations/*_create_alert_pg_update_log_table.php` - Update log table

## Troubleshooting

**Problem**: Partition table not created after inserting alert

**Solution**: 
1. Check if sync worker is running
2. Check if entry exists in `alert_pg_update_log` with `status=1`
3. Check Laravel logs for sync errors
4. Manually trigger sync by inserting into `alert_pg_update_log`

**Problem**: Alert synced but `synced_at` is NULL in MySQL

**Solution**: This is expected! The UpdateSyncWorker uses `alert_pg_update_log` for tracking, not the `synced_at` column in the `alerts` table. The `synced_at` column is used by the initial sync job, not the update sync worker.
