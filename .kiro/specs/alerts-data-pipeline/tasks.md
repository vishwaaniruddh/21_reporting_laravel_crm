# Implementation Plan: Alerts Data Pipeline

## Overview

This implementation plan breaks down the alerts data pipeline into discrete coding tasks. The pipeline syncs 2-3 million alert records from MySQL to PostgreSQL for reporting, with verification and automated cleanup. Each task builds incrementally toward a production-ready data synchronization system.

## ⚠️ CRITICAL WARNING - READ BEFORE EVERY TASK ⚠️

**NEVER DELETE, TRUNCATE, OR MODIFY DATA IN THE MySQL `alerts` TABLE!**

The MySQL `alerts` table contains PRODUCTION DATA with millions of records from Java applications. 

**ALLOWED operations on MySQL `alerts` table:**
- SELECT (read data)
- UPDATE `synced_at` and `sync_batch_id` columns ONLY (sync markers)

**FORBIDDEN operations on MySQL `alerts` table:**
- DELETE
- TRUNCATE  
- DROP
- ALTER (except adding sync columns)
- UPDATE on any column except `synced_at` and `sync_batch_id`

**For testing:**
- Use existing MySQL data - DO NOT create test data in MySQL alerts
- Clean up PostgreSQL tables only
- Reset sync markers (`synced_at`, `sync_batch_id`) after tests - DO NOT delete rows

**Cleanup operations (Task 6) are the ONLY exception** - and they require:
1. Records must be verified as synced to PostgreSQL
2. Records must be older than retention period
3. Cleanup must be explicitly triggered by admin

## Tasks

- [ ] 1. Set up database schema and models
  - ⚠️ **WARNING: DO NOT DELETE/TRUNCATE MySQL alerts table!**
  - [x] 1.1 Add sync tracking columns to MySQL alerts table
    - Add `synced_at` TIMESTAMP column (nullable)
    - Add `sync_batch_id` BIGINT column (nullable)
    - Add indexes on both columns for efficient queries
    - ⚠️ Only ADD columns - never modify existing data
    - _Requirements: 1.3_

  - [x] 1.2 Create sync_batches table in MySQL
    - Create migration for sync_batches table
    - Include: id, start_id, end_id, records_count, status, timestamps
    - Add indexes on status and created_at
    - _Requirements: 2.1_

  - [x] 1.3 Create PostgreSQL alerts table migration
    - Mirror MySQL alerts structure exactly
    - Add synced_at and sync_batch_id columns
    - Create appropriate indexes for reporting queries
    - _Requirements: 1.2_

  - [x] 1.4 Create sync_logs table in PostgreSQL
    - Track all sync/verify/cleanup operations
    - Include: batch_id, operation, records_affected, status, duration
    - _Requirements: 2.1_

  - [x] 1.5 Create Laravel models for sync operations
    - Alert model (MySQL) with unsynced scope
    - SyncedAlert model (PostgreSQL)
    - SyncBatch model (MySQL)
    - SyncLog model (PostgreSQL)
    - _Requirements: 1.1, 1.2_

- [x] 2. Implement batch sync engine
  - ⚠️ **WARNING: DO NOT DELETE/TRUNCATE MySQL alerts table!**
  - ⚠️ **Only UPDATE synced_at and sync_batch_id columns in MySQL alerts**
  - [x] 2.1 Create SyncService class
    - Implement batch fetching from MySQL (configurable batch size)
    - Implement bulk insert to PostgreSQL
    - Handle transaction boundaries per batch
    - ⚠️ READ-ONLY from MySQL alerts (except sync markers)
    - _Requirements: 1.1, 1.2, 1.5_

  - [x] 2.2 Create SyncJob (Laravel Queue Job)
    - Fetch unsynced records by ID order
    - Process in batches with progress tracking
    - Update sync markers after successful insert
    - Implement checkpoint/resume capability
    - ⚠️ Only UPDATE synced_at/sync_batch_id - NEVER DELETE
    - _Requirements: 1.1, 1.4, 7.3_

  - [x] 2.3 Write property test for data preservation

    - **Property 1: Data Preservation on Sync**
    - ⚠️ Use existing MySQL data for testing - DO NOT create/delete test data
    - **Validates: Requirements 1.2**

  - [x] 2.4 Write property test for sync marker consistency

    - **Property 2: Sync Marker Consistency**
    - ⚠️ Use existing MySQL data for testing - DO NOT create/delete test data
    - **Validates: Requirements 1.3**

- [x] 3. Checkpoint - Verify sync engine works
  - ⚠️ **WARNING: DO NOT DELETE/TRUNCATE MySQL alerts table!**
  - Run sync on existing MySQL data (DO NOT create test data)
  - Verify records appear in PostgreSQL
  - Clean up PostgreSQL only after tests
  - Reset sync markers only (UPDATE, not DELETE)
  - Ensure all tests pass, ask the user if questions arise.

