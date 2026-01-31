# Implementation Plan: Configurable Table Sync Module

## Overview

This implementation plan creates a generic, configurable table synchronization module for the existing Laravel dual-database application. The module enables administrators to specify any MySQL table for one-way sync to PostgreSQL. **CRITICAL: This module NEVER deletes any records from MySQL source tables - it only reads from MySQL and writes to PostgreSQL.**

## Tasks

- [x] 1. Create database migrations and models
  - [x] 1.1 Create migration for table_sync_configurations table in PostgreSQL
    - Define all columns: id, name, source_table, target_table, primary_key_column, sync_marker_column, column_mappings (JSONB), excluded_columns (JSONB), batch_size, schedule, is_enabled, last_sync_at, last_sync_status
    - Add unique constraint on source_table
    - **⚠️ NO DELETION FROM MYSQL: This table only stores config metadata in PostgreSQL**
    - _Requirements: 1.4, 1.5_

  - [x] 1.2 Create migration for table_sync_logs table in PostgreSQL
    - Define columns: id, configuration_id (FK), source_table, records_synced, records_failed, start_id, end_id, status, error_message, duration_ms, started_at, completed_at
    - **⚠️ NO DELETION FROM MYSQL: Logs are stored in PostgreSQL only**
    - _Requirements: 6.1, 6.4_

  - [x] 1.3 Create migration for table_sync_errors table in PostgreSQL
    - Define columns: id, configuration_id (FK), source_table, record_id, record_data (JSONB), error_message, retry_count, last_retry_at, resolved_at
    - **⚠️ NO DELETION FROM MYSQL: Error queue is stored in PostgreSQL only**
    - _Requirements: 7.4_

  - [x] 1.4 Create TableSyncConfiguration model
    - Define fillable fields, casts for JSONB columns
    - Add relationships to logs and errors
    - Add scopes for enabled/disabled configurations
    - **⚠️ NO DELETION FROM MYSQL: Model connects to PostgreSQL only**
    - _Requirements: 1.1, 1.4, 1.6_

  - [x] 1.5 Create TableSyncLog model
    - Define fillable fields, relationship to configuration
    - Add helper methods for logging sync operations
    - **⚠️ NO DELETION FROM MYSQL: Model connects to PostgreSQL only**
    - _Requirements: 6.1_

  - [x] 1.6 Create TableSyncError model
    - Define fillable fields, relationship to configuration
    - Add methods for adding to queue and marking resolved
    - **⚠️ NO DELETION FROM MYSQL: Model connects to PostgreSQL only**
    - _Requirements: 7.4_

- [x] 2. Implement Schema Detection Service
  - [x] 2.1 Create SchemaDetectorService class
    - Implement tableExists() method to check if table exists in MySQL
    - Implement getTableSchema() to retrieve column definitions (READ-ONLY)
    - Implement getColumnTypes() to get MySQL column types (READ-ONLY)
    - Implement getPrimaryKey() to detect primary key column (READ-ONLY)
    - **⚠️ NO DELETION FROM MYSQL: This service only READS schema information, never modifies or deletes data**
    - _Requirements: 1.2, 1.3_

  - [ ]* 2.2 Write property test for schema detection accuracy
    - **Property 2: Schema Detection Accuracy**
    - **⚠️ NO DELETION FROM MYSQL: Test only verifies read operations**
    - **Validates: Requirements 1.3**

- [x] 3. Implement Column Mapper Service
  - [x] 3.1 Create ColumnMapperService class
    - Implement mapColumns() for applying column mappings to source rows
    - Implement column exclusion logic
    - Implement convertType() for MySQL to PostgreSQL type conversion
    - Implement generateTargetSchema() for creating target table DDL
    - **⚠️ NO DELETION FROM MYSQL: This service only transforms data in memory, never deletes from MySQL**
    - _Requirements: 2.1, 2.2, 2.3, 2.5_

  - [ ]* 3.2 Write property test for column mapping correctness
    - **Property 3: Column Mapping Correctness**
    - **⚠️ NO DELETION FROM MYSQL: Test only verifies data transformation**
    - **Validates: Requirements 2.1, 2.2, 2.3**

  - [ ]* 3.3 Write property test for data type conversion
    - **Property 4: Data Type Conversion Preservation**
    - **⚠️ NO DELETION FROM MYSQL: Test only verifies type conversion**
    - **Validates: Requirements 2.5**

  - [ ]* 3.4 Write property test for NULL value preservation
    - **Property 5: NULL Value Preservation**
    - **⚠️ NO DELETION FROM MYSQL: Test only verifies NULL handling**
    - **Validates: Requirements 2.6**

