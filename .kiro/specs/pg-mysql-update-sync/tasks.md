# Implementation Plan: MySQL to PostgreSQL Update Synchronization

## Overview

This implementation creates a continuous synchronization worker that monitors the MySQL `alert_pg_update_log` table and propagates changes to PostgreSQL. The worker reads alert data from MySQL (source) and updates PostgreSQL (target), then marks log entries as processed. The implementation follows Laravel conventions and integrates with the existing dual-database architecture.

## Tasks

- [x] 1. Create database migration for alert_pg_update_log table
  - Create migration file for MySQL alert_pg_update_log table
  - Define schema with id, alert_id, status, created_at, updated_at, error_message, retry_count
  - Add indexes for status+created_at and alert_id
  - _Requirements: 1.2, 4.1, 4.2, 4.3, 4.4_

- [x] 2. Create Eloquent models and value objects
  - [x] 2.1 Create AlertUpdateLog model for alert_pg_update_log table
    - Define model with MySQL connection
    - Add fillable fields and casts
    - Add scopes for pending entries (status=1)
    - _Requirements: 1.2, 4.1_

  - [x] 2.2 Create SyncResult value object
    - Define readonly properties: success, alertId, errorMessage, duration
    - Add helper methods: isSuccess(), isFailed()
    - _Requirements: 2.3, 3.2_

- [x] 3. Implement UpdateLogMonitor service
  - [x] 3.1 Create UpdateLogMonitor service class
    - Implement fetchPendingEntries() to query status=1 entries
    - Order by created_at ascending (oldest first)
    - Apply batch size limit
    - Implement getPendingCount() for metrics
    - **CRITICAL: Only SELECT queries on MySQL alert_pg_update_log - no DELETE, TRUNCATE, or UPDATE on MySQL alerts table**
    - _Requirements: 1.1, 1.2, 1.3, 6.1_

  - [ ]* 3.2 Write property test for status filtering
    - **Property 1: Status-Based Query Filtering**
    - **Validates: Requirements 1.2**

  - [ ]* 3.3 Write property test for processing order
    - **Property 2: Processing Order Preservation**
    - **Validates: Requirements 1.3**

  - [ ]* 3.4 Write property test for batch size compliance
    - **Property 15: Batch Size Compliance**
    - **Validates: Requirements 6.1**

- [x] 4. Implement SyncLogger service
  - [x] 4.1 Create SyncLogger service class
    - Implement logCycleStart() with pending count
    - Implement logCycleComplete() with metrics
    - Implement logAlertSync() with duration and status
    - Implement logError() with context
    - Use Laravel Log facade with structured data
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5_

  - [ ]* 4.2 Write unit tests for logging methods
    - Test log message formatting
    - Test metric calculations
    - Test context data inclusion
    - _Requirements: 7.1, 7.2, 7.3_

- [x] 5. Implement AlertSyncService
  - [x] 5.1 Create AlertSyncService class with core sync logic
    - Implement syncAlert() method as main entry point
    - Implement fetchAlertFromMysql() with error handling
    - Implement updateAlertInPostgres() with upsert logic
    - Implement markLogEntryProcessed() to update status and timestamp in MySQL alert_pg_update_log
    - Handle missing alerts in MySQL (mark as failed)
    - **CRITICAL: Only SELECT queries on MySQL alerts table - NEVER DELETE, TRUNCATE, or UPDATE**
    - **CRITICAL: Only UPDATE queries on MySQL alert_pg_update_log table (to mark processed)**
    - **CRITICAL: All INSERT/UPDATE operations go to PostgreSQL alerts table only**
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 3.1, 3.2, 3.3, 4.1, 4.2, 4.3, 4.4_

  - [x] 5.2 Implement retry logic with exponential backoff
    - Create retryWithBackoff() method
    - Calculate delays: base_delay * (multiplier ^ attempt)
    - Cap maximum delay at 60 seconds
    - Retry on connection errors and query failures
    - _Requirements: 5.1_

  - [ ]* 5.3 Write property test for alert data consistency
    - **Property 5: Alert Data Consistency**
    - **Validates: Requirements 3.2**

  - [ ]* 5.4 Write property test for PostgreSQL timestamp update
    - **Property 6: PostgreSQL Timestamp Update**
    - **Validates: Requirements 3.3**

  - [ ]* 5.5 Write property test for log entry status transition
    - **Property 9: Log Entry Status Transition**
    - **Validates: Requirements 4.1, 4.2, 4.3**

  - [ ]* 5.6 Write property test for retry backoff monotonicity
    - **Property 12: Retry Backoff Monotonicity**
    - **Validates: Requirements 5.1**

  - [ ]* 5.7 Write property test for error isolation
    - **Property 13: Error Isolation**
    - **Validates: Requirements 5.2**

  - [ ]* 5.8 Write unit tests for error handling
    - Test alert not found in MySQL
    - Test PostgreSQL connection failure
    - Test transaction rollback on error
    - _Requirements: 2.4, 3.5, 5.2_

