# Date-Partitioned Alerts Migration Guide

## Table of Contents

1. [Overview](#overview)
2. [Pre-Migration Checklist](#pre-migration-checklist)
3. [Migration Strategies](#migration-strategies)
4. [Step-by-Step Migration Process](#step-by-step-migration-process)
5. [Historical Data Backfill](#historical-data-backfill)
6. [Validation and Testing](#validation-and-testing)
7. [Rollback Procedures](#rollback-procedures)
8. [Post-Migration Tasks](#post-migration-tasks)
9. [Troubleshooting](#troubleshooting)

## Overview

This guide provides detailed instructions for migrating from a single PostgreSQL `alerts` table to a date-partitioned table structure. The migration can be performed with minimal downtime using parallel operation strategies.

### Migration Goals

- ✅ Transition to date-partitioned tables without data loss
- ✅ Maintain system availability during migration
- ✅ Preserve all existing functionality
- ✅ Enable rollback if issues occur
- ✅ Validate data integrity throughout the process

### Migration Timeline

| Phase | Duration | Downtime |
|-------|----------|----------|
| Preparation | 1-2 hours | None |
| Parallel Operation Setup | 30 minutes | None |
| Historical Data Backfill | 2-8 hours | None |
| Validation | 1-2 hours | None |
| Cutover | 15 minutes | Optional |
| Post-Migration | 1 hour | None |

**Total Estimated Time:** 6-14 hours (depending on data volume)
**Required Downtime:** 0-15 minutes (optional)

## Pre-Migration Checklist

### 1. System Requirements

- [ ] PostgreSQL 14+ installed and running
- [ ] MySQL 8+ with read access to alerts table
- [ ] Laravel 10+ application
- [ ] Sufficient disk space (estimate: 1.5x current alerts table size)
- [ ] Database backup completed
- [ ] Maintenance window scheduled (optional)

### 2. Backup Current System

```bash
# Backup PostgreSQL database
pg_dump -h localhost -U postgres -d your_database > backup_before_migration.sql

# Backup MySQL alerts table (optional, as it's read-only)
mysqldump -h localhost -u root -p your_database alerts > mysql_alerts_backup.sql

# Backup Laravel application
tar -czf laravel_backup_$(date +%Y%m%d).tar.gz /path/to/laravel/app
```

### 3. Verify Current State

```sql
-- Check current alerts table size
SELECT 
    pg_size_pretty(pg_total_relation_size('alerts')) as total_size,
    COUNT(*) as record_count
FROM alerts;

-- Check date range of existing data
SELECT 
    MIN(receivedtime) as earliest_alert,
    MAX(receivedtime) as latest_alert,
    COUNT(DISTINCT DATE(receivedtime)) as unique_dates
FROM alerts;

-- Check for any data quality issues
SELECT 
    COUNT(*) as null_receivedtime_count
FROM alerts 
WHERE receivedtime IS NULL;
```

### 4. Performance Baseline

```sql
-- Measure current query performance
EXPLAIN ANALYZE
SELECT * FROM alerts 
WHERE receivedtime BETWEEN '2026-01-01' AND '2026-01-31'
AND severity = 'high';

-- Record execution time for comparison
```

### 5. Notify Stakeholders

- [ ] Inform users of upcoming migration
- [ ] Schedule maintenance window (if needed)
- [ ] Prepare rollback communication plan
- [ ] Assign migration team roles

## Migration Strategies

### Strategy 1: Parallel Operation (Recommended)

**Best for:** Production systems requiring zero downtime

**Approach:**
1. Deploy partition sync system alongside existing system
2. Start syncing new data to partitions
3. Backfill historical data in background
4. Validate data consistency
5. Switch queries to partition router
6. Deprecate old single table

**Pros:**
- Zero downtime
- Safe rollback at any point
- Gradual validation

**Cons:**
- Longer migration period
- Temporary storage overhead
- More complex coordination

### Strategy 2: Maintenance Window Migration

**Best for:** Development/staging environments or systems with flexible downtime

**Approach:**
1. Schedule maintenance window
2. Stop all writes to alerts table
3. Backfill all historical data to partitions
4. Switch to partition system
5. Resume operations

**Pros:**
- Simpler process
- Faster completion
- Clear cutover point

**Cons:**
- Requires downtime
- Higher risk
- Less flexibility

### Strategy 3: Hybrid Approach

**Best for:** Systems with predictable low-traffic periods

**Approach:**
1. Deploy partition system during business hours
2. Sync recent data (last 30 days)
3. During low-traffic period, backfill remaining historical data
4. Validate and cutover

**Pros:**
- Minimal downtime
- Faster than full parallel
- Manageable risk

**Cons:**
- Requires timing coordination
- Some downtime needed

## Step-by-Step Migration Process

### Phase 1: Preparation

#### Step 1.1: Deploy Partition System Code

```bash
# Pull latest code with partition system
git pull origin main

# Install dependencies
composer install

# Run migrations
php artisan migrate

# Verify partition_registry table exists
php artisan tinker
>>> DB::connection('pgsql')->table('partition_registry')->count();
```

#### Step 1.2: Configure Partition Sync

```env
# Add to .env
PARTITION_SYNC_ENABLED=true
PARTITION_SYNC_BATCH_SIZE=1000
PARTITION_SYNC_SCHEDULE="0 * * * *"
PARTITION_RETENTION_DAYS=90
```

#### Step 1.3: Test Partition Creation

```bash
# Test creating a partition manually
php artisan tinker

>>> use App\Services\PartitionManager;
>>> use Carbon\Carbon;
>>> $manager = app(PartitionManager::class);
>>> $date = Carbon::today();
>>> $result = $manager->createPartition($date);
>>> echo $result ? "Success" : "Failed";
```

### Phase 2: Parallel Operation Setup

#### Step 2.1: Enable Dual-Write Mode (Optional)

If you want to write to both old and new tables during migration:

```php
// In your sync service or controller
public function syncAlert($alertData)
{
    // Write to old single table
    DB::connection('pgsql')->table('alerts')->insert($alertData);
    
    // Also write to partition table
    $partitionSync = app(DateGroupedSyncService::class);
    $partitionSync->syncSingleAlert($alertData);
}
```

#### Step 2.2: Start Syncing New Data

```bash
# Start partition sync for new data
php artisan sync:partitioned --start-from=$(php artisan db:query "SELECT MAX(id) FROM alerts")

# Verify new data is being synced
php artisan tinker
>>> DB::connection('pgsql')->table('partition_registry')->get();
```

#### Step 2.3: Monitor Sync Progress

```bash
# Check sync logs
tail -f storage/logs/laravel.log | grep "Partition"

# Check partition creation
php artisan tinker
>>> use App\Models\PartitionRegistry;
>>> PartitionRegistry::orderBy('created_at', 'desc')->take(5)->get();
```

### Phase 3: Historical Data Backfill

#### Step 3.1: Analyze Historical Data

```sql
-- Determine date range for backfill
SELECT 
    DATE(MIN(receivedtime)) as start_date,
    DATE(MAX(receivedtime)) as end_date,
    COUNT(*) as total_records,
    COUNT(DISTINCT DATE(receivedtime)) as total_days
FROM alerts;
```

#### Step 3.2: Create Backfill Script

Create `database/scripts/backfill_partitions.php`:

```php
<?php

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\DateGroupedSyncService;
use App\Services\PartitionManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

$startDate = Carbon::parse('2025-01-01'); // Adjust as needed
$endDate = Carbon::today();
$batchSize = 5000;

$syncService = app(DateGroupedSyncService::class);
$partitionManager = app(PartitionManager::class);

echo "Starting historical data backfill...\n";
echo "Date range: {$startDate->toDateString()} to {$endDate->toDateString()}\n";

$currentDate = $startDate->copy();
$totalRecords = 0;
$totalPartitions = 0;

while ($currentDate <= $endDate) {
    echo "\nProcessing date: {$currentDate->toDateString()}\n";
    
    // Ensure partition exists
    $partitionManager->ensurePartitionExists($currentDate);
    
    // Get alerts for this date from old table
    $alerts = DB::connection('pgsql')
        ->table('alerts')
        ->whereDate('receivedtime', $currentDate)
        ->orderBy('id')
        ->get();
    
    if ($alerts->isEmpty()) {
        echo "  No alerts for this date, skipping...\n";
        $currentDate->addDay();
        continue;
    }
    
    echo "  Found {$alerts->count()} alerts\n";
    
    // Insert into partition table
    $partitionTable = $partitionManager->getPartitionTableName($currentDate);
    
    foreach ($alerts->chunk($batchSize) as $chunk) {
        DB::connection('pgsql')->table($partitionTable)->insert($chunk->toArray());
        echo "  Inserted {$chunk->count()} records\n";
    }
    
    // Update partition registry
    DB::connection('pgsql')
        ->table('partition_registry')
        ->where('table_name', $partitionTable)
        ->update([
            'record_count' => $alerts->count(),
            'last_synced_at' => now()
        ]);
    
    $totalRecords += $alerts->count();
    $totalPartitions++;
    
    echo "  ✓ Completed {$currentDate->toDateString()}\n";
    
    $currentDate->addDay();
}

echo "\n=== Backfill Complete ===\n";
echo "Total partitions created: {$totalPartitions}\n";
echo "Total records migrated: {$totalRecords}\n";
```

#### Step 3.3: Run Backfill

```bash
# Run backfill script
php database/scripts/backfill_partitions.php

# Or use artisan command if available
php artisan partition:backfill --start-date=2025-01-01 --end-date=2026-01-09

# Monitor progress
watch -n 5 'php artisan tinker --execute="echo App\Models\PartitionRegistry::sum(\"record_count\");"'
```

#### Step 3.4: Verify Backfill Completion

```sql
-- Compare record counts
SELECT 
    (SELECT COUNT(*) FROM alerts) as old_table_count,
    (SELECT SUM(record_count) FROM partition_registry) as partition_total_count,
    (SELECT COUNT(*) FROM alerts) - (SELECT SUM(record_count) FROM partition_registry) as difference;

-- Check for missing dates
WITH date_series AS (
    SELECT generate_series(
        (SELECT MIN(DATE(receivedtime)) FROM alerts),
        (SELECT MAX(DATE(receivedtime)) FROM alerts),
        '1 day'::interval
    )::date as date
),
partition_dates AS (
    SELECT partition_date FROM partition_registry
)
SELECT ds.date as missing_date
FROM date_series ds
LEFT JOIN partition_dates pd ON ds.date = pd.partition_date
WHERE pd.partition_date IS NULL
AND EXISTS (SELECT 1 FROM alerts WHERE DATE(receivedtime) = ds.date);
```

### Phase 4: Validation and Testing

#### Step 4.1: Data Integrity Validation

```php
// Create validation script: database/scripts/validate_migration.php
<?php

require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PartitionQueryRouter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

echo "=== Migration Validation ===\n\n";

// Test 1: Record count comparison
echo "Test 1: Record Count Comparison\n";
$oldCount = DB::connection('pgsql')->table('alerts')->count();
$newCount = DB::connection('pgsql')->table('partition_registry')->sum('record_count');
$match = $oldCount === $newCount;
echo "  Old table: {$oldCount}\n";
echo "  Partitions: {$newCount}\n";
echo "  Status: " . ($match ? "✓ PASS" : "✗ FAIL") . "\n\n";

// Test 2: Sample data comparison
echo "Test 2: Sample Data Comparison\n";
$sampleIds = DB::connection('pgsql')->table('alerts')->inRandomOrder()->limit(100)->pluck('id');
$router = app(PartitionQueryRouter::class);

$mismatches = 0;
foreach ($sampleIds as $id) {
    $oldRecord = DB::connection('pgsql')->table('alerts')->where('id', $id)->first();
    $date = Carbon::parse($oldRecord->receivedtime);
    $partitionTable = 'alerts_' . $date->format('Y_m_d');
    $newRecord = DB::connection('pgsql')->table($partitionTable)->where('id', $id)->first();
    
    if (!$newRecord || $oldRecord->alert_type !== $newRecord->alert_type) {
        $mismatches++;
    }
}
echo "  Samples checked: " . count($sampleIds) . "\n";
echo "  Mismatches: {$mismatches}\n";
echo "  Status: " . ($mismatches === 0 ? "✓ PASS" : "✗ FAIL") . "\n\n";

// Test 3: Date range query comparison
echo "Test 3: Date Range Query Comparison\n";
$startDate = Carbon::today()->subDays(7);
$endDate = Carbon::today();

$oldResults = DB::connection('pgsql')
    ->table('alerts')
    ->whereBetween('receivedtime', [$startDate, $endDate])
    ->count();

$newResults = $router->queryDateRange($startDate, $endDate)->count();

echo "  Old query: {$oldResults}\n";
echo "  New query: {$newResults}\n";
echo "  Status: " . ($oldResults === $newResults ? "✓ PASS" : "✗ FAIL") . "\n\n";

echo "=== Validation Complete ===\n";
```

```bash
# Run validation
php database/scripts/validate_migration.php
```

#### Step 4.2: Performance Testing

```sql
-- Test query performance on partitions
EXPLAIN ANALYZE
SELECT * FROM alerts_2026_01_08
WHERE severity = 'high'
AND alert_type = 'hardware_failure';

-- Compare with old table
EXPLAIN ANALYZE
SELECT * FROM alerts
WHERE receivedtime BETWEEN '2026-01-08' AND '2026-01-09'
AND severity = 'high'
AND alert_type = 'hardware_failure';
```

#### Step 4.3: Application Testing

```bash
# Run application tests
php artisan test

# Run specific partition tests
php artisan test --filter=Partition

# Test API endpoints
curl -X GET "http://localhost:8000/api/reports/partitioned/query?start_date=2026-01-08&end_date=2026-01-10"
```

### Phase 5: Cutover

#### Step 5.1: Update Application Code

```php
// Update ReportService to use PartitionQueryRouter
// This should already be done in task 12, but verify:

// Before (old code):
public function getAlerts($startDate, $endDate, $filters = [])
{
    return DB::connection('pgsql')
        ->table('alerts')
        ->whereBetween('receivedtime', [$startDate, $endDate])
        ->where($filters)
        ->get();
}

// After (new code):
public function getAlerts($startDate, $endDate, $filters = [])
{
    $router = app(PartitionQueryRouter::class);
    return $router->queryDateRange($startDate, $endDate, $filters);
}
```

#### Step 5.2: Switch Query Routing

```php
// Update config/database.php or create feature flag
'features' => [
    'use_partitioned_queries' => env('USE_PARTITIONED_QUERIES', true),
],

// In your services:
if (config('features.use_partitioned_queries')) {
    // Use partition router
} else {
    // Use old single table
}
```

#### Step 5.3: Enable Partition Queries

```env
# Update .env
USE_PARTITIONED_QUERIES=true
```

```bash
# Clear config cache
php artisan config:clear
php artisan cache:clear

# Restart application
php artisan optimize
```

#### Step 5.4: Monitor Cutover

```bash
# Monitor application logs
tail -f storage/logs/laravel.log

# Monitor database queries
# In PostgreSQL:
SELECT pid, query, state, query_start 
FROM pg_stat_activity 
WHERE datname = 'your_database' 
AND query LIKE '%alerts_%';

# Check for errors
grep -i error storage/logs/laravel.log | tail -20
```

### Phase 6: Deprecate Old Table

#### Step 6.1: Verify Partition System Stability

```bash
# Run for 24-48 hours with partition system
# Monitor:
# - Query performance
# - Error rates
# - Data consistency
# - User feedback
```

#### Step 6.2: Rename Old Table (Don't Drop Yet)

```sql
-- Rename old table as backup
ALTER TABLE alerts RENAME TO alerts_legacy_backup;

-- Update any remaining references
-- (Should be none if cutover was complete)
```

#### Step 6.3: Schedule Old Table Removal

```sql
-- After 30 days of successful operation, drop old table
-- DROP TABLE alerts_legacy_backup;

-- Or export for archival
COPY alerts_legacy_backup TO '/tmp/alerts_legacy_export.csv' CSV HEADER;
```

## Historical Data Backfill

### Backfill Strategies

#### Strategy A: Full Backfill (All Historical Data)

```bash
# Backfill all data from beginning
php artisan partition:backfill --start-date=2020-01-01 --end-date=2026-01-09

# Pros: Complete data migration
# Cons: Time-consuming, resource-intensive
```

#### Strategy B: Rolling Window (Recent Data Only)

```bash
# Backfill last 90 days only
php artisan partition:backfill --start-date=$(date -d '90 days ago' +%Y-%m-%d) --end-date=$(date +%Y-%m-%d)

# Pros: Faster, less resource usage
# Cons: Old data remains in single table
```

#### Strategy C: Incremental Backfill

```bash
# Backfill in chunks
for month in {1..12}; do
    php artisan partition:backfill --start-date=2025-${month}-01 --end-date=2025-${month}-31
    sleep 300  # Wait 5 minutes between months
done

# Pros: Controlled resource usage
# Cons: Longer total time
```

### Backfill Performance Optimization

```php
// Optimize backfill script
DB::connection('pgsql')->disableQueryLog();
DB::connection('pgsql')->statement('SET synchronous_commit = OFF');

// Use COPY for faster inserts
DB::connection('pgsql')->statement("
    COPY {$partitionTable} 
    FROM '/tmp/alerts_data.csv' 
    WITH (FORMAT csv, HEADER true)
");

// Re-enable after backfill
DB::connection('pgsql')->statement('SET synchronous_commit = ON');
```

### Backfill Monitoring

```sql
-- Create monitoring view
CREATE VIEW backfill_progress AS
SELECT 
    pr.partition_date,
    pr.record_count as partition_count,
    COUNT(a.id) as source_count,
    pr.record_count - COUNT(a.id) as difference,
    CASE 
        WHEN pr.record_count = COUNT(a.id) THEN 'Complete'
        WHEN pr.record_count > 0 THEN 'Partial'
        ELSE 'Not Started'
    END as status
FROM partition_registry pr
LEFT JOIN alerts a ON DATE(a.receivedtime) = pr.partition_date
GROUP BY pr.partition_date, pr.record_count
ORDER BY pr.partition_date DESC;

-- Check progress
SELECT * FROM backfill_progress WHERE status != 'Complete';
```

## Validation and Testing

### Validation Checklist

- [ ] Record counts match between old and new tables
- [ ] Sample data comparison shows no discrepancies
- [ ] All date ranges have corresponding partitions
- [ ] No NULL receivedtime values in partitions
- [ ] Indexes exist on all partition tables
- [ ] Partition metadata is accurate
- [ ] Query performance meets expectations
- [ ] API endpoints return correct data
- [ ] Application tests pass
- [ ] No errors in logs

### Automated Validation Script

```bash
#!/bin/bash
# validate_migration.sh

echo "=== Automated Migration Validation ==="

# Test 1: Record count
echo "Checking record counts..."
OLD_COUNT=$(psql -U postgres -d your_database -t -c "SELECT COUNT(*) FROM alerts")
NEW_COUNT=$(psql -U postgres -d your_database -t -c "SELECT SUM(record_count) FROM partition_registry")

if [ "$OLD_COUNT" -eq "$NEW_COUNT" ]; then
    echo "✓ Record counts match: $OLD_COUNT"
else
    echo "✗ Record count mismatch: Old=$OLD_COUNT, New=$NEW_COUNT"
    exit 1
fi

# Test 2: Partition count
echo "Checking partition count..."
PARTITION_COUNT=$(psql -U postgres -d your_database -t -c "SELECT COUNT(*) FROM partition_registry")
echo "✓ Total partitions: $PARTITION_COUNT"

# Test 3: Application health
echo "Checking application health..."
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/api/sync/partitions)

if [ "$HTTP_CODE" -eq "200" ]; then
    echo "✓ API endpoint responding"
else
    echo "✗ API endpoint error: HTTP $HTTP_CODE"
    exit 1
fi

echo "=== Validation Complete ==="
```

## Rollback Procedures

### Rollback Scenarios

#### Scenario 1: Rollback During Parallel Operation

**Situation:** Issues discovered before cutover

**Steps:**
1. Stop partition sync
2. Continue using old single table
3. Investigate and fix issues
4. Restart migration when ready

```bash
# Stop partition sync
php artisan schedule:clear

# Disable partition queries
# In .env:
USE_PARTITIONED_QUERIES=false

# Clear cache
php artisan config:clear
```

#### Scenario 2: Rollback After Cutover

**Situation:** Critical issues after switching to partitions

**Steps:**
1. Switch back to old table immediately
2. Investigate issues
3. Sync any new data from partitions to old table
4. Plan remediation

```bash
# Emergency rollback
# 1. Switch queries back to old table
USE_PARTITIONED_QUERIES=false
php artisan config:clear

# 2. If old table was renamed, rename it back
psql -U postgres -d your_database -c "ALTER TABLE alerts_legacy_backup RENAME TO alerts"

# 3. Sync any new data from partitions to old table
php artisan partition:sync-back --start-date=2026-01-09
```

#### Scenario 3: Partial Rollback

**Situation:** Some features work, others don't

**Steps:**
1. Use feature flags to selectively enable/disable
2. Route specific queries to old table
3. Fix issues incrementally

```php
// Selective routing
public function getAlerts($startDate, $endDate, $filters = [])
{
    // Use partitions for recent data only
    if ($startDate >= Carbon::today()->subDays(30)) {
        return $this->partitionRouter->queryDateRange($startDate, $endDate, $filters);
    }
    
    // Use old table for historical data
    return DB::table('alerts')
        ->whereBetween('receivedtime', [$startDate, $endDate])
        ->where($filters)
        ->get();
}
```

### Rollback Validation

```bash
# After rollback, verify:
# 1. Application is functional
curl http://localhost:8000/api/reports/query?start_date=2026-01-01&end_date=2026-01-09

# 2. No errors in logs
tail -100 storage/logs/laravel.log | grep -i error

# 3. Database queries working
php artisan tinker
>>> DB::table('alerts')->count();

# 4. Run tests
php artisan test
```

## Post-Migration Tasks

### 1. Performance Monitoring

```bash
# Set up monitoring for:
# - Query execution times
# - Partition creation frequency
# - Sync job duration
# - Error rates

# Example monitoring query
SELECT 
    schemaname,
    tablename,
    pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) AS size,
    pg_total_relation_size(schemaname||'.'||tablename) AS size_bytes
FROM pg_tables
WHERE tablename LIKE 'alerts_%'
ORDER BY size_bytes DESC
LIMIT 20;
```

### 2. Optimize Partition Indexes

```sql
-- Analyze all partition tables
DO $$
DECLARE
    partition_name TEXT;
BEGIN
    FOR partition_name IN 
        SELECT table_name FROM partition_registry
    LOOP
        EXECUTE 'ANALYZE ' || partition_name;
    END LOOP;
END $$;

-- Rebuild indexes if needed
REINDEX TABLE alerts_2026_01_08;
```

### 3. Set Up Automated Maintenance

```php
// In routes/console.php

// Daily partition cleanup (drop old partitions)
Schedule::command('partition:cleanup --older-than=90')
    ->daily()
    ->at('03:00');

// Weekly index maintenance
Schedule::command('partition:reindex --all')
    ->weekly()
    ->sundays()
    ->at('02:00');

// Daily validation
Schedule::command('partition:verify --check-counts')
    ->daily()
    ->at('04:00');
```

### 4. Update Documentation

- [ ] Update API documentation with partition endpoints
- [ ] Document partition naming conventions
- [ ] Create runbook for common operations
- [ ] Train team on new system
- [ ] Update monitoring dashboards

### 5. Archive Old Table

```bash
# After 30 days of successful operation:

# 1. Export old table
pg_dump -U postgres -d your_database -t alerts_legacy_backup > alerts_legacy_backup.sql

# 2. Compress export
gzip alerts_legacy_backup.sql

# 3. Move to archive storage
mv alerts_legacy_backup.sql.gz /archive/database_backups/

# 4. Drop old table
psql -U postgres -d your_database -c "DROP TABLE alerts_legacy_backup"
```

## Troubleshooting

### Common Migration Issues

#### Issue 1: Backfill Takes Too Long

**Symptoms:**
- Backfill running for many hours
- High database CPU usage

**Solutions:**
```bash
# Increase batch size
php artisan partition:backfill --batch-size=10000

# Run during off-peak hours
php artisan partition:backfill --start-date=2025-01-01 --end-date=2025-06-30 &

# Use parallel processing
php artisan partition:backfill --parallel=4
```

#### Issue 2: Record Count Mismatch

**Symptoms:**
- Validation shows different counts
- Missing records in partitions

**Solutions:**
```sql
-- Find missing records
SELECT a.id, a.receivedtime
FROM alerts a
LEFT JOIN (
    SELECT id FROM alerts_2026_01_08
    UNION ALL
    SELECT id FROM alerts_2026_01_09
    -- Add all partition tables
) p ON a.id = p.id
WHERE p.id IS NULL
AND DATE(a.receivedtime) BETWEEN '2026-01-08' AND '2026-01-09';

-- Re-sync missing records
php artisan partition:sync-missing --date=2026-01-08
```

#### Issue 3: Query Performance Degradation

**Symptoms:**
- Queries slower than before
- Timeout errors

**Solutions:**
```sql
-- Check if indexes exist
SELECT tablename, indexname 
FROM pg_indexes 
WHERE tablename LIKE 'alerts_%';

-- Rebuild missing indexes
php artisan partition:reindex --all

-- Analyze tables
ANALYZE alerts_2026_01_08;
```

#### Issue 4: Partition Creation Failures

**Symptoms:**
- Errors during sync
- Missing partitions

**Solutions:**
```sql
-- Check permissions
GRANT CREATE ON SCHEMA public TO your_user;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO your_user;

-- Check disk space
SELECT pg_size_pretty(pg_database_size('your_database'));

-- Manually create partition
php artisan tinker
>>> app(PartitionManager::class)->createPartition(Carbon::parse('2026-01-08'));
```

### Getting Help

If issues persist:

1. Check logs: `storage/logs/laravel.log`
2. Review error queue: `partition_sync_errors` table
3. Run diagnostics: `php artisan partition:diagnose`
4. Contact database administrator
5. Refer to main documentation: `docs/PARTITION_SYNC_GUIDE.md`

## Migration Checklist

### Pre-Migration
- [ ] Backup completed
- [ ] System requirements verified
- [ ] Stakeholders notified
- [ ] Migration team assigned
- [ ] Rollback plan documented

### During Migration
- [ ] Partition system deployed
- [ ] Parallel operation started
- [ ] Historical data backfilled
- [ ] Validation tests passed
- [ ] Performance benchmarks met

### Post-Migration
- [ ] Cutover completed
- [ ] Old table deprecated
- [ ] Monitoring configured
- [ ] Documentation updated
- [ ] Team trained
- [ ] Success metrics tracked

## Success Criteria

Migration is considered successful when:

✅ All historical data migrated to partitions
✅ Record counts match exactly
✅ Query performance meets or exceeds baseline
✅ No data loss or corruption
✅ Application functionality unchanged
✅ Zero critical errors for 48 hours
✅ User acceptance confirmed
✅ Rollback capability maintained for 30 days

## Conclusion

This migration guide provides a comprehensive approach to transitioning from a single alerts table to a date-partitioned structure. Follow the steps carefully, validate at each stage, and maintain rollback capability until the new system is proven stable.

For additional support, refer to:
- Main documentation: `docs/PARTITION_SYNC_GUIDE.md`
- API reference: `docs/TABLE_SYNC_API.md`
- System architecture: `.kiro/specs/date-partitioned-alerts-sync/design.md`
