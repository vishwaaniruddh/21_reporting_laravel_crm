# Implementation Plan: MySQL Alerts Age-Based Cleanup

## Overview

This implementation plan breaks down the age-based cleanup feature into discrete, incremental coding tasks. Each task builds on previous work and includes verification through tests. The implementation follows a safety-first approach with multiple verification layers to prevent accidental data loss.

**CRITICAL SAFETY NOTE:** This feature performs irreversible DELETE operations on production MySQL data. Every task must be implemented with extreme caution and thorough testing.

**⚠️ CRITICAL TESTING REQUIREMENT - TWO-PHASE APPROACH:**

**PHASE 1 - TESTING (alerts_2 table):**
- **ALL implementation and testing will be done on the `alerts_2` table**
- The production `alerts` table will NOT be touched during this phase
- Every task below must use `alerts_2` as the target table
- Configuration must default to `alerts_2`
- All tests, dry runs, and cleanup operations will only affect `alerts_2`
- This phase continues until all functionality is verified and approved

**PHASE 2 - PRODUCTION (alerts table):**
- Only after Phase 1 is complete and explicitly approved
- Configuration will be updated to target the production `alerts` table
- A separate task will be added for production migration
- All safety mechanisms remain in place

**Implementation Rule:** Every task that involves database operations MUST explicitly use `alerts_2` table during development.

## Tasks

- [-] 1. Set up database migrations and models
  - Create `cleanup_logs` migration with all required fields
  - Create `cleanup_batches` migration with foreign key to cleanup_logs
  - Create `emergency_stops` migration with service_name unique constraint
  - Create CleanupLog model with scopes and helper methods
  - Create CleanupBatch model with relationship to CleanupLog
  - Create EmergencyStop model with flag management methods
  - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 11.1, 11.2, 11.3, 11.5_

- [x] 1.1 Write unit tests for models
  - Test CleanupLog creation and field validation
  - Test CleanupBatch relationship to CleanupLog
  - Test EmergencyStop flag persistence
  - _Requirements: 5.1, 11.5_

- [ ] 2. Implement result value objects
  - Create CleanupResult class with readonly properties
  - Create BatchCleanupResult class with batch-specific fields
  - Create SafetyCheckResult class with validation results
  - Add helper methods for result inspection
  - _Requirements: 4.5, 9.1, 9.2, 9.3_

- [ ] 2.1 Write unit tests for result objects
  - Test CleanupResult construction and properties
  - Test BatchCleanupResult field access
  - Test SafetyCheckResult validation logic
  - _Requirements: 4.5_

- [ ] 3. Implement SafetyGate validator
  - Create SafetyGate class with validation methods
  - Implement validateAgeThreshold() method
  - Implement validateBatchSize() with min/max enforcement (10-1000)
  - Implement validateConnections() for MySQL and PostgreSQL
  - Implement validateAdminConfirmation() check
  - Implement validateSafetyChecks() orchestrator method
  - _Requirements: 1.1, 2.4, 4.1, 4.2, 4.3, 4.4, 4.6_

- [ ] 3.1 Write property test for age threshold validation
  - **Property 1: Age-based record identification**
  - **Validates: Requirements 1.1**

- [ ] 3.2 Write property test for batch size validation
  - **Property 4: Batch size validation**
  - **Validates: Requirements 2.4**

- [ ] 3.3 Write unit tests for safety gate
  - Test connection validation with unavailable databases
  - Test admin confirmation requirement
  - Test safety check orchestration
  - _Requirements: 4.3, 4.6_

- [ ] 4. Implement PostgresVerifier service
  - Create PostgresVerifier class with PartitionManager dependency
  - Implement getPartitionTableName() using receivedtime
  - Implement recordExistsInPartition() with PostgreSQL query
  - Implement verifyRecordExists() for single record
  - Implement verifyRecordsBatch() for batch verification
  - Handle partition table not found errors gracefully
  - _Requirements: 3.1, 3.2, 3.3, 3.4_

- [ ] 4.1 Write property test for partition name calculation
  - **Property 6: Partition table name calculation**
  - **Validates: Requirements 3.2**

