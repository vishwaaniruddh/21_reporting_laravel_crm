# Requirements Document

## Introduction

A critical safety-focused cleanup system that automatically deletes old alert records from the MySQL alerts table to prevent database bloat and maintain optimal performance. The system ONLY deletes records that are older than 48 hours (configurable) and processes them in small batches (default: 100 records per batch) to prevent table locking and performance degradation. This module is designed with extreme caution as it performs irreversible DELETE operations on production data.

**⚠️ CRITICAL TESTING REQUIREMENT:**
- **PHASE 1 (TESTING)**: All development and testing will be performed on the `alerts_2` table
- **PHASE 2 (PRODUCTION)**: Only after thorough testing and validation on `alerts_2`, the system will be configured to work with the production `alerts` table
- **The production `alerts` table will NOT be modified or deleted from during the testing phase**
- This two-phase approach ensures complete safety before touching production data

## Glossary

- **MySQL_Alerts_Table**: The production MySQL table containing alert records that grows continuously (NOTE: During testing phase, this refers to `alerts_2` table; in production phase, this refers to `alerts` table)
- **Age_Threshold**: The minimum age (in hours) a record must have before it becomes eligible for deletion (default: 48 hours)
- **Cleanup_Batch_Size**: The number of records deleted in a single transaction to prevent long table locks (default: 100 records)
- **Age_Based_Cleanup_Service**: The service responsible for identifying and deleting old records based on age criteria
- **Receivedtime_Column**: The timestamp column used to determine record age (typically `receivedtime` or `created_at`)
- **Safety_Gate**: A multi-level verification system that prevents accidental deletion of important data
- **PostgreSQL_Verification**: A mandatory check that verifies records exist in PostgreSQL partitioned tables before deletion
- **Partition_Table_Lookup**: The process of determining which date-partitioned table (e.g., `alerts_2026_01_08`) should contain a record
- **Cleanup_Schedule**: The automated schedule for running cleanup operations (e.g., hourly, daily)
- **Cleanup_Log**: Audit trail of all cleanup operations including records deleted and timestamps
- **Dry_Run_Mode**: A preview mode that shows what would be deleted without actually deleting
- **Emergency_Stop**: A mechanism to immediately halt cleanup operations if issues are detected

## Requirements

### Requirement 1: Age-Based Record Identification

**User Story:** As a system administrator, I want to identify records older than 48 hours for cleanup, so that only sufficiently old data is removed from MySQL.

#### Acceptance Criteria

1. THE Age_Based_Cleanup_Service SHALL identify records where the Receivedtime_Column is older than the configured Age_Threshold
2. THE Age_Based_Cleanup_Service SHALL use the Age_Threshold value from configuration (default: 48 hours)
3. THE Age_Based_Cleanup_Service SHALL calculate age based on the current server timestamp
4. THE Age_Based_Cleanup_Service SHALL handle timezone conversions correctly when comparing timestamps
5. THE Age_Based_Cleanup_Service SHALL exclude records that do not meet the age criteria from cleanup operations

### Requirement 2: Configurable Batch Size Processing

**User Story:** As a system administrator, I want to control how many records are deleted per batch, so that I can balance cleanup speed with database performance.

#### Acceptance Criteria

1. THE Age_Based_Cleanup_Service SHALL delete records in batches of the configured Cleanup_Batch_Size (default: 100)
2. THE Age_Based_Cleanup_Service SHALL allow the Cleanup_Batch_Size to be configured via environment variables or configuration files
3. THE Age_Based_Cleanup_Service SHALL provide a clear location in the code where batch size can be modified
4. THE Age_Based_Cleanup_Service SHALL enforce minimum batch size of 10 and maximum batch size of 1000
5. THE Age_Based_Cleanup_Service SHALL commit each batch as a separate transaction to prevent long locks

### Requirement 3: PostgreSQL Verification Before Deletion

**User Story:** As a system administrator, I want to verify that records exist in PostgreSQL before deleting from MySQL, so that I never lose data that hasn't been properly synced.

#### Acceptance Criteria

1. THE Age_Based_Cleanup_Service SHALL verify each record exists in the appropriate PostgreSQL partition table before deletion
2. THE Age_Based_Cleanup_Service SHALL calculate the expected partition table name based on the record's receivedtime (e.g., `alerts_2026_01_06` for a record from 48 hours ago)
3. THE Age_Based_Cleanup_Service SHALL query the PostgreSQL partition table to confirm the record ID exists
4. IF a record is not found in PostgreSQL, THEN THE Age_Based_Cleanup_Service SHALL skip that record and log a warning
5. THE Age_Based_Cleanup_Service SHALL only delete records that are confirmed to exist in PostgreSQL

