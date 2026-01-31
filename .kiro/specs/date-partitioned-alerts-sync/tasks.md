# Implementation Plan: Date-Partitioned Alerts Sync

## Overview

This implementation plan breaks down the date-partitioned sync system into discrete coding tasks. The system reads alerts from MySQL and distributes them into date-specific PostgreSQL tables (e.g., `alerts_2026_01_08`), with automatic partition creation and cross-partition query routing.

## ⚠️ CRITICAL RULES - READ BEFORE EVERY TASK ⚠️

**0. Project Directory:**
- Laravel project is located at: `dual-database-app/`
- All Laravel commands, migrations, models, services must be created in this directory
- All file paths are relative to `dual-database-app/`
- Example: `dual-database-app/app/Services/DateExtractor.php`

**1. NEVER DELETE OR UPDATE MySQL alerts table:**
- MySQL alerts table is PRODUCTION DATA - READ ONLY
- Only SELECT operations allowed on MySQL alerts
- NO DELETE, UPDATE, TRUNCATE, DROP, or ALTER operations
- This is the source of truth - must remain untouched

**2. Property-Based Test Configuration:**
- Maximum 20 iterations per property test (not 100)
- Keep tests fast and focused

**3. Test Data Strategy:**
- NO need to create test data - use existing MySQL alerts as source
- Tests will read from real MySQL data
- Tests will create partitions in PostgreSQL test database

**4. PostgreSQL Data Retention:**
- If tests pass, DO NOT delete data from PostgreSQL partition tables
- Keep synced data in alerts_YYYY_MM_DD tables
- Only clean up on test failures if needed

## Tasks

**📁 Project Directory Note:**
All tasks below should be executed in the `dual-database-app/` directory. When creating files:
- Migrations: `dual-database-app/database/migrations/`
- Models: `dual-database-app/app/Models/`
- Services: `dual-database-app/app/Services/`
- Controllers: `dual-database-app/app/Http/Controllers/`
- Commands: `dual-database-app/app/Console/Commands/`
- Tests: `dual-database-app/tests/`
- React components: `dual-database-app/resources/js/components/`

- [x] 1. Create partition metadata infrastructure
  - [x] 1.1 Create partition_registry table migration
    - Create PostgreSQL migration for partition_registry table
    - Include: table_name, partition_date, record_count, timestamps
    - Add indexes on partition_date and table_name
    - _Requirements: 9.1, 9.2_

  - [x] 1.2 Create PartitionRegistry model
    - Create Laravel model for partition_registry table
    - Add methods for registering and querying partitions
    - Include record count update functionality
    - _Requirements: 9.1, 9.3, 9.5_

- [-] 2. Implement date extraction and partition naming
  - [x] 2.1 Create DateExtractor service
    - Extract date from MySQL receivedtime column
    - Format dates as YYYY_MM_DD for table names
    - Handle timezone conversions consistently
    - Validate and sanitize partition names
    - _Requirements: 1.1, 1.2, 7.1, 7.2, 7.3, 7.5_

  - [x] 2.2 Write property test for date extraction consistency

    - **Property 1: Date Extraction Consistency**
    - ⚠️ Use existing MySQL alerts data - NO test data creation
    - ⚠️ Max 20 iterations (not 100)
    - **Validates: Requirements 1.1, 1.2, 7.3**

  - [x] 2.3 Write property test for partition naming convention

    - **Property 7: Partition Naming Convention Compliance**
    - ⚠️ Use existing MySQL alerts data - NO test data creation
    - ⚠️ Max 20 iterations (not 100)
    - **Validates: Requirements 7.1, 7.2, 7.5**

- [-] 3. Implement partition table management
  - [x] 3.1 Create PartitionManager service
    - Check if partition table exists
    - Create partition table with base alerts schema
    - Create all necessary indexes on new partitions
    - Register new partitions in partition_registry
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 3.1, 3.2, 3.3_

  - [x] 3.2 Implement schema template system
    - Define base alerts table schema
    - Generate CREATE TABLE statements dynamically
    - Generate CREATE INDEX statements for partitions
    - Ensure schema consistency across partitions
    - _Requirements: 1.5, 3.1, 3.2, 3.3_

  - [ ] 3.3 Write property test for partition creation idempotency

    - **Property 2: Partition Table Creation Idempotency**
    - ⚠️ Use existing MySQL alerts data - NO test data creation
    - ⚠️ Max 20 iterations (not 100)
    - **Validates: Requirements 2.1, 2.2**

  - [ ]* 3.4 Write property test for schema consistency
    - **Property 3: Schema Consistency Across Partitions**
    - ⚠️ Use existing MySQL alerts data - NO test data creation
    - ⚠️ Max 20 iterations (not 100)
    - **Validates: Requirements 3.1, 3.2, 3.3**