- [ ] 4.2 Write property test for PostgreSQL verification
  - **Property 5: PostgreSQL verification before deletion**
  - **Validates: Requirements 3.1**

- [ ] 4.3 Write property test for skipping unverified records
  - **Property 7: Skipping unverified records**
  - **Validates: Requirements 3.4**

- [ ] 4.4 Write unit tests for PostgresVerifier
  - Test partition name format for various dates
  - Test handling of missing partition tables
  - Test batch verification with mixed results
  - _Requirements: 3.2, 3.4_

- [ ] 5. Implement CleanupLogger service
  - Create CleanupLogger class
  - Implement logCleanupStart() returning log ID
  - Implement logCleanupComplete() updating log entry
  - Implement logBatch() for batch details
  - Implement logSkippedRecords() with reasons
  - Implement getCleanupHistory() with 90-day default
  - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6_

- [ ] 5.1 Write property test for logging completeness
  - **Property 9: Cleanup operation logging completeness**
  - **Validates: Requirements 5.1, 5.2, 5.3**

- [ ] 5.2 Write unit tests for CleanupLogger
  - Test log entry creation with all fields
  - Test batch logging with parent relationship
  - Test skipped records logging
  - Test history retrieval for 90 days
  - _Requirements: 5.1, 5.4, 5.5, 5.6_

- [ ] 6. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 7. Implement core AgeBasedCleanupService (Part 1: Configuration and Setup)
  - Create AgeBasedCleanupService class with dependencies
  - **⚠️ CRITICAL: Add targetTable property with default value 'alerts_2'**
  - Add configurable properties: ageThresholdHours (default: 48), batchSize (default: 100)
  - Add configurable properties: maxBatchesPerRun (default: 50), delayBetweenBatchesMs (default: 100)
  - Add clear comments marking configuration locations: "⚠️ CONFIGURABLE: Change age threshold here"
  - **Add clear comment: "⚠️ CRITICAL: Testing on alerts_2, change to 'alerts' for production"**
  - Implement setAgeThreshold() with minimum 24-hour enforcement
  - Implement setBatchSize() with 10-1000 range enforcement
  - **Implement setTargetTable() with validation (must be 'alerts' or 'alerts_2')**
  - Implement constructor with dependency injection
  - _Requirements: 1.2, 2.1, 2.2, 2.3, 2.4, 7.1, 7.2, 7.3, 14.1, 14.3, 14.4_

- [ ] 7.1 Write property test for age threshold minimum enforcement
  - **Property 11: Age threshold minimum enforcement**
  - **Validates: Requirements 7.3**

- [ ] 7.2 Write unit tests for configuration
  - Test default configuration values
  - **Test default targetTable is 'alerts_2'**
  - Test setAgeThreshold() with valid and invalid values
  - Test setBatchSize() with valid and invalid values
  - **Test setTargetTable() accepts only 'alerts' or 'alerts_2'**
  - Test configuration comments are present in code
  - _Requirements: 1.2, 2.2, 7.2, 7.4_

- [ ] 8. Implement core AgeBasedCleanupService (Part 2: Record Identification)
  - **⚠️ CRITICAL: Implement getEligibleRecords() querying the configured targetTable (alerts_2 during testing)**
  - Use WHERE receivedtime < NOW() - INTERVAL age_threshold HOUR
  - Add LIMIT clause to prevent full table scans
  - Use indexed query on receivedtime column
  - Handle timezone conversions correctly
  - Return Collection of eligible records
  - **Ensure all queries use $this->targetTable, never hardcode 'alerts'**
  - _Requirements: 1.1, 1.3, 1.4, 1.5, 15.1, 15.2_

- [ ] 8.1 Write property test for timezone-aware age calculation
  - **Property 2: Timezone-aware age calculation**
  - **Validates: Requirements 1.4**

- [ ] 8.2 Write unit tests for record identification
  - **Test queries target alerts_2 table, not alerts**
  - Test query uses indexed receivedtime column
  - Test query includes LIMIT clause
  - Test timezone handling with various timezones
  - _Requirements: 1.3, 15.1, 15.2_