- [x] 4. Implement Generic Sync Service
  - [x] 4.1 Create GenericSyncService class with core sync logic
    - Implement syncTable() method for single table sync
    - Implement batch fetching from MySQL using dynamic table name (READ-ONLY from MySQL)
    - Implement bulk insert to PostgreSQL with column mapping (WRITE to PostgreSQL only)
    - Implement sync marker update in MySQL source table (UPDATE only synced_at column, NEVER DELETE)
    - **⚠️ NO DELETION FROM MYSQL: Only SELECT and UPDATE (sync marker) operations allowed on MySQL**
    - _Requirements: 3.2, 3.3, 4.1_

  - [x] 4.2 Implement sync marker column management
    - Implement addSyncMarkerColumn() to add synced_at column to source table (ALTER TABLE ADD COLUMN only)
    - Implement checkSyncMarkerExists() to verify column exists
    - **⚠️ NO DELETION FROM MYSQL: Only adds a column, NEVER deletes records or columns**
    - _Requirements: 3.1, 3.4_

  - [x] 4.3 Implement target table auto-creation
    - Create target table in PostgreSQL if it doesn't exist
    - Use schema detection and column mapper to generate DDL
    - **⚠️ NO DELETION FROM MYSQL: Only creates tables in PostgreSQL**
    - _Requirements: 2.4_

  - [x] 4.4 Implement batch processing with transactions
    - Wrap each batch in PostgreSQL transaction
    - Implement rollback on failure (PostgreSQL rollback only)
    - Implement checkpoint after each successful batch
    - **⚠️ NO DELETION FROM MYSQL: Transactions and rollbacks only affect PostgreSQL, MySQL data remains intact**
    - _Requirements: 4.2, 4.3, 4.4, 4.5_

  - [ ]* 4.5 Write property test for source data preservation
    - **Property 6: Source Data Preservation (Critical)**
    - **⚠️ NO DELETION FROM MYSQL: This test MUST verify MySQL record count never decreases**
    - **Validates: Requirements 3.4**

  - [ ]* 4.6 Write property test for sync marker consistency
    - **Property 7: Sync Marker Consistency**
    - **⚠️ NO DELETION FROM MYSQL: Test verifies sync markers, not deletion**
    - **Validates: Requirements 3.2, 3.3**

  - [ ]* 4.7 Write property test for batch transaction atomicity
    - **Property 8: Batch Transaction Atomicity**
    - **⚠️ NO DELETION FROM MYSQL: Atomicity applies to PostgreSQL inserts only**
    - **Validates: Requirements 4.2**

  - [ ]* 4.8 Write property test for batch isolation on failure
    - **Property 9: Batch Isolation on Failure**
    - **⚠️ NO DELETION FROM MYSQL: Failure handling only affects PostgreSQL, MySQL data preserved**
    - **Validates: Requirements 4.3**

- [x] 5. Checkpoint - Ensure core sync engine tests pass
  - Ensure all tests pass, ask the user if questions arise.
  - **⚠️ VERIFY: No test or implementation deletes from MySQL**