- [x] 4. Checkpoint - Verify partition creation works
  - ⚠️ Test using existing MySQL alerts data
  - ⚠️ DO NOT delete MySQL alerts table
  - ⚠️ Keep PostgreSQL partition data if tests pass
  - Test creating partitions for various dates
  - Verify schema consistency across partitions
  - Check partition_registry is updated correctly
  - Ensure all tests pass, ask the user if questions arise.

- [x] 5. Implement date-grouped sync service
  - [x] 5.1 Create DateGroupedSyncService
    - Fetch alerts from MySQL in batches (read-only)
    - Group alerts by extracted date within batch
    - Process each date group separately
    - Insert alerts into appropriate partition tables
    - Update partition_registry record counts
    - _Requirements: 1.3, 4.1, 5.1, 5.2, 5.3, 5.4, 5.5_

  - [x] 5.2 Implement transaction handling per date group
    - Wrap each date group insert in transaction
    - Rollback on failure for that date group
    - Continue processing other date groups on failure
    - Log all sync operations
    - _Requirements: 5.3, 8.2, 8.4_

  - [ ]* 5.3 Write property test for read-only MySQL operations
    - **Property 4: Read-Only MySQL Operations**
    - ⚠️ Use existing MySQL alerts data - NO test data creation
    - ⚠️ Max 20 iterations (not 100)
    - ⚠️ Verify NO DELETE/UPDATE on MySQL alerts
    - **Validates: Requirements 4.1, 4.2, 4.3, 4.4**

  - [ ]* 5.4 Write property test for date group insertion atomicity
    - **Property 5: Date Group Insertion Atomicity**
    - ⚠️ Use existing MySQL alerts data - NO test data creation
    - ⚠️ Max 20 iterations (not 100)
    - **Validates: Requirements 5.3, 8.2**

  - [ ]* 5.5 Write property test for error isolation
    - **Property 9: Error Isolation Between Date Groups**
    - ⚠️ Use existing MySQL alerts data - NO test data creation
    - ⚠️ Max 20 iterations (not 100)
    - **Validates: Requirements 8.4**

- [x] 6. Implement partition query router
  - [x] 6.1 Create PartitionQueryRouter service
    - Accept date range parameters for queries
    - Identify all partition tables in date range
    - Build UNION ALL queries across partitions
    - Handle missing partitions gracefully
    - Aggregate results from multiple partitions
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5_

  - [x] 6.2 Implement query builder for cross-partition queries
    - Support filtering by alert_type, severity, terminal_id
    - Support ordering and pagination
    - Optimize query performance with partition pruning
    - Return results in consistent format
    - _Requirements: 10.2, 10.3, 10.4_

  - [ ]* 6.3 Write property test for cross-partition query completeness
    - **Property 6: Cross-Partition Query Completeness**
    - ⚠️ Use existing MySQL alerts data - NO test data creation
    - ⚠️ Max 20 iterations (not 100)
    - **Validates: Requirements 6.1, 6.2, 6.3, 6.4**

  - [ ]* 6.4 Write property test for query result format consistency
    - **Property 10: Query Result Format Consistency**
    - ⚠️ Use existing MySQL alerts data - NO test data creation
    - ⚠️ Max 20 iterations (not 100)
    - **Validates: Requirements 10.2, 10.3**

- [x] 7. Checkpoint - Verify end-to-end sync and query
  - ⚠️ Test using existing MySQL alerts data
  - ⚠️ DO NOT delete MySQL alerts table
  - ⚠️ Keep PostgreSQL partition data if tests pass
  - Test syncing alerts with multiple dates
  - Verify partitions are created automatically
  - Test querying across date ranges
  - Verify results are complete and correct
  - Ensure all tests pass, ask the user if questions arise.

- [x] 8. Implement error handling and retry logic
  - [x] 8.1 Add partition creation retry logic
    - Retry partition creation up to 3 times on failure
    - Log errors with detailed context
    - Move to error queue after max retries
    - _Requirements: 2.5, 8.1, 8.3_

  - [x] 8.2 Add error queue for failed records
    - Create table for failed partition operations
    - Store alerts that fail to sync
    - Provide retry mechanism for error queue
    - _Requirements: 8.3_

  - [x] 8.3 Implement alerting for partition failures
    - Send alerts when failures exceed threshold
    - Include partition name and error details
    - _Requirements: 8.5_

