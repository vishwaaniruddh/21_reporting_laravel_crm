# Requirements Document

## Introduction

A configurable table synchronization module that enables administrators to specify any MySQL table for one-way data synchronization to PostgreSQL. Unlike the existing alerts-specific pipeline, this module provides a generic, reusable sync mechanism where users can configure table names, column mappings, and sync behavior. The module preserves all source data in MySQL (no deletion) while maintaining incremental sync capabilities and data integrity.

## Glossary

- **Table_Sync_Module**: The configurable system that synchronizes data from any specified MySQL table to PostgreSQL
- **Source_Table**: The MySQL table specified by the administrator for synchronization
- **Target_Table**: The corresponding PostgreSQL table where synced data is stored
- **Table_Configuration**: Settings that define which table to sync, column mappings, and sync behavior
- **Column_Mapping**: Definition of how source columns map to target columns (supports renaming)
- **Sync_Marker_Column**: A column added to source table to track sync status (synced_at timestamp)
- **Primary_Key_Column**: The column used to identify and track individual records for sync
- **Sync_Registry**: A central registry storing all configured table sync definitions
- **Batch_Sync_Engine**: The engine that processes records in batches for any configured table

## Requirements

### Requirement 1: Table Configuration Management

**User Story:** As a system administrator, I want to specify which MySQL table to sync to PostgreSQL, so that I can extend the sync capability to any table without code changes.

#### Acceptance Criteria

1. THE Table_Sync_Module SHALL accept a table name parameter specifying the Source_Table to sync
2. THE Table_Sync_Module SHALL validate that the specified Source_Table exists in MySQL before sync
3. THE Table_Sync_Module SHALL automatically detect column schema from the Source_Table
4. WHEN a table is configured for sync, THE Table_Sync_Module SHALL store the configuration in Sync_Registry
5. THE Table_Sync_Module SHALL support multiple table configurations running independently
6. THE Table_Sync_Module SHALL allow specifying a custom Target_Table name (defaults to source name)

### Requirement 2: Column Mapping and Schema Handling

**User Story:** As a system administrator, I want to define column mappings between source and target tables, so that I can handle schema differences between MySQL and PostgreSQL.

#### Acceptance Criteria

1. THE Table_Sync_Module SHALL support one-to-one column mapping by default (same column names)
2. THE Table_Sync_Module SHALL allow custom Column_Mapping to rename columns during sync
3. THE Table_Sync_Module SHALL allow excluding specific columns from synchronization
4. WHEN the Target_Table does not exist, THE Table_Sync_Module SHALL create it with matching schema
5. THE Table_Sync_Module SHALL handle MySQL-to-PostgreSQL data type conversions automatically
6. THE Table_Sync_Module SHALL preserve NULL values and default values during sync

### Requirement 3: Incremental Sync Without Deletion

**User Story:** As a system administrator, I want data to sync incrementally from MySQL to PostgreSQL without deleting source records, so that MySQL remains the authoritative data source.

#### Acceptance Criteria

1. THE Table_Sync_Module SHALL add a Sync_Marker_Column to track sync status on Source_Table
2. THE Table_Sync_Module SHALL only sync records where Sync_Marker_Column is NULL (unsynced)
3. THE Table_Sync_Module SHALL update Sync_Marker_Column with timestamp after successful sync
4. THE Table_Sync_Module SHALL NOT delete any records from the Source_Table
5. THE Table_Sync_Module SHALL support re-syncing records by clearing their Sync_Marker_Column
6. THE Table_Sync_Module SHALL use the Primary_Key_Column for efficient record identification

### Requirement 4: Batch Processing for Configured Tables

**User Story:** As a system administrator, I want the sync to process records in configurable batches, so that large tables can be synced without memory or performance issues.

#### Acceptance Criteria

1. THE Batch_Sync_Engine SHALL process records in configurable batch sizes (default: 10,000)
2. THE Batch_Sync_Engine SHALL use transactions per batch to ensure atomicity
3. WHEN a batch fails, THE Batch_Sync_Engine SHALL rollback only that batch and continue with next
4. THE Batch_Sync_Engine SHALL checkpoint progress after each successful batch
5. THE Batch_Sync_Engine SHALL support resuming from last checkpoint on restart
6. THE Batch_Sync_Engine SHALL log batch progress including records processed and duration

### Requirement 5: Sync Execution and Scheduling

**User Story:** As a system administrator, I want to run sync operations on-demand or on a schedule, so that I can control when data synchronization occurs.

#### Acceptance Criteria

1. THE Table_Sync_Module SHALL support on-demand sync execution via API endpoint
2. THE Table_Sync_Module SHALL support scheduled sync via configurable cron expression
3. THE Table_Sync_Module SHALL allow enabling/disabling sync per table configuration
4. WHEN sync is triggered, THE Table_Sync_Module SHALL prevent concurrent syncs for the same table
5. THE Table_Sync_Module SHALL provide sync status (idle, running, completed, failed) per table
6. THE Table_Sync_Module SHALL support syncing all configured tables or a specific table

### Requirement 6: Sync Monitoring and Logging

**User Story:** As a system administrator, I want to monitor sync operations for all configured tables, so that I can track progress and troubleshoot issues.

#### Acceptance Criteria

1. THE Table_Sync_Module SHALL log each sync operation with table name, records synced, and duration
2. THE Table_Sync_Module SHALL display sync history per configured table
3. THE Table_Sync_Module SHALL show pending record count for each configured table
4. WHEN sync fails, THE Table_Sync_Module SHALL log error details with affected record range
5. THE Table_Sync_Module SHALL provide a dashboard showing status of all configured table syncs
6. THE Table_Sync_Module SHALL support filtering sync logs by table name and date range

### Requirement 7: Error Handling and Recovery

**User Story:** As a system administrator, I want the sync module to handle errors gracefully, so that temporary failures don't corrupt data or require manual intervention.

#### Acceptance Criteria

1. WHEN MySQL connection fails, THE Table_Sync_Module SHALL retry with exponential backoff
2. WHEN PostgreSQL connection fails, THE Table_Sync_Module SHALL rollback batch and retry
3. IF a record fails validation, THEN THE Table_Sync_Module SHALL skip it and log the error
4. THE Table_Sync_Module SHALL maintain an error queue for records that repeatedly fail
5. THE Table_Sync_Module SHALL support manual retry of failed records from error queue
6. WHEN errors exceed a configurable threshold, THE Table_Sync_Module SHALL pause sync and alert

### Requirement 8: API and UI Integration

**User Story:** As a system administrator, I want to manage table sync configurations through the existing dashboard, so that I can configure and monitor syncs without direct database access.

#### Acceptance Criteria

1. THE Table_Sync_Module SHALL provide REST API endpoints for CRUD operations on table configurations
2. THE Table_Sync_Module SHALL provide API endpoint to trigger sync for a specific table
3. THE Table_Sync_Module SHALL provide API endpoint to get sync status and statistics
4. THE Reporting_System SHALL display a configuration UI for managing table sync settings
5. THE Reporting_System SHALL display sync progress and history for each configured table
6. THE Table_Sync_Module SHALL validate configuration changes before applying them