- [x] 6. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 7. Create UpdateSyncWorker console command
  - [x] 7.1 Create Laravel console command class
    - Define command signature with options: poll-interval, batch-size, max-retries
    - Inject UpdateLogMonitor, AlertSyncService, SyncLogger
    - Implement handle() method with infinite loop
    - Add graceful shutdown on SIGTERM/SIGINT
    - Log worker lifecycle events (startup, shutdown)
    - **CRITICAL: Ensure no DELETE, TRUNCATE, or UPDATE operations on MySQL alerts table**
    - _Requirements: 1.1, 1.4, 1.5, 6.1, 6.5, 7.5_

  - [x] 7.2 Implement main processing loop
    - Fetch pending entries using UpdateLogMonitor
    - Process each entry using AlertSyncService
    - Log cycle metrics using SyncLogger
    - Sleep for poll-interval when no entries found
    - Handle errors gracefully and continue processing
    - **CRITICAL: MySQL alerts table is READ-ONLY - only SELECT operations allowed**
    - _Requirements: 1.1, 1.3, 1.4, 5.2, 5.5, 7.1_

  - [ ]* 7.3 Write integration test for full sync cycle
    - Insert test log entries in MySQL
    - Run worker for one cycle
    - Verify PostgreSQL updates
    - Verify log entry status updates
    - _Requirements: 1.1, 1.2, 1.3, 2.1, 2.2, 2.3, 3.1, 3.2, 4.1, 4.2_

  - [ ]* 7.4 Write integration test for graceful shutdown
    - Start worker
    - Send SIGTERM
    - Verify current batch completes
    - Verify clean shutdown
    - _Requirements: 1.5_

- [x] 8. Register command and configure service provider
  - [x] 8.1 Register UpdateSyncWorker command in Kernel
    - Add command to app/Console/Kernel.php
    - _Requirements: 1.1_

  - [x] 8.2 Create service provider for sync services
    - Register UpdateLogMonitor as singleton
    - Register AlertSyncService with dependencies
    - Register SyncLogger as singleton
    - Configure default values from config
    - _Requirements: 6.1, 6.5_

  - [x] 8.3 Create configuration file for sync worker
    - Create config/update-sync.php
    - Define poll_interval, batch_size, max_retries
    - Define retry backoff configuration
    - _Requirements: 5.1, 6.1, 6.5_

- [x] 9. Create documentation
  - [x] 9.1 Create README for sync worker
    - Document command usage and options
    - Document configuration options
    - Document monitoring and troubleshooting
    - Provide examples of running the worker
    - _Requirements: 7.4, 7.5_

  - [x] 9.2 Add inline code documentation
    - Add PHPDoc comments to all public methods
    - Document parameters and return types
    - Document exceptions thrown
    - _Requirements: 5.4_

- [x] 10. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties
- Unit tests validate specific examples and edge cases
- The worker runs indefinitely and should be managed by a process supervisor (systemd, supervisor, etc.)
- **CRITICAL CONSTRAINT: MySQL alerts table is READ-ONLY - only SELECT operations allowed**
- **CRITICAL CONSTRAINT: No DELETE, TRUNCATE, or UPDATE operations on MySQL alerts table**
- **CRITICAL CONSTRAINT: Only UPDATE operations on MySQL alert_pg_update_log table (to mark processed)**
- PostgreSQL alerts table is the target for all INSERT/UPDATE operations
