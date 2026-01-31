# Requirements Document

## Introduction

A data pipeline system for an ATM monitoring company that synchronizes 2-3 million alert records from MySQL to PostgreSQL for reporting purposes. The system addresses database contention issues where concurrent Java application writes and reporting queries overload MySQL, causing WAMP crashes and service disruptions. The solution offloads reporting workload to PostgreSQL while maintaining data integrity and providing automated cleanup of synced MySQL data.

## Glossary

- **MySQL_Alerts_Table**: The source table in MySQL containing ATM monitoring alerts (2-3 million records)
- **PostgreSQL_Alerts_Table**: The target table in PostgreSQL for reporting queries
- **Data_Pipeline**: The automated system that transfers alerts from MySQL to PostgreSQL
- **Sync_Job**: A scheduled background job that performs incremental data synchronization
- **Cleanup_Job**: A scheduled background job that removes synced records from MySQL
- **Sync_Marker**: A tracking mechanism to identify which records have been synced
- **Batch_Processor**: Component that processes records in configurable chunks to prevent memory issues
- **Reporting_System**: The Laravel/React application that generates daily reports from PostgreSQL
- **Java_Applications**: External applications that continuously insert alerts into MySQL
- **Sync_Log**: Audit trail of all sync operations with timestamps and record counts
- **Verification_Service**: Component that validates data integrity before cleanup

## Requirements

### Requirement 1: Incremental Data Synchronization

**User Story:** As a system administrator, I want alerts to be automatically synced from MySQL to PostgreSQL in batches, so that reporting queries don't impact the production MySQL database.

#### Acceptance Criteria

1. THE Data_Pipeline SHALL read new/unsynced records from MySQL_Alerts_Table in configurable batch sizes
2. THE Data_Pipeline SHALL insert synced records into PostgreSQL_Alerts_Table preserving all original data
3. THE Data_Pipeline SHALL mark records as synced in MySQL using a Sync_Marker after successful PostgreSQL insertion
4. WHEN a sync batch fails, THE Data_Pipeline SHALL rollback the batch and retry without data loss
5. THE Data_Pipeline SHALL process batches of 10,000-50,000 records to balance throughput and memory usage
6. THE Data_Pipeline SHALL run during off-peak hours to minimize impact on Java_Applications

### Requirement 2: Sync Progress Tracking and Monitoring

**User Story:** As a system administrator, I want to monitor sync progress and history, so that I can ensure data is being transferred correctly and troubleshoot issues.

#### Acceptance Criteria

1. THE Sync_Log SHALL record start time, end time, records processed, and status for each sync operation
2. THE Reporting_System SHALL display current sync status (idle, running, failed) on the dashboard
3. THE Reporting_System SHALL show total records synced, pending records, and last sync timestamp
4. WHEN a sync operation fails, THE Data_Pipeline SHALL log the error details and affected record range
5. THE Sync_Log SHALL be queryable to show sync history for the past 30 days

### Requirement 3: Data Verification Before Cleanup

**User Story:** As a system administrator, I want synced data to be verified before MySQL cleanup, so that I never lose alert data due to sync failures.

#### Acceptance Criteria

1. THE Verification_Service SHALL compare record counts between MySQL and PostgreSQL for synced batches
2. THE Verification_Service SHALL validate that all synced record IDs exist in PostgreSQL before cleanup
3. IF verification fails, THEN THE Cleanup_Job SHALL skip the affected records and alert administrators
4. THE Verification_Service SHALL generate a verification report showing match/mismatch statistics
5. THE Cleanup_Job SHALL only delete records that have been verified as successfully synced

### Requirement 4: Automated MySQL Cleanup

**User Story:** As a system administrator, I want synced records to be automatically deleted from MySQL on a schedule, so that the alerts table stays manageable and MySQL performance remains stable.

#### Acceptance Criteria

1. THE Cleanup_Job SHALL delete only records that are marked as synced AND verified
2. THE Cleanup_Job SHALL delete records older than a configurable retention period (default: 7 days after sync)
3. THE Cleanup_Job SHALL delete records in batches to prevent long-running transactions
4. WHEN cleanup is running, THE Cleanup_Job SHALL log progress and records deleted
5. THE Cleanup_Job SHALL run during off-peak hours (configurable schedule)
6. THE Cleanup_Job SHALL stop if MySQL connection issues are detected

### Requirement 5: Reporting from PostgreSQL

**User Story:** As a report user, I want to generate daily ATM monitoring reports from PostgreSQL, so that report generation doesn't slow down the production MySQL database.

#### Acceptance Criteria

1. THE Reporting_System SHALL query PostgreSQL_Alerts_Table for all report data
2. THE Reporting_System SHALL support filtering alerts by date range, alert type, and severity
3. THE Reporting_System SHALL generate summary statistics (counts by type, severity trends, etc.)
4. THE Reporting_System SHALL export reports in CSV and PDF formats
5. WHEN PostgreSQL is unavailable, THE Reporting_System SHALL display an appropriate error message

### Requirement 6: Configuration and Scheduling

**User Story:** As a system administrator, I want to configure sync and cleanup schedules, so that I can optimize the pipeline for my specific workload patterns.

#### Acceptance Criteria

1. THE Data_Pipeline SHALL allow configuration of batch size via environment variables
2. THE Data_Pipeline SHALL allow configuration of sync schedule (cron expression)
3. THE Cleanup_Job SHALL allow configuration of retention period before cleanup
4. THE Cleanup_Job SHALL allow configuration of cleanup schedule (cron expression)
5. THE Reporting_System SHALL display current configuration settings on an admin page
6. WHEN configuration is changed, THE Data_Pipeline SHALL apply changes without restart

### Requirement 7: Error Recovery and Resilience

**User Story:** As a system administrator, I want the pipeline to handle failures gracefully, so that temporary issues don't cause data loss or require manual intervention.

#### Acceptance Criteria

1. WHEN MySQL connection fails during sync, THE Data_Pipeline SHALL retry with exponential backoff
2. WHEN PostgreSQL connection fails during sync, THE Data_Pipeline SHALL rollback and retry the batch
3. IF a sync job exceeds the maximum runtime, THEN THE Data_Pipeline SHALL checkpoint progress and resume on next run
4. THE Data_Pipeline SHALL send alerts (email/notification) when sync failures exceed a threshold
5. THE Data_Pipeline SHALL maintain an error queue for records that repeatedly fail to sync

### Requirement 8: Performance Optimization

**User Story:** As a system administrator, I want the sync process to be optimized for high-volume data, so that it can handle 2-3 million records without impacting MySQL performance.

#### Acceptance Criteria

1. THE Data_Pipeline SHALL use indexed queries on MySQL to identify unsynced records efficiently
2. THE Data_Pipeline SHALL use bulk inserts on PostgreSQL for better write performance
3. THE Batch_Processor SHALL limit concurrent database connections to prevent connection pool exhaustion
4. THE Data_Pipeline SHALL process records in ID order to enable efficient pagination
5. THE Cleanup_Job SHALL use indexed deletes to minimize table locking time