- [x] 4. Implement error handling and resilience
  - ⚠️ **WARNING: DO NOT DELETE/TRUNCATE MySQL alerts table!**
  - [x] 4.1 Add transaction rollback on failure
    - Wrap PostgreSQL inserts in transactions
    - Rollback on any failure, no partial batches
    - Update batch status to 'failed' with error message
    - ⚠️ Rollback affects PostgreSQL only - MySQL alerts unchanged
    - _Requirements: 1.4, 7.2_

  - [x] 4.2 Implement retry with exponential backoff
    - Add retry logic for connection failures
    - Implement exponential backoff (1s, 2s, 4s, 8s, max 30s)
    - Max 5 retries before marking batch failed
    - _Requirements: 7.1_

  - [x] 4.3 Add error queue for failed records
    - Create failed_sync_records table
    - Move repeatedly failing records to error queue
    - Provide admin interface to review/retry
    - ⚠️ Error queue is separate table - NEVER modify MySQL alerts
    - _Requirements: 7.5_

  - [x] 4.4 Write property test for transaction rollback

    - **Property 3: Transaction Rollback on Failure**
    - ⚠️ Use existing MySQL data for testing - DO NOT create/delete test data
    - **Validates: Requirements 1.4, 7.2**

  - [x] 4.5 Write property test for connection failure retry

    - **Property 10: Connection Failure Retry**
    - ⚠️ Use existing MySQL data for testing - DO NOT create/delete test data
    - **Validates: Requirements 7.1**

- [x] 5. Implement verification service
  - ⚠️ **WARNING: DO NOT DELETE/TRUNCATE MySQL alerts table!**
  - [x] 5.1 Create VerificationService class
    - Compare record counts per batch
    - Validate all synced IDs exist in PostgreSQL
    - Generate verification reports
    - ⚠️ READ-ONLY operations on MySQL alerts
    - _Requirements: 3.1, 3.2_

  - [x] 5.2 Create VerifyBatchJob
    - Run verification on completed batches
    - Update batch status to 'verified' or 'failed'
    - Log verification results
    - ⚠️ Only UPDATE sync_batches status - NEVER touch MySQL alerts
    - _Requirements: 3.1, 3.4_

  - [x] 5.3 Write property test for verification accuracy

    - **Property 5: Verification Accuracy**
    - ⚠️ Use existing MySQL data for testing - DO NOT create/delete test data
    - **Validates: Requirements 3.1**

- [x] 6. Implement cleanup job
  - ⚠️ **EXTREME CAUTION: This is the ONLY task that deletes from MySQL alerts!**
  - ⚠️ **Cleanup requires: verified + older than retention period + admin trigger**
  - [x] 6.1 Create CleanupService class
    - Only delete verified records older than retention period
    - Delete in batches to prevent long locks
    - Log all cleanup operations
    - ⚠️ TRIPLE-CHECK verification before ANY delete
    - ⚠️ Require explicit admin confirmation
    - _Requirements: 4.1, 4.2, 4.3_

  - [x] 6.2 Create CleanupJob (Laravel Queue Job)
    - Check verification status before delete
    - Respect configurable retention period
    - Stop on connection issues
    - ⚠️ NEVER auto-run cleanup - require manual trigger
    - ⚠️ Log every single delete operation
    - _Requirements: 3.5, 4.1, 4.6_

  - [x] 6.3 Write property test for cleanup safety gate

    - **Property 6: Cleanup Safety Gate**
    - ⚠️ Test with mock/fake data ONLY - NEVER test cleanup on production
    - **Validates: Requirements 3.2, 3.3, 3.5, 4.1, 4.2**

- [x] 7. Checkpoint - Verify full pipeline works
  - ⚠️ **WARNING: DO NOT DELETE/TRUNCATE MySQL alerts table!**
  - Test sync → verify cycle (skip cleanup in testing)
  - Verify no data loss
  - ⚠️ DO NOT test cleanup on production data
  - Ensure all tests pass, ask the user if questions arise.

- [x] 8. Implement sync logging and monitoring
  - ⚠️ **WARNING: DO NOT DELETE/TRUNCATE MySQL alerts table!**
  - [x] 8.1 Create SyncLogService
    - Log all sync operations with timestamps
    - Track records processed, duration, status
    - Store error details for failed operations
    - ⚠️ Logs go to PostgreSQL sync_logs - not MySQL
    - _Requirements: 2.1, 2.4_

  - [x] 8.2 Create monitoring API endpoints
    - GET /api/pipeline/status - current status
    - GET /api/pipeline/sync-logs - history with pagination
    - POST /api/pipeline/sync/trigger - manual trigger
    - POST /api/pipeline/cleanup/trigger - manual cleanup
    - ⚠️ Cleanup trigger requires admin auth + confirmation
    - _Requirements: 2.2, 2.3_

  - [x] 8.3 Write property test for sync log completeness

    - **Property 4: Sync Log Completeness**
    - ⚠️ Use existing MySQL data for testing - DO NOT create/delete test data
    - **Validates: Requirements 2.1, 2.4, 4.4**