- [x] 6. Implement Sync Logging and Error Queue
  - [x] 6.1 Create TableSyncLogService class
    - Implement logSyncStart() and logSyncComplete() methods
    - Implement logSyncError() for failed syncs
    - Implement getLogsForConfiguration() with filtering
    - **⚠️ NO DELETION FROM MYSQL: All logs stored in PostgreSQL**
    - _Requirements: 6.1, 6.4, 6.6_

  - [x] 6.2 Create TableSyncErrorQueueService class
    - Implement addToQueue() for failed records (stores in PostgreSQL)
    - Implement retryFromQueue() for manual retry
    - Implement markResolved() for successful retries
    - Implement getQueuedErrors() with filtering
    - **⚠️ NO DELETION FROM MYSQL: Error queue is in PostgreSQL, retry re-reads from MySQL**
    - _Requirements: 7.4, 7.5_

  - [ ]* 6.3 Write property test for sync logging completeness
    - **Property 10: Sync Logging Completeness**
    - **⚠️ NO DELETION FROM MYSQL: Test verifies logging to PostgreSQL**
    - **Validates: Requirements 6.1, 6.4**

  - [ ]* 6.4 Write property test for error queue population
    - **Property 12: Error Queue Population**
    - **⚠️ NO DELETION FROM MYSQL: Error queue is in PostgreSQL**
    - **Validates: Requirements 7.4**

- [x] 7. Implement Concurrency Control and Error Handling
  - [x] 7.1 Implement sync locking mechanism
    - Use cache-based locking to prevent concurrent syncs
    - Implement acquireLock() and releaseLock() methods
    - Return appropriate error when sync already running
    - **⚠️ NO DELETION FROM MYSQL: Locking uses cache, not MySQL**
    - _Requirements: 5.4_

  - [x] 7.2 Implement retry logic with exponential backoff
    - Implement retryable error detection
    - Implement exponential backoff (1s, 2s, 4s, 8s, max 30s)
    - Max 5 retry attempts before failing
    - **⚠️ NO DELETION FROM MYSQL: Retry re-reads from MySQL, writes to PostgreSQL**
    - _Requirements: 7.1, 7.2_

  - [x] 7.3 Implement error threshold alerting
    - Track consecutive failures per configuration
    - Pause sync when threshold exceeded
    - Log critical alert
    - **⚠️ NO DELETION FROM MYSQL: Alerting does not modify MySQL**
    - _Requirements: 7.6_

  - [ ]* 7.4 Write property test for concurrent sync prevention
    - **Property 11: Concurrent Sync Prevention**
    - **⚠️ NO DELETION FROM MYSQL: Test verifies locking, not deletion**
    - **Validates: Requirements 5.4**

- [x] 8. Implement Configuration Management Service
  - [x] 8.1 Create TableSyncConfigurationService class
    - Implement create() with validation
    - Implement update() with validation
    - Implement delete() with cleanup (deletes config from PostgreSQL only)
    - Implement getAll() and getById()
    - **⚠️ NO DELETION FROM MYSQL: Config CRUD operates on PostgreSQL only**
    - _Requirements: 8.1, 8.6_

  - [x] 8.2 Implement configuration validation
    - Validate source table exists in MySQL (READ-ONLY check)
    - Validate column mappings reference valid columns
    - Validate batch size within allowed range
    - Validate cron expression format
    - **⚠️ NO DELETION FROM MYSQL: Validation only reads MySQL schema**
    - _Requirements: 1.2, 8.6_

  - [ ]* 8.3 Write property test for configuration round-trip
    - **Property 1: Configuration Round-Trip Consistency**
    - **⚠️ NO DELETION FROM MYSQL: Test operates on PostgreSQL config table**
    - **Validates: Requirements 1.4, 8.6**

- [x] 9. Checkpoint - Ensure all service tests pass
  - Ensure all tests pass, ask the user if questions arise.
  - **⚠️ VERIFY: No test or implementation deletes from MySQL**

- [x] 10. Implement API Controllers
  - [x] 10.1 Create TableSyncConfigurationController
    - Implement index() - list all configurations
    - Implement store() - create new configuration
    - Implement show() - get single configuration
    - Implement update() - update configuration
    - Implement destroy() - delete configuration (from PostgreSQL only)
    - **⚠️ NO DELETION FROM MYSQL: All CRUD operates on PostgreSQL config table**
    - _Requirements: 8.1_

  - [x] 10.2 Create TableSyncController
    - Implement sync() - trigger sync for specific table (READ MySQL, WRITE PostgreSQL)
    - Implement syncAll() - trigger sync for all enabled tables
    - Implement status() - get sync status for table
    - Implement logs() - get sync logs with filters
    - Implement errors() - get error queue with filters
    - Implement retryError() - retry specific error
    - **⚠️ NO DELETION FROM MYSQL: Sync reads from MySQL, writes to PostgreSQL**
    - _Requirements: 5.1, 5.6, 8.2, 8.3_

  - [x] 10.3 Register API routes
    - Add routes for configuration CRUD
    - Add routes for sync operations
    - Add routes for logs and errors
    - Apply authentication middleware
    - **⚠️ NO DELETION FROM MYSQL: No route triggers MySQL deletion**
    - _Requirements: 8.1, 8.2, 8.3_

