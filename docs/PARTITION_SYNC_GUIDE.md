# Date-Partitioned Alerts Sync Guide

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Date-Based Partitioning](#date-based-partitioning)
4. [API Endpoints](#api-endpoints)
5. [Usage Examples](#usage-examples)
6. [Configuration](#configuration)
7. [Monitoring and Maintenance](#monitoring-and-maintenance)
8. [Troubleshooting](#troubleshooting)

## Overview

The Date-Partitioned Alerts Sync system synchronizes alert records from MySQL to PostgreSQL using daily partitioned tables. Instead of storing all alerts in a single table, the system creates separate tables for each date (e.g., `alerts_2026_01_08`) based on the `receivedtime` column from MySQL.

### Key Benefits

- **Improved Query Performance**: Date-range queries are faster due to partition pruning
- **Efficient Data Management**: Easy archival and deletion of old data by dropping partition tables
- **Scalability**: Distributes data across multiple tables, reducing table size
- **Automatic Partition Creation**: No manual intervention needed for new dates
- **Backward Compatible**: Existing reports continue to work seamlessly

### System Components

```
MySQL Alerts (Source)
        ↓
Date Extraction & Grouping
        ↓
Partition Manager (Creates tables as needed)
        ↓
PostgreSQL Partitions (alerts_YYYY_MM_DD)
        ↓
Partition Query Router (Unified query interface)
        ↓
Reports & Analytics
```

## Architecture

### Core Components

#### 1. DateExtractor
Extracts dates from MySQL `receivedtime` column and formats them for partition table names.

**Responsibilities:**
- Parse MySQL timestamps
- Handle timezone conversions
- Format dates as `YYYY_MM_DD`
- Validate partition names

#### 2. PartitionManager
Manages the lifecycle of partition tables in PostgreSQL.

**Responsibilities:**
- Check if partition tables exist
- Create new partition tables dynamically
- Replicate schema and indexes
- Register partitions in metadata registry
- Handle creation errors with retry logic

#### 3. DateGroupedSyncService
Orchestrates the sync process with date-based grouping.

**Responsibilities:**
- Fetch alerts from MySQL in batches
- Group alerts by extracted date
- Sync each date group to its partition
- Handle transactions per date group
- Update partition metadata

#### 4. PartitionQueryRouter
Provides unified query interface across multiple partitions.

**Responsibilities:**
- Accept date range parameters
- Identify relevant partition tables
- Build UNION ALL queries
- Aggregate results from multiple partitions
- Handle missing partitions gracefully

#### 5. PartitionRegistry
Tracks metadata about all partition tables.

**Responsibilities:**
- Maintain registry of created partitions
- Track record counts per partition
- Store creation and last sync timestamps
- Provide partition discovery API

## Date-Based Partitioning

### Partition Naming Convention

Partition tables follow the format: `alerts_YYYY_MM_DD`

**Examples:**
- `alerts_2026_01_08` - Alerts received on January 8, 2026
- `alerts_2026_12_31` - Alerts received on December 31, 2026

**Rules:**
- Year: 4 digits (e.g., 2026)
- Month: 2 digits, zero-padded (e.g., 01, 12)
- Day: 2 digits, zero-padded (e.g., 08, 31)
- Separator: Underscore (`_`)

### Partition Schema

Each partition table has identical structure:

```sql
CREATE TABLE alerts_2026_01_08 (
    id BIGINT PRIMARY KEY,
    terminal_id VARCHAR(50),
    alert_type VARCHAR(100) NOT NULL,
    alert_code VARCHAR(50),
    message TEXT,
    severity VARCHAR(20) DEFAULT 'medium',
    source_system VARCHAR(100),
    receivedtime TIMESTAMP NOT NULL,
    resolved_at TIMESTAMP NULL,
    metadata JSONB,
    synced_at TIMESTAMP DEFAULT NOW(),
    sync_batch_id BIGINT
);

-- Indexes on each partition
CREATE INDEX idx_alerts_2026_01_08_terminal_id ON alerts_2026_01_08 (terminal_id);
CREATE INDEX idx_alerts_2026_01_08_alert_type ON alerts_2026_01_08 (alert_type);
CREATE INDEX idx_alerts_2026_01_08_severity ON alerts_2026_01_08 (severity);
CREATE INDEX idx_alerts_2026_01_08_receivedtime ON alerts_2026_01_08 (receivedtime);
```

### Automatic Partition Creation

Partitions are created automatically when:
1. Sync process encounters a new date
2. First alert for that date is processed
3. Partition doesn't exist in PostgreSQL

**Creation Process:**
1. Extract date from alert's `receivedtime`
2. Format as partition table name
3. Check if table exists
4. If not, create table with base schema
5. Create all indexes
6. Register in `partition_registry`
7. Insert alert records

### Partition Metadata Registry

The `partition_registry` table tracks all partitions:

```sql
CREATE TABLE partition_registry (
    id SERIAL PRIMARY KEY,
    table_name VARCHAR(100) UNIQUE NOT NULL,
    partition_date DATE NOT NULL,
    record_count BIGINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW(),
    last_synced_at TIMESTAMP
);
```

**Tracked Information:**
- Table name (e.g., `alerts_2026_01_08`)
- Partition date (2026-01-08)
- Record count (updated after each sync)
- Creation timestamp
- Last sync timestamp

## API Endpoints

### 1. Trigger Partition Sync

**Endpoint:** `POST /api/sync/partitioned/trigger`

**Description:** Manually trigger a date-partitioned sync operation.

**Request:**
```json
{
    "batch_size": 1000,
    "start_from_id": 0
}
```

**Parameters:**
- `batch_size` (optional): Number of records per batch (default: 1000)
- `start_from_id` (optional): MySQL ID to start from (default: 0)

**Response:**
```json
{
    "success": true,
    "message": "Partition sync completed successfully",
    "data": {
        "total_records_synced": 5432,
        "partitions_created": 3,
        "partitions_updated": 2,
        "date_groups": [
            {
                "date": "2026-01-08",
                "partition_table": "alerts_2026_01_08",
                "records_inserted": 2150,
                "success": true
            },
            {
                "date": "2026-01-09",
                "partition_table": "alerts_2026_01_09",
                "records_inserted": 1890,
                "success": true
            }
        ],
        "execution_time_seconds": 12.5
    }
}
```

**Example:**
```bash
curl -X POST http://localhost:8000/api/sync/partitioned/trigger \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"batch_size": 500}'
```

### 2. List All Partitions

**Endpoint:** `GET /api/sync/partitions`

**Description:** Retrieve list of all partition tables with metadata.

**Query Parameters:**
- `sort_by` (optional): Sort field (date, record_count, created_at)
- `order` (optional): Sort order (asc, desc)
- `limit` (optional): Number of results (default: 100)

**Response:**
```json
{
    "success": true,
    "data": {
        "partitions": [
            {
                "table_name": "alerts_2026_01_08",
                "partition_date": "2026-01-08",
                "record_count": 2150,
                "created_at": "2026-01-08T10:30:00Z",
                "last_synced_at": "2026-01-08T15:45:00Z"
            },
            {
                "table_name": "alerts_2026_01_09",
                "partition_date": "2026-01-09",
                "record_count": 1890,
                "created_at": "2026-01-09T09:15:00Z",
                "last_synced_at": "2026-01-09T14:20:00Z"
            }
        ],
        "total_partitions": 45,
        "total_records": 98765
    }
}
```

**Example:**
```bash
curl -X GET "http://localhost:8000/api/sync/partitions?sort_by=date&order=desc&limit=10" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### 3. Get Partition Info

**Endpoint:** `GET /api/sync/partitions/{date}`

**Description:** Get detailed information for a specific partition.

**Path Parameters:**
- `date`: Partition date in YYYY-MM-DD format

**Response:**
```json
{
    "success": true,
    "data": {
        "table_name": "alerts_2026_01_08",
        "partition_date": "2026-01-08",
        "record_count": 2150,
        "created_at": "2026-01-08T10:30:00Z",
        "last_synced_at": "2026-01-08T15:45:00Z",
        "schema_info": {
            "columns": 12,
            "indexes": 4,
            "table_size": "1.2 MB"
        },
        "sync_history": [
            {
                "synced_at": "2026-01-08T15:45:00Z",
                "records_added": 150,
                "batch_id": 12345
            }
        ]
    }
}
```

**Example:**
```bash
curl -X GET http://localhost:8000/api/sync/partitions/2026-01-08 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### 4. Query Across Partitions

**Endpoint:** `GET /api/reports/partitioned/query`

**Description:** Query alerts across multiple date partitions.

**Query Parameters:**
- `start_date` (required): Start date (YYYY-MM-DD)
- `end_date` (required): End date (YYYY-MM-DD)
- `alert_type` (optional): Filter by alert type
- `severity` (optional): Filter by severity
- `terminal_id` (optional): Filter by terminal ID
- `limit` (optional): Number of results (default: 100)
- `offset` (optional): Pagination offset (default: 0)

**Response:**
```json
{
    "success": true,
    "data": {
        "alerts": [
            {
                "id": 12345,
                "terminal_id": "TERM001",
                "alert_type": "hardware_failure",
                "severity": "high",
                "message": "Disk failure detected",
                "receivedtime": "2026-01-08T14:30:00Z",
                "partition_table": "alerts_2026_01_08"
            }
        ],
        "total_count": 450,
        "partitions_queried": ["alerts_2026_01_08", "alerts_2026_01_09"],
        "date_range": {
            "start": "2026-01-08",
            "end": "2026-01-09"
        }
    }
}
```

**Example:**
```bash
curl -X GET "http://localhost:8000/api/reports/partitioned/query?start_date=2026-01-08&end_date=2026-01-10&severity=high" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### 5. Get Partition Summary

**Endpoint:** `GET /api/reports/partitioned/summary`

**Description:** Get summary statistics across all partitions.

**Query Parameters:**
- `start_date` (optional): Filter from date
- `end_date` (optional): Filter to date

**Response:**
```json
{
    "success": true,
    "data": {
        "total_partitions": 45,
        "total_records": 98765,
        "date_range": {
            "earliest": "2025-11-15",
            "latest": "2026-01-09"
        },
        "by_severity": {
            "critical": 1234,
            "high": 5678,
            "medium": 45678,
            "low": 46175
        },
        "by_alert_type": {
            "hardware_failure": 2345,
            "network_issue": 3456,
            "software_error": 4567
        },
        "storage_info": {
            "total_size": "125.5 MB",
            "average_partition_size": "2.8 MB"
        }
    }
}
```

**Example:**
```bash
curl -X GET "http://localhost:8000/api/reports/partitioned/summary?start_date=2026-01-01&end_date=2026-01-31" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## Usage Examples

### Running Partition Sync via Artisan Command

```bash
# Basic sync with default settings
php artisan sync:partitioned

# Sync with custom batch size
php artisan sync:partitioned --batch-size=500

# Sync starting from specific MySQL ID
php artisan sync:partitioned --start-from=10000

# Sync with verbose output
php artisan sync:partitioned --verbose

# Dry run (show what would be synced)
php artisan sync:partitioned --dry-run
```

### Scheduling Automatic Sync

Add to `routes/console.php` or `app/Console/Kernel.php`:

```php
// Run partition sync every hour
Schedule::command('sync:partitioned')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Run during off-peak hours only
Schedule::command('sync:partitioned --batch-size=2000')
    ->dailyAt('02:00')
    ->timezone('America/New_York')
    ->withoutOverlapping();
```

### Querying Partitioned Data in Code

```php
use App\Services\PartitionQueryRouter;
use Carbon\Carbon;

// Get partition query router
$router = app(PartitionQueryRouter::class);

// Query alerts for a date range
$startDate = Carbon::parse('2026-01-08');
$endDate = Carbon::parse('2026-01-10');

$alerts = $router->queryDateRange($startDate, $endDate, [
    'alert_type' => 'hardware_failure',
    'severity' => 'high',
    'limit' => 100
]);

// Get list of partitions in range
$partitions = $router->getPartitionsInRange($startDate, $endDate);
// Returns: ['alerts_2026_01_08', 'alerts_2026_01_09', 'alerts_2026_01_10']
```

### Creating Partitions Programmatically

```php
use App\Services\PartitionManager;
use Carbon\Carbon;

$manager = app(PartitionManager::class);

// Ensure partition exists for a specific date
$date = Carbon::parse('2026-01-15');
$exists = $manager->ensurePartitionExists($date);

// Create partition explicitly
$created = $manager->createPartition($date);

// Get partition table name
$tableName = $manager->getPartitionTableName($date);
// Returns: 'alerts_2026_01_15'

// List all partitions
$partitions = $manager->listPartitions();
```

### Accessing Partition Metadata

```php
use App\Models\PartitionRegistry;

// Get all partitions ordered by date
$partitions = PartitionRegistry::orderBy('partition_date', 'desc')->get();

// Get partition for specific date
$partition = PartitionRegistry::where('partition_date', '2026-01-08')->first();

// Get total record count across all partitions
$totalRecords = PartitionRegistry::sum('record_count');

// Get partitions created in last 7 days
$recentPartitions = PartitionRegistry::where('created_at', '>=', now()->subDays(7))->get();
```

### React Component Usage

```jsx
import { partitionService } from '../services/partitionService';

// Fetch all partitions
const partitions = await partitionService.getPartitions();

// Trigger manual sync
const result = await partitionService.triggerSync({ batch_size: 1000 });

// Query across partitions
const alerts = await partitionService.queryPartitions({
    start_date: '2026-01-08',
    end_date: '2026-01-10',
    severity: 'high'
});

// Get partition summary
const summary = await partitionService.getPartitionSummary();
```

## Configuration

### Environment Variables

```env
# Partition sync configuration
PARTITION_SYNC_BATCH_SIZE=1000
PARTITION_SYNC_ENABLED=true
PARTITION_SYNC_SCHEDULE="0 * * * *"  # Every hour

# Partition retention (days)
PARTITION_RETENTION_DAYS=90

# Error handling
PARTITION_MAX_RETRIES=3
PARTITION_RETRY_DELAY=5  # seconds
PARTITION_ERROR_THRESHOLD=10  # Alert after N failures
```

### Laravel Configuration

Create `config/partition-sync.php`:

```php
<?php

return [
    'enabled' => env('PARTITION_SYNC_ENABLED', true),
    
    'batch_size' => env('PARTITION_SYNC_BATCH_SIZE', 1000),
    
    'schedule' => env('PARTITION_SYNC_SCHEDULE', '0 * * * *'),
    
    'retention_days' => env('PARTITION_RETENTION_DAYS', 90),
    
    'error_handling' => [
        'max_retries' => env('PARTITION_MAX_RETRIES', 3),
        'retry_delay' => env('PARTITION_RETRY_DELAY', 5),
        'error_threshold' => env('PARTITION_ERROR_THRESHOLD', 10),
    ],
    
    'timezone' => env('PARTITION_TIMEZONE', 'UTC'),
];
```

## Monitoring and Maintenance

### Health Checks

```php
// Check if partition sync is running
$isRunning = Cache::has('partition_sync_running');

// Get last sync time
$lastSync = Cache::get('partition_sync_last_run');

// Get sync statistics
$stats = Cache::get('partition_sync_stats');
```

### Monitoring Queries

```sql
-- Check partition count
SELECT COUNT(*) FROM partition_registry;

-- Check total records across partitions
SELECT SUM(record_count) FROM partition_registry;

-- Find partitions with no recent syncs
SELECT * FROM partition_registry 
WHERE last_synced_at < NOW() - INTERVAL '24 hours';

-- Check partition sizes
SELECT 
    table_name,
    pg_size_pretty(pg_total_relation_size(table_name::regclass)) as size
FROM partition_registry
ORDER BY pg_total_relation_size(table_name::regclass) DESC;
```

### Maintenance Tasks

#### Archive Old Partitions

```bash
# Archive partitions older than 90 days
php artisan partition:archive --older-than=90

# Export partition to file before dropping
php artisan partition:export alerts_2025_10_15 --format=csv
php artisan partition:drop alerts_2025_10_15
```

#### Rebuild Partition Indexes

```bash
# Rebuild indexes for specific partition
php artisan partition:reindex alerts_2026_01_08

# Rebuild indexes for all partitions
php artisan partition:reindex --all
```

#### Verify Partition Integrity

```bash
# Check for missing partitions in date range
php artisan partition:verify --start-date=2026-01-01 --end-date=2026-01-31

# Verify record counts match
php artisan partition:verify --check-counts
```

## Troubleshooting

### Common Issues

#### 1. Partition Creation Fails

**Symptoms:**
- Error: "Permission denied for schema public"
- Partitions not being created

**Solutions:**
```sql
-- Grant necessary permissions
GRANT CREATE ON SCHEMA public TO your_user;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO your_user;
```

#### 2. Slow Cross-Partition Queries

**Symptoms:**
- Queries across many partitions are slow
- Timeout errors

**Solutions:**
- Reduce date range in queries
- Add more specific filters (alert_type, severity)
- Ensure indexes exist on all partitions
- Consider query result caching

#### 3. Missing Partitions

**Symptoms:**
- Gaps in partition dates
- Some dates have no data

**Solutions:**
```bash
# Check for missing dates
php artisan partition:check-gaps --start-date=2026-01-01 --end-date=2026-01-31

# Backfill missing partitions
php artisan partition:backfill --date=2026-01-15
```

#### 4. Duplicate Records

**Symptoms:**
- Same alert appears multiple times
- Record count doesn't match

**Solutions:**
```bash
# Check for duplicates
php artisan partition:check-duplicates alerts_2026_01_08

# Remove duplicates (keeps first occurrence)
php artisan partition:deduplicate alerts_2026_01_08
```

### Debug Mode

Enable detailed logging:

```php
// In .env
LOG_LEVEL=debug
PARTITION_SYNC_DEBUG=true

// Check logs
tail -f storage/logs/laravel.log | grep "Partition"
```

### Performance Tuning

```php
// Increase batch size for faster sync
php artisan sync:partitioned --batch-size=5000

// Use parallel processing (if available)
php artisan sync:partitioned --parallel=4

// Disable indexes during bulk insert
php artisan sync:partitioned --no-indexes
php artisan partition:reindex --all
```

## Best Practices

1. **Regular Monitoring**: Check partition health daily
2. **Scheduled Sync**: Run sync during off-peak hours
3. **Archive Old Data**: Drop or archive partitions older than retention period
4. **Index Maintenance**: Rebuild indexes periodically
5. **Backup Strategy**: Backup partition_registry table regularly
6. **Query Optimization**: Use specific date ranges and filters
7. **Error Handling**: Monitor error queue and retry failed operations
8. **Documentation**: Keep partition naming conventions documented

## Support

For issues or questions:
- Check logs: `storage/logs/laravel.log`
- Review error queue: `partition_sync_errors` table
- Contact system administrator
- Refer to migration guide for advanced scenarios