- [x] 9. Implement reporting from PostgreSQL
  - ⚠️ **WARNING: DO NOT DELETE/TRUNCATE MySQL alerts table!**
  - ⚠️ **Reports query PostgreSQL ONLY - never MySQL alerts**
  - [x] 9.1 Create ReportService class
    - Query PostgreSQL exclusively for reports
    - Support filtering by date, type, severity
    - Generate summary statistics
    - ⚠️ READ from PostgreSQL only
    - _Requirements: 5.1, 5.2, 5.3_

  - [x] 9.2 Create report API endpoints
    - GET /api/reports/daily - daily report
    - GET /api/reports/summary - summary with filters
    - GET /api/reports/export/csv - CSV export
    - GET /api/reports/export/pdf - PDF export
    - _Requirements: 5.2, 5.4_

  - [ ]* 9.3 Write property test for report filter accuracy
    - **Property 7: Report Filter Accuracy**
    - ⚠️ Test against PostgreSQL data only
    - **Validates: Requirements 5.2**

  - [ ]* 9.4 Write property test for statistics correctness
    - **Property 8: Report Statistics Correctness**
    - ⚠️ Test against PostgreSQL data only
    - **Validates: Requirements 5.3**

- [x] 10. Implement configuration management
  - ⚠️ **WARNING: DO NOT DELETE/TRUNCATE MySQL alerts table!**
  - [x] 10.1 Create pipeline configuration
    - Environment variables for batch size, schedules
    - Retention period configuration
    - Alert thresholds configuration
    - _Requirements: 6.1, 6.2, 6.3, 6.4_

  - [x] 10.2 Create configuration API endpoints
    - GET /api/config/pipeline - get current config
    - PUT /api/config/pipeline - update config
    - _Requirements: 6.5_

- [x] 11. Set up Laravel scheduler
  - ⚠️ **WARNING: DO NOT DELETE/TRUNCATE MySQL alerts table!**
  - [x] 11.1 Configure sync job schedule
    - Schedule SyncJob to run every 15 minutes
    - Configure off-peak hours preference
    - _Requirements: 1.6_

  - [x] 11.2 Configure cleanup job schedule
    - Schedule CleanupJob to run daily at 2 AM
    - Configure verification check before cleanup
    - ⚠️ Cleanup should be DISABLED by default
    - ⚠️ Require explicit enable + admin approval
    - _Requirements: 4.5_

  - [x] 11.3 Configure verification job schedule
    - Schedule VerifyBatchJob after sync completion
    - _Requirements: 3.1_

- [-] 12. Build React monitoring dashboard
  - ⚠️ **WARNING: DO NOT DELETE/TRUNCATE MySQL alerts table!**
  - [x] 12.1 Create PipelineDashboard component
    - Display sync status (idle/running/failed)
    - Show progress for active sync jobs
    - Display records synced, pending, last sync time
    - _Requirements: 2.2, 2.3_

  - [x] 12.2 Create SyncHistoryTable component
    - Display sync log history with pagination
    - Show batch details, status, duration
    - Filter by date range and status
    - _Requirements: 2.5_

  - [x] 12.3 Create ReportingDashboard component
    - Report generation interface
    - Filter controls for date, type, severity
    - Export buttons for CSV/PDF
    - _Requirements: 5.2, 5.4_

  - [x] 12.4 Create ConfigurationPanel component
    - Display current pipeline settings
    - Allow editing batch size, schedules, retention
    - ⚠️ Cleanup enable/disable should require confirmation
    - _Requirements: 6.5_

- [x] 13. Final checkpoint - Full system test
  - ⚠️ **WARNING: DO NOT DELETE/TRUNCATE MySQL alerts table!**
  - Test complete pipeline with realistic data volume
  - Verify dashboard displays correct information
  - Test manual trigger and configuration changes
  - ⚠️ Skip cleanup testing on production - use staging only
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional property tests that can be skipped for faster MVP
- Each task references specific requirements for traceability
- Property tests validate universal correctness properties
- Checkpoints ensure incremental validation before proceeding
- The implementation uses Laravel's queue system for background processing
- All sync operations are designed to be resumable after interruption

## ⚠️ CRITICAL REMINDERS ⚠️

1. **MySQL `alerts` table is PRODUCTION DATA** - 2-3 million records
2. **NEVER use DELETE, TRUNCATE, or DROP on MySQL alerts**
3. **Only UPDATE `synced_at` and `sync_batch_id` columns**
4. **Tests must use existing data - no creating/deleting test records**
5. **Cleanup (Task 6) is the ONLY exception and requires triple verification**
6. **When in doubt, ASK before modifying MySQL alerts**