- [x] 11. Implement Scheduled Jobs
  - [x] 11.1 Create TableSyncJob class
    - Accept configuration ID parameter
    - Call GenericSyncService::syncTable()
    - Handle errors and logging
    - **⚠️ NO DELETION FROM MYSQL: Job reads from MySQL, writes to PostgreSQL**
    - _Requirements: 5.2_

  - [x] 11.2 Register scheduled syncs in console kernel
    - Read enabled configurations with schedules
    - Register each as scheduled job
    - **⚠️ NO DELETION FROM MYSQL: Scheduled jobs only sync, never delete**
    - _Requirements: 5.2, 5.3_

  - [x] 11.3 Create artisan command for manual sync
    - Accept table name or configuration ID
    - Support --all flag for all tables
    - Display progress and results
    - **⚠️ NO DELETION FROM MYSQL: Command syncs data, never deletes from MySQL**
    - _Requirements: 5.1, 5.6_

- [x] 12. Implement React Dashboard Components
  - [x] 12.1 Create TableSyncConfigurationList component
    - Display all configurations with status
    - Show pending count, last sync time
    - Add/Edit/Delete actions (delete removes config from PostgreSQL only)
    - **⚠️ NO DELETION FROM MYSQL: UI manages PostgreSQL config, not MySQL data**
    - _Requirements: 8.4, 8.5_

  - [x] 12.2 Create TableSyncConfigurationForm component
    - Form for creating/editing configurations
    - Table name input with validation
    - Column mapping editor
    - Batch size and schedule inputs
    - **⚠️ NO DELETION FROM MYSQL: Form saves to PostgreSQL config table**
    - _Requirements: 8.4_

  - [x] 12.3 Create TableSyncDashboard component
    - Overview of all table syncs
    - Status indicators (idle, running, failed)
    - Quick actions (sync now, view logs)
    - **⚠️ NO DELETION FROM MYSQL: Dashboard displays status, sync action reads MySQL**
    - _Requirements: 6.5, 8.5_

  - [x] 12.4 Create TableSyncLogsView component
    - Filterable log table
    - Filter by table, date range, status
    - Error details expansion
    - **⚠️ NO DELETION FROM MYSQL: Logs are read from PostgreSQL**
    - _Requirements: 6.2, 6.6_

  - [x] 12.5 Create frontend API service
    - Implement all API calls for table sync
    - Handle errors and loading states
    - **⚠️ NO DELETION FROM MYSQL: API calls never trigger MySQL deletion**
    - _Requirements: 8.1, 8.2, 8.3_

- [x] 13. Final Checkpoint - Integration testing
  - Ensure all tests pass, ask the user if questions arise.
  - Verify end-to-end sync workflow
  - Verify API endpoints work correctly
  - Verify dashboard displays correct data
  - **⚠️ CRITICAL VERIFICATION: Confirm no code path deletes from MySQL source tables**

## Notes

- Tasks marked with `*` are optional property-based tests that can be skipped for faster MVP
- Property 6 (Source Data Preservation) is critical and should always be tested
- The module reuses existing database connections configured in the application
- All sync operations use the existing retry and error handling patterns from alerts-data-pipeline
- Frontend components follow existing React patterns in the application

## ⚠️ CRITICAL CONSTRAINT: NO MYSQL DELETION

**This module MUST NEVER delete any records from MySQL source tables.**

Allowed MySQL operations:
- SELECT (read data for sync)
- UPDATE (only synced_at marker column)
- ALTER TABLE ADD COLUMN (only to add sync marker column)

Forbidden MySQL operations:
- DELETE
- TRUNCATE
- DROP TABLE
- ALTER TABLE DROP COLUMN

All data removal operations (if any) must only target PostgreSQL tables.
