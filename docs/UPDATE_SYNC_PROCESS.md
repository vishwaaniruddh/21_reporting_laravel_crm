# Update Sync Process - How It Works

## Overview

The Update Sync Worker is designed to **UPDATE existing records** in PostgreSQL when they change in MySQL. It does NOT require a full re-sync or deletion of existing data.

## Key Principles

### ✅ What the Worker DOES

1. **Reads from MySQL alerts** (READ-ONLY)
   - Fetches alert data when `alert_pg_update_log` indicates a change
   - Never modifies MySQL alerts table

2. **Updates PostgreSQL alerts** (INSERT/UPDATE only)
   - Uses `updateOrCreate()` which:
     - **UPDATES** the record if it exists (most common case)
     - **INSERTS** a new record if it doesn't exist (rare case)
   - Sets `updated_at` timestamp to current time
   - Preserves all existing data

3. **Marks log entries as processed**
   - Updates `alert_pg_update_log` status to 2 (completed) or 3 (failed)
   - Tracks processing history

### ❌ What the Worker NEVER DOES

1. **Never deletes data from PostgreSQL alerts**
2. **Never truncates PostgreSQL alerts**
3. **Never modifies MySQL alerts** (read-only)
4. **Never requires full re-sync**

## Data Flow

```
Java App → MySQL alerts (INSERT/UPDATE)
         ↓
Java App → MySQL alert_pg_update_log (INSERT status=1)
         ↓
Worker   → MySQL alerts (SELECT - read alert data)
         ↓
Worker   → PostgreSQL alerts (UPDATE/INSERT - sync changes)
         ↓
Worker   → MySQL alert_pg_update_log (UPDATE status=2)
```

## How Updates Work

### Scenario 1: Alert Updated in MySQL

```sql
-- Java app updates alert in MySQL
UPDATE alerts SET status='resolved' WHERE id=123;

-- Java app creates log entry
INSERT INTO alert_pg_update_log (alert_id, status) VALUES (123, 1);

-- Worker processes:
-- 1. Reads alert 123 from MySQL (SELECT)
-- 2. Updates alert 123 in PostgreSQL (UPDATE)
-- 3. Marks log entry as processed (UPDATE status=2)
```

**Result**: PostgreSQL alert 123 is UPDATED with new values. All other alerts remain unchanged.

### Scenario 2: New Alert Created in MySQL

```sql
-- Java app creates new alert in MySQL
INSERT INTO alerts (...) VALUES (...); -- id=456

-- Java app creates log entry
INSERT INTO alert_pg_update_log (alert_id, status) VALUES (456, 1);

-- Worker processes:
-- 1. Reads alert 456 from MySQL (SELECT)
-- 2. Inserts alert 456 in PostgreSQL (INSERT)
-- 3. Marks log entry as processed (UPDATE status=2)
```

**Result**: PostgreSQL gets a new alert 456. All existing alerts remain unchanged.

## Running the Worker

### Start the Worker

```bash
php artisan sync:update-worker --poll-interval=5 --batch-size=100
```

### Worker Options

- `--poll-interval=5`: Check for updates every 5 seconds
- `--batch-size=100`: Process up to 100 updates per cycle
- `--max-retries=3`: Retry failed operations up to 3 times

### Monitoring

The worker logs:
- Each processing cycle (how many updates processed)
- Individual alert updates (success/failure)
- Performance metrics (processing time)
- Errors with full context

Check logs:
```bash
tail -f storage/logs/laravel.log
```

## Maintenance Scripts

### Safe: Clear Sync Metadata Only

Use this to reset sync state WITHOUT losing data:

```bash
php clear_sync_metadata.php
```

This clears:
- ✅ Sync logs and tracking tables
- ✅ Error queues
- ✅ Configuration status
- ❌ Does NOT touch alerts data
- ❌ Does NOT touch MySQL

### Dangerous: Clear All Data

⚠️ **WARNING**: Only use this for testing or fresh setup!

```bash
php clear_postgres.php
```

This will:
- ❌ DELETE ALL alerts from PostgreSQL
- ❌ DELETE ALL sites from PostgreSQL
- ❌ Require full re-sync of all data

**You typically DON'T want to do this in production!**

## Common Scenarios

### "I have enormous data in PostgreSQL alerts"

✅ **Good news**: The update worker will only UPDATE changed records, not re-sync everything.

You should:
1. Keep your existing PostgreSQL alerts data
2. Start the update worker
3. It will only update alerts that have entries in `alert_pg_update_log`

### "I want to reset sync state but keep my data"

Use the safe script:
```bash
php clear_sync_metadata.php
```

This resets tracking without touching your alerts.

### "I want to test from scratch"

Only for testing:
```bash
php clear_postgres.php  # Will ask for confirmation
```

Then run initial sync to populate data.

## Troubleshooting

### Worker not updating records

Check:
1. Is `alert_pg_update_log` being populated by Java app?
   ```sql
   SELECT COUNT(*) FROM alert_pg_update_log WHERE status=1;
   ```

2. Are there errors in the log?
   ```bash
   tail -f storage/logs/laravel.log | grep ERROR
   ```

3. Is the worker running?
   ```bash
   ps aux | grep update-worker
   ```

### Records not syncing

Check:
1. Does the alert exist in MySQL?
   ```sql
   SELECT * FROM alerts WHERE id=<alert_id>;
   ```

2. Check the log entry status:
   ```sql
   SELECT * FROM alert_pg_update_log WHERE alert_id=<alert_id>;
   ```

3. If status=3 (failed), check error_message column

### Performance issues

Adjust worker settings:
- Increase `--batch-size` for higher throughput
- Decrease `--poll-interval` for lower latency
- Monitor system resources (CPU, memory, connections)

## Database Constraints

### MySQL (Source)

- **alerts table**: READ-ONLY from worker perspective
  - Worker only performs SELECT queries
  - Java app performs INSERT/UPDATE

- **alert_pg_update_log table**: READ and UPDATE
  - Java app performs INSERT (creates log entries)
  - Worker performs SELECT (reads pending) and UPDATE (marks processed)

### PostgreSQL (Target)

- **alerts table**: INSERT and UPDATE only
  - Worker performs INSERT (new alerts) and UPDATE (changed alerts)
  - Worker NEVER performs DELETE or TRUNCATE

## Best Practices

1. **Run worker as a service**
   - Use systemd, supervisor, or similar
   - Ensure automatic restart on failure

2. **Monitor worker health**
   - Check logs regularly
   - Set up alerts for errors
   - Monitor processing lag

3. **Don't delete data unnecessarily**
   - Use `clear_sync_metadata.php` for resets
   - Only use `clear_postgres.php` for testing

4. **Tune performance**
   - Adjust batch size based on load
   - Monitor database connections
   - Scale horizontally if needed (multiple workers)

5. **Handle errors gracefully**
   - Worker will retry transient failures
   - Failed entries are marked in log
   - Can be retried manually if needed