### Requirement 4: Safe Deletion with Multiple Safety Gates

**User Story:** As a system administrator, I want multiple safety checks before deletion, so that I never accidentally delete important or recent data.

#### Acceptance Criteria

1. THE Safety_Gate SHALL verify that records meet the Age_Threshold before deletion
2. THE Safety_Gate SHALL verify that records exist in PostgreSQL partition tables before deletion
3. THE Safety_Gate SHALL verify that the MySQL connection is stable before proceeding with deletion
4. THE Safety_Gate SHALL verify that the batch size does not exceed configured limits
5. IF any safety check fails, THEN THE Age_Based_Cleanup_Service SHALL abort the cleanup operation and log the reason
6. THE Safety_Gate SHALL require explicit admin confirmation before any deletion occurs

### Requirement 5: Comprehensive Cleanup Logging

**User Story:** As a system administrator, I want detailed logs of all cleanup operations, so that I can audit what was deleted and troubleshoot issues.

#### Acceptance Criteria

1. THE Cleanup_Log SHALL record the start time and end time of each cleanup operation
2. THE Cleanup_Log SHALL record the number of records deleted in each batch
3. THE Cleanup_Log SHALL record the number of records skipped due to PostgreSQL verification failure
4. THE Cleanup_Log SHALL record the age threshold and batch size used for each operation
5. THE Cleanup_Log SHALL record any errors or warnings encountered during cleanup
6. THE Cleanup_Log SHALL be queryable to show cleanup history for the past 90 days

### Requirement 6: Dry Run Preview Mode

**User Story:** As a system administrator, I want to preview what would be deleted without actually deleting, so that I can verify the cleanup operation is safe before executing it.

#### Acceptance Criteria

1. THE Age_Based_Cleanup_Service SHALL provide a Dry_Run_Mode that identifies eligible records without deleting
2. WHEN Dry_Run_Mode is enabled, THE Age_Based_Cleanup_Service SHALL return a preview showing record count and age distribution
3. THE Dry_Run_Mode SHALL show the oldest and newest records that would be deleted
4. THE Dry_Run_Mode SHALL verify PostgreSQL existence for preview records and show verification statistics
5. THE Dry_Run_Mode SHALL calculate the total size of data that would be removed
6. THE Dry_Run_Mode SHALL not modify any data in the MySQL_Alerts_Table

### Requirement 7: Configurable Age Threshold

**User Story:** As a system administrator, I want to configure the age threshold for cleanup, so that I can adjust retention based on business requirements.

#### Acceptance Criteria

1. THE Age_Based_Cleanup_Service SHALL read the Age_Threshold from configuration (default: 48 hours)
2. THE Age_Based_Cleanup_Service SHALL allow the Age_Threshold to be specified in hours
3. THE Age_Based_Cleanup_Service SHALL enforce a minimum Age_Threshold of 24 hours to prevent accidental deletion of recent data
4. THE Age_Based_Cleanup_Service SHALL validate the Age_Threshold value before starting cleanup
5. WHEN the Age_Threshold is changed, THE Age_Based_Cleanup_Service SHALL apply the new value on the next cleanup run

### Requirement 8: Incremental Cleanup Execution

**User Story:** As a system administrator, I want cleanup to run incrementally in small batches, so that it doesn't lock the alerts table for extended periods.

#### Acceptance Criteria

1. THE Age_Based_Cleanup_Service SHALL process one batch at a time with a short delay between batches
2. THE Age_Based_Cleanup_Service SHALL commit each batch immediately after deletion
3. THE Age_Based_Cleanup_Service SHALL allow other database operations to proceed between batches
4. THE Age_Based_Cleanup_Service SHALL stop after processing a configurable maximum number of batches per run
5. THE Age_Based_Cleanup_Service SHALL resume from where it left off on the next scheduled run

### Requirement 9: Error Handling and Recovery

**User Story:** As a system administrator, I want cleanup to handle errors gracefully, so that temporary issues don't cause data corruption or system instability.

#### Acceptance Criteria