- [ ] 9. Implement core AgeBasedCleanupService (Part 3: Verification and Deletion)
  - Implement verifyRecordsInPostgres() using PostgresVerifier
  - Separate verified and missing records
  - **⚠️ CRITICAL: Implement deleteRecords() with transaction support, using $this->targetTable**
  - Use DELETE with LIMIT to prevent full table scans
  - Commit each batch as separate transaction
  - Add configurable delay between batches
  - **Ensure DELETE queries use $this->targetTable, never hardcode 'alerts'**
  - _Requirements: 2.5, 3.1, 3.4, 8.1, 8.2, 15.2, 15.3_

- [ ] 9.1 Write property test for batch size enforcement
  - **Property 3: Batch size enforcement**
  - **Validates: Requirements 2.1**

- [ ] 9.2 Write unit tests for deletion
  - **Test DELETE operations target alerts_2 table, not alerts**
  - Test transaction commit per batch
  - Test DELETE query includes LIMIT
  - Test delay between batches
  - _Requirements: 2.5, 8.1, 15.2, 15.3_

- [ ] 10. Implement core AgeBasedCleanupService (Part 4: Batch Processing)
  - Implement processBatch() orchestrating single batch
  - Check emergency stop flag before processing
  - Get eligible records for batch
  - Verify records in PostgreSQL
  - Delete verified records
  - Log batch results
  - Return BatchCleanupResult
  - _Requirements: 8.1, 8.4, 11.1_

- [ ] 10.1 Write property test for emergency stop check
  - **Property 15: Emergency stop check before each batch**
  - **Validates: Requirements 11.1**

- [ ] 10.2 Write unit tests for batch processing
  - Test batch processing flow
  - Test emergency stop interrupts processing
  - Test batch result includes all fields
  - _Requirements: 8.1, 11.1_

- [ ] 11. Implement core AgeBasedCleanupService (Part 5: Main Cleanup Method)
  - Implement cleanupOldRecords() main method
  - Run safety checks via SafetyGate
  - Process batches up to maxBatchesPerRun limit
  - Track consecutive failures
  - Stop after 3 consecutive failures
  - Log all operations via CleanupLogger
  - Return CleanupResult
  - _Requirements: 4.5, 8.4, 9.1, 9.3_

- [ ] 11.1 Write property test for safety check failure prevents deletion
  - **Property 8: Safety check failure prevents deletion**
  - **Validates: Requirements 4.5**

- [ ] 11.2 Write property test for maximum batches per run
  - **Property 12: Maximum batches per run limit**
  - **Validates: Requirements 8.4**

- [ ] 11.3 Write property test for consecutive failure threshold
  - **Property 14: Consecutive failure threshold**
  - **Validates: Requirements 9.3**

- [ ] 11.4 Write unit tests for main cleanup method
  - Test safety checks prevent cleanup
  - Test max batches limit enforcement
  - Test consecutive failure handling
  - Test cleanup result accuracy
  - _Requirements: 4.5, 8.4, 9.1, 9.3_

- [ ] 12. Implement error handling and recovery
  - Implement retry logic for failed batches (up to 2 retries)
  - Implement exponential backoff for retries
  - Implement error queue for repeatedly failed batches
  - Handle MySQL connection errors gracefully
  - Handle PostgreSQL connection errors gracefully
  - Rollback failed batch transactions
  - _Requirements: 9.1, 9.2, 9.4, 9.5_

- [ ] 12.1 Write unit tests for error handling
  - Test retry logic with 2 attempts
  - Test exponential backoff timing
  - Test error queue population
  - Test connection error handling
  - Test transaction rollback
  - _Requirements: 9.1, 9.2, 9.4, 9.5_

- [ ] 13. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 14. Implement dry run mode
  - Add dryRun parameter to cleanupOldRecords()
  - Implement preview logic without deletion
  - Calculate record count and age distribution
  - Show oldest and newest records
  - Verify PostgreSQL existence for preview
  - Calculate total data size
  - Return preview result without modifying database
  - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6_

- [ ] 14.1 Write property test for dry run mode safety
  - **Property 10: Dry run mode safety**
  - **Validates: Requirements 6.1, 6.6**