- [x] 9. Create API endpoints for partition management
  - [x] 9.1 Create partition sync trigger endpoint
    - POST /api/sync/partitioned/trigger
    - Trigger date-partitioned sync manually
    - Return sync status and results
    - _Requirements: 5.1_

  - [x] 9.2 Create partition listing endpoint
    - GET /api/sync/partitions
    - List all partition tables with metadata
    - Include record counts and date ranges
    - _Requirements: 9.4_

  - [x] 9.3 Create partition info endpoint
    - GET /api/sync/partitions/{date}
    - Get detailed info for specific partition
    - Include record count and sync history
    - _Requirements: 9.4_

  - [x] 9.4 Create partitioned query endpoint
    - GET /api/reports/partitioned/query
    - Query across date partitions with filters
    - Support date range, alert_type, severity filters
    - _Requirements: 6.1, 10.1_

- [x] 10. Implement partition metadata tracking
  - [x] 10.1 Add partition metadata updates
    - Update record_count after each sync
    - Track last_synced_at timestamp
    - Maintain accurate partition statistics
    - _Requirements: 9.2, 9.5_

  - [ ]* 10.2 Write property test for partition metadata accuracy
    - **Property 8: Partition Metadata Accuracy**
    - ⚠️ Use existing MySQL alerts data - NO test data creation
    - ⚠️ Max 20 iterations (not 100)
    - **Validates: Requirements 9.2, 9.5**

- [x] 11. Create Laravel command for partition sync
  - [x] 11.1 Create PartitionedSyncCommand
    - Artisan command: php artisan sync:partitioned
    - Support batch size configuration
    - Display progress and statistics
    - _Requirements: 5.1_

  - [x] 11.2 Add scheduling support
    - Configure command to run on schedule
    - Support off-peak hours configuration
    - _Requirements: 5.1_

- [x] 12. Update reporting services to use partition router
  - [x] 12.1 Modify ReportService to use PartitionQueryRouter
    - Replace direct table queries with router
    - Maintain backward compatibility
    - Support same filter parameters
    - _Requirements: 10.1, 10.2, 10.3, 10.5_

  - [x] 12.2 Update AlertsReportController
    - Use partition router for date-range queries
    - Handle missing partitions gracefully
    - Return consistent response format
    - _Requirements: 10.1, 10.5_

- [x] 13. Create React UI for partition management
  - [x] 13.1 Create PartitionManagementDashboard component
    - Display list of all partitions
    - Show record counts and dates
    - Provide manual sync trigger button
    - _Requirements: 9.4_

  - [x] 13.2 Add partition visualization
    - Chart showing records per partition
    - Timeline view of partition coverage
    - Highlight missing date ranges
    - _Requirements: 9.4_

- [x] 14. Add documentation and migration guide
  - [x] 14.1 Document partition sync process
    - Explain date-based partitioning
    - Document API endpoints
    - Provide usage examples
    - _Requirements: All_

  - [x] 14.2 Create migration guide
    - Steps to transition from single table
    - Historical data backfill process
    - Rollback procedures
    - _Requirements: All_

- [x] 15. Final checkpoint - Full system integration test
  - ⚠️ Test using existing MySQL alerts data
  - ⚠️ DO NOT delete MySQL alerts table
  - ⚠️ Keep PostgreSQL partition data if tests pass
  - Test complete sync pipeline with realistic data
  - Verify partition creation and querying
  - Test error handling and recovery
  - Validate reporting works correctly
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional property tests that can be skipped for faster MVP
- Each task references specific requirements for traceability
- Property tests validate universal correctness properties
- Checkpoints ensure incremental validation before proceeding
- The implementation extends existing sync infrastructure
- All MySQL operations are read-only (no deletes/updates)
- Partition tables are created dynamically as needed
- Query routing abstracts partition details from reports

## ⚠️ CRITICAL REMINDERS ⚠️

1. **Laravel project directory**: `dual-database-app/` - all work happens here
2. **MySQL alerts table is PRODUCTION DATA** - NEVER modify or delete
3. **Only SELECT operations** allowed on MySQL alerts table
4. **Use existing MySQL data** for all tests - no test data creation needed
5. **Max 20 iterations** for property-based tests
6. **Keep PostgreSQL partition data** if tests pass - don't delete
7. **When in doubt, ASK** before touching MySQL alerts table