1. WHEN a MySQL connection error occurs during cleanup, THE Age_Based_Cleanup_Service SHALL stop immediately and log the error
2. WHEN a batch deletion fails, THE Age_Based_Cleanup_Service SHALL rollback that batch and continue with the next batch
3. IF deletion errors exceed a threshold (3 consecutive failures), THEN THE Age_Based_Cleanup_Service SHALL stop and alert administrators
4. THE Age_Based_Cleanup_Service SHALL retry failed batches up to 2 times before marking them as failed
5. THE Age_Based_Cleanup_Service SHALL maintain an error queue for batches that repeatedly fail

### Requirement 10: Performance Monitoring and Metrics

**User Story:** As a system administrator, I want to monitor cleanup performance, so that I can optimize batch size and schedule for my workload.

#### Acceptance Criteria

1. THE Age_Based_Cleanup_Service SHALL track the average time per batch deletion
2. THE Age_Based_Cleanup_Service SHALL track the total records deleted per cleanup run
3. THE Age_Based_Cleanup_Service SHALL track the MySQL table size before and after cleanup
4. THE Age_Based_Cleanup_Service SHALL provide metrics on cleanup throughput (records per minute)
5. THE Age_Based_Cleanup_Service SHALL expose metrics via an API endpoint for monitoring dashboards

### Requirement 11: Emergency Stop Mechanism

**User Story:** As a system administrator, I want an emergency stop mechanism, so that I can immediately halt cleanup if I detect issues.

#### Acceptance Criteria

1. THE Age_Based_Cleanup_Service SHALL check for an Emergency_Stop flag before processing each batch
2. WHEN the Emergency_Stop flag is set, THE Age_Based_Cleanup_Service SHALL stop immediately and log the reason
3. THE Emergency_Stop flag SHALL be settable via API, configuration file, or database flag
4. THE Age_Based_Cleanup_Service SHALL send an alert when Emergency_Stop is triggered
5. THE Emergency_Stop flag SHALL persist until explicitly cleared by an administrator

### Requirement 12: Scheduled Automated Cleanup

**User Story:** As a system administrator, I want cleanup to run automatically on a schedule, so that I don't have to manually trigger it.

#### Acceptance Criteria

1. THE Age_Based_Cleanup_Service SHALL run on a configurable Cleanup_Schedule (default: every 6 hours)
2. THE Cleanup_Schedule SHALL be configurable via cron expression or interval
3. THE Age_Based_Cleanup_Service SHALL run during off-peak hours when possible
4. THE Age_Based_Cleanup_Service SHALL skip scheduled runs if a previous run is still in progress
5. THE Age_Based_Cleanup_Service SHALL log each scheduled run start and completion

### Requirement 13: Admin Dashboard Integration

**User Story:** As a system administrator, I want to view and control cleanup operations from the admin dashboard, so that I have visibility and control over the cleanup process.

#### Acceptance Criteria

1. THE Admin Dashboard SHALL display the current cleanup status (idle, running, stopped)
2. THE Admin Dashboard SHALL show the last cleanup run timestamp and records deleted
3. THE Admin Dashboard SHALL show the current Age_Threshold and Cleanup_Batch_Size configuration
4. THE Admin Dashboard SHALL provide buttons to trigger manual cleanup or dry run
5. THE Admin Dashboard SHALL provide a button to set the Emergency_Stop flag

### Requirement 14: Code Configuration Accessibility

**User Story:** As a developer, I want clear documentation of where to modify batch size and age threshold in the code, so that I can easily adjust these values.

#### Acceptance Criteria

1. THE Age_Based_Cleanup_Service SHALL have clearly commented configuration constants at the top of the class
2. THE configuration file SHALL include comments explaining the purpose of Age_Threshold and Cleanup_Batch_Size
3. THE Age_Based_Cleanup_Service SHALL provide a method to dynamically set batch size without code changes
4. THE Age_Based_Cleanup_Service SHALL provide a method to dynamically set age threshold without code changes
5. THE documentation SHALL include examples of how to modify these values in different environments

### Requirement 15: Cleanup Impact Minimization

**User Story:** As a system administrator, I want cleanup to have minimal impact on production operations, so that it doesn't affect the Java applications writing to MySQL.

#### Acceptance Criteria

1. THE Age_Based_Cleanup_Service SHALL use indexed queries to identify old records efficiently
2. THE Age_Based_Cleanup_Service SHALL use DELETE with LIMIT to prevent full table scans
3. THE Age_Based_Cleanup_Service SHALL add a configurable delay between batches (default: 100ms)
4. THE Age_Based_Cleanup_Service SHALL monitor MySQL connection pool usage and pause if threshold is exceeded
5. THE Age_Based_Cleanup_Service SHALL prioritize read/write operations from Java applications over cleanup operations