- [ ] 14.2 Write unit tests for dry run mode
  - Test dry run returns preview data
  - Test dry run shows oldest/newest records
  - Test dry run includes verification statistics
  - Test dry run calculates data size
  - **Test dry run never modifies alerts_2 table**
  - _Requirements: 6.2, 6.3, 6.4, 6.5, 6.6_

- [ ] 15. Implement emergency stop mechanism
  - Implement isEmergencyStopped() checking database flag
  - Implement setEmergencyStop() updating database flag
  - Check emergency stop before each batch in processBatch()
  - Log emergency stop events
  - Send alert when emergency stop is triggered
  - Persist flag across service restarts
  - _Requirements: 11.1, 11.2, 11.3, 11.4, 11.5_

- [ ] 15.1 Write property test for emergency stop persistence
  - **Property 16: Emergency stop persistence**
  - **Validates: Requirements 11.2**

- [ ] 15.2 Write unit tests for emergency stop
  - Test emergency stop flag persistence
  - Test emergency stop prevents cleanup
  - Test emergency stop logging
  - Test emergency stop alerting
  - Test flag survives service restart
  - _Requirements: 11.2, 11.3, 11.4, 11.5_

- [ ] 16. Implement idempotent cleanup across runs
  - Track processed record IDs to prevent re-processing
  - Implement state persistence between runs
  - Ensure deleted records are not re-identified
  - Test cleanup can resume after interruption
  - _Requirements: 8.5_

- [ ] 16.1 Write property test for idempotent cleanup
  - **Property 13: Idempotent cleanup across runs**
  - **Validates: Requirements 8.5**

- [ ] 16.2 Write unit tests for idempotency
  - Test records deleted in previous run are not re-processed
  - Test cleanup resumes correctly after interruption
  - _Requirements: 8.5_

- [ ] 17. Implement CleanupController API endpoints
  - Create CleanupController with route definitions
  - Implement GET /api/cleanup/status endpoint
  - Implement POST /api/cleanup/preview endpoint (dry run)
  - Implement POST /api/cleanup/execute endpoint (requires admin confirmation)
  - Implement POST /api/cleanup/emergency-stop endpoint
  - Implement GET /api/cleanup/history endpoint
  - Implement PUT /api/cleanup/config endpoint
  - Add authentication and authorization middleware
  - _Requirements: 13.1, 13.2, 13.3, 13.4, 13.5_

- [ ] 17.1 Write unit tests for API endpoints
  - Test status endpoint returns current state
  - Test preview endpoint returns dry run results
  - Test execute endpoint requires admin confirmation
  - Test emergency stop endpoint sets flag
  - Test history endpoint returns cleanup logs
  - Test config endpoint updates configuration
  - Test authentication is required
  - _Requirements: 13.1, 13.2, 13.3, 13.4, 13.5_

- [ ] 18. Implement AgeBasedCleanupJob scheduled job
  - Create AgeBasedCleanupJob implementing ShouldQueue
  - Set timeout to 3600 seconds (1 hour)
  - Set tries to 1 (no automatic retry)
  - Implement handle() method calling AgeBasedCleanupService
  - Check if previous run is still in progress
  - Skip run if previous run is active
  - Implement failed() method for error handling
  - Log job start and completion
  - _Requirements: 12.1, 12.2, 12.3, 12.4, 12.5_

- [ ] 18.1 Write property test for concurrent run prevention
  - **Property 17: Concurrent run prevention**
  - **Validates: Requirements 12.4**

- [ ] 18.2 Write unit tests for scheduled job
  - Test job executes cleanup service
  - Test job skips if previous run active
  - Test job timeout configuration
  - Test job failure handling
  - Test job logging
  - _Requirements: 12.1, 12.4, 12.5_

- [ ] 19. Implement performance monitoring and metrics
  - Track average time per batch deletion
  - Track total records deleted per run
  - Track MySQL table size before and after
  - Calculate cleanup throughput (records per minute)
  - Expose metrics via API endpoint
  - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5_

- [ ] 19.1 Write unit tests for metrics
  - Test batch timing tracking
  - Test record count tracking
  - Test table size tracking
  - Test throughput calculation
  - Test metrics API endpoint
  - _Requirements: 10.1, 10.3, 10.4, 10.5_

