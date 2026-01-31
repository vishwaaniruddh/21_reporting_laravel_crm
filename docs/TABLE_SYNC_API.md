# Table Sync API Documentation

This document describes the API endpoints for the configurable table synchronization feature.

## Overview

The Table Sync feature allows you to configure and manage database table synchronization between different database connections (e.g., MySQL to PostgreSQL).

## Base URL

All API endpoints are prefixed with `/api/table-sync/`

## Authentication

Currently, the API does not require authentication. In production, you should add appropriate authentication middleware.

---

## Endpoints

### Configurations

#### List All Configurations

```
GET /api/table-sync/configurations
```

**Query Parameters:**
- `with_stats` (boolean, optional): Include sync statistics for each configuration

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "source_connection": "mysql",
      "source_table": "alerts",
      "target_connection": "pgsql",
      "target_table": "alerts",
      "primary_key": "id",
      "sync_marker_column": "synced_at",
      "batch_size": 100,
      "is_active": true,
      "sync_interval_minutes": 5,
      "last_sync_at": "2025-01-07T18:30:00.000000Z",
      "last_sync_status": "success"
    }
  ]
}
```

#### Get Single Configuration

```
GET /api/table-sync/configurations/{id}
```

#### Create Configuration

```
POST /api/table-sync/configurations
```

**Request Body:**
```json
{
  "source_connection": "mysql",
  "source_table": "alerts",
  "target_connection": "pgsql",
  "target_table": "alerts",
  "primary_key": "id",
  "sync_marker_column": "synced_at",
  "batch_size": 100,
  "is_active": true,
  "sync_interval_minutes": 5
}
```

#### Update Configuration

```
PUT /api/table-sync/configurations/{id}
```

#### Delete Configuration

```
DELETE /api/table-sync/configurations/{id}
```

---

### Sync Operations

#### Trigger Sync

```
POST /api/table-sync/configurations/{id}/sync
```

Triggers an immediate sync for the specified configuration.

**Response:**
```json
{
  "success": true,
  "message": "Sync completed successfully",
  "records_synced": 150,
  "records_failed": 0
}
```

#### Get Sync Statistics

```
GET /api/table-sync/configurations/{id}/stats
```

---

### Sync Logs

#### List Sync Logs

```
GET /api/table-sync/configurations/{id}/logs
```

**Query Parameters:**
- `status` (string, optional): Filter by status (success, partial, failed)
- `per_page` (integer, optional): Number of results per page

---

### Sync Errors

#### List Sync Errors

```
GET /api/table-sync/configurations/{id}/errors
```

---

## Sync Process

### How Sync Works

1. **Lock Acquisition**: Prevents concurrent syncs for the same configuration
2. **Marker Column**: Uses `synced_at` column to track synced records
3. **Batch Processing**: Records processed in configurable batch sizes
4. **Upsert Logic**: Insert new records, update existing ones
5. **Logging**: All operations logged to `table_sync_logs`

### Scheduled Sync

Configurations with `sync_interval_minutes` set will be automatically synced by the Laravel scheduler.

```bash
# Add to crontab
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

---

## Error Handling

| Error | Description | Solution |
|-------|-------------|----------|
| Sync lock acquisition failed | Another sync in progress | Wait for current sync |
| Connection not found | Invalid database connection | Check config/database.php |
| Table not found | Source/target table missing | Create table or check name |

---

## Best Practices

1. Start with smaller batch sizes (100-500)
2. Set appropriate sync intervals based on data volume
3. Monitor sync logs regularly
4. Ensure target table schema matches source
5. Use unique, immutable primary keys