- [ ] 20. Integrate with ConfigurationService
  - Add cleanup configuration keys to ConfigurationService
  - **⚠️ CRITICAL: Add 'cleanup.target_table' configuration key with default 'alerts_2'**
  - Implement runtime configuration updates
  - Add validation for cleanup configuration
  - **Add validation that target_table can only be 'alerts' or 'alerts_2'**
  - Test configuration persistence via cache
  - Test configuration reload without restart
  - _Requirements: 2.2, 7.1, 7.5, 14.3, 14.4_

- [ ] 20.1 Write unit tests for configuration integration
  - Test configuration keys are registered
  - **Test 'cleanup.target_table' defaults to 'alerts_2'**
  - Test runtime configuration updates
  - Test configuration validation
  - **Test target_table validation rejects invalid table names**
  - Test configuration persistence
  - _Requirements: 2.2, 7.5_

- [ ] 21. Add Laravel scheduler configuration
  - Register AgeBasedCleanupJob in app/Console/Kernel.php
  - Set default schedule to every 6 hours: ->cron('0 */6 * * *')
  - Make schedule configurable via ConfigurationService
  - Add off-peak hours preference
  - Test scheduler registration
  - _Requirements: 12.1, 12.2, 12.3_

- [ ] 21.1 Write unit tests for scheduler configuration
  - Test job is registered in scheduler
  - Test schedule is configurable
  - Test off-peak hours preference
  - _Requirements: 12.1, 12.2, 12.3_

- [ ] 22. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 23. Create admin dashboard views (optional)
  - Create cleanup status dashboard view
  - Add cleanup configuration form
  - Add dry run preview button
  - Add manual cleanup trigger button
  - Add emergency stop button
  - Display cleanup history table
  - _Requirements: 13.1, 13.2, 13.3, 13.4, 13.5_

- [ ] 24. Write integration tests
  - **⚠️ CRITICAL: Test end-to-end cleanup with real databases using alerts_2 table**
  - Test scheduled job execution
  - Test API endpoint integration
  - Test emergency stop during active cleanup
  - Test concurrent run prevention
  - Test configuration changes during operation
  - **Verify alerts table is never touched during any test**
  - _Requirements: All requirements_

- [ ] 25. Write documentation
  - Document configuration options in README
  - Document API endpoints with examples
  - Document safety mechanisms and verification process
  - Document emergency procedures
  - Document monitoring and metrics
  - Add code comments for configuration locations
  - _Requirements: 14.1, 14.2, 14.5_

- [ ] 26. Final checkpoint - Complete testing and validation
  - Run full test suite
  - Verify all properties pass with 100+ iterations
  - **⚠️ CRITICAL: Test with production-like data volumes on alerts_2**
  - Verify performance targets are met
  - Ensure all safety checks are working
  - **Verify alerts table has never been modified**
  - **Verify all operations only affected alerts_2 table**
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 27. **⚠️ PHASE 2 PREPARATION - Production Migration (DO NOT EXECUTE WITHOUT APPROVAL)**
  - Create migration checklist document
  - Document configuration changes needed (alerts_2 → alerts)
  - Create rollback plan
  - Document monitoring requirements
  - **This task is for planning only - execution requires explicit approval**
  - _Requirements: All requirements_

## Notes

- All tasks are required for comprehensive implementation
- Each task references specific requirements for traceability
- Property tests validate universal correctness properties
- Unit tests validate specific examples and edge cases
- **CRITICAL**: This feature deletes production data - implement with extreme caution
- All deletion operations must be verified through multiple safety gates
- Configuration locations are clearly marked with "⚠️ CONFIGURABLE" comments
- Batch size and age threshold can be modified in code, environment variables, or runtime API

**⚠️ CRITICAL TESTING PROTOCOL:**
- **PHASE 1**: ALL development uses `alerts_2` table
- **Target table is configurable** via `targetTable` property, environment variable, or configuration
- **Default configuration MUST be `alerts_2`** during development
- **Production `alerts` table will NOT be touched** until Phase 2 is explicitly approved
- Every database operation must use the configured `targetTable`, never hardcode table names
- Task 27 prepares for Phase 2 but does NOT execute the migration

