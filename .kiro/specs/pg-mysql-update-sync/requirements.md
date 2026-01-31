# Requirements Document

## Introduction

This specification defines a continuous synchronization system that monitors a MySQL update log table (`alert_pg_update_log`) and propagates changes to a PostgreSQL alerts table. The system processes log entries with status=1, fetches the corresponding alert data from MySQL (the source of truth written by a Java application), and updates the PostgreSQL alerts table with all column values.

**Key Architecture**:
- Java application writes/updates records in MySQL alerts table (source of truth)
- Java application creates entries in MySQL alert_pg_update_log when alerts change
- Sync worker reads from MySQL alerts (read-only) and updates PostgreSQL alerts (target)
- Sync worker updates MySQL alert_pg_update_log to mark entries as processed
- MySQL alerts table is never modified by the sync worker

## Glossary

- **Update_Log_Table**: The MySQL table `alert_pg_update_log` that tracks alerts requiring synchronization
- **Source_Database**: MySQL database containing the authoritative alert data (written by Java app)
- **Target_Database**: PostgreSQL database containing alerts that need to be updated
- **Sync_Worker**: The continuous process that monitors and processes update log entries
- **Alert_Record**: A row in the alerts table containing alert information
- **Log_Entry**: A row in the update log table indicating an alert needs synchronization

## Requirements

### Requirement 1: Monitor Update Log Table

**User Story:** As a system administrator, I want the system to continuously monitor the PostgreSQL update log table, so that changes are detected and processed in near real-time.

#### Acceptance Criteria

1. THE Sync_Worker SHALL continuously poll the Update_Log_Table for new entries
2. WHEN polling occurs, THE Sync_Worker SHALL query for log entries where status equals 1
3. THE Sync_Worker SHALL process log entries in order of creation (oldest first)
4. WHEN no entries are found, THE Sync_Worker SHALL wait before the next poll cycle
5. THE Sync_Worker SHALL run indefinitely until explicitly stopped

### Requirement 2: Fetch Alert Data from MySQL

**User Story:** As a data synchronization system, I want to retrieve complete alert records from MySQL, so that I have all current column values to update in PostgreSQL.

#### Acceptance Criteria

1. WHEN a log entry with status=1 is found, THE Sync_Worker SHALL extract the alert_id value
2. THE Sync_Worker SHALL query the Source_Database alerts table where id equals the alert_id
3. WHEN the alert is found, THE Sync_Worker SHALL retrieve all column values from the Alert_Record
4. IF the alert is not found in Source_Database, THEN THE Sync_Worker SHALL log an error and mark the log entry as failed
5. THE Sync_Worker SHALL handle database connection errors gracefully
6. THE Sync_Worker SHALL perform only SELECT operations on the Source_Database alerts table

### Requirement 3: Update PostgreSQL Alert Records

**User Story:** As a data synchronization system, I want to update PostgreSQL alert records with all current values from MySQL, so that both databases remain consistent.

#### Acceptance Criteria

1. WHEN alert data is retrieved from Source_Database, THE Sync_Worker SHALL update or insert into the Target_Database alerts table where id equals alert_id
2. THE Sync_Worker SHALL update all columns in the PostgreSQL Alert_Record with values from the MySQL Alert_Record
3. WHEN the update completes successfully, THE Sync_Worker SHALL set the updated_at timestamp to the current time
4. THE Sync_Worker SHALL execute the update as a single atomic transaction
5. IF the PostgreSQL alert record does not exist, THEN THE Sync_Worker SHALL insert a new record with all column values

### Requirement 4: Update Log Entry Status

**User Story:** As a system administrator, I want processed log entries to be marked as complete, so that they are not processed multiple times and I can track synchronization history.

#### Acceptance Criteria

1. WHEN an alert update completes successfully, THE Sync_Worker SHALL update the log entry updated_at timestamp in the Update_Log_Table
2. THE Sync_Worker SHALL update the log entry status to indicate successful processing
3. WHEN an error occurs during processing, THE Sync_Worker SHALL update the log entry status to indicate failure
4. THE Sync_Worker SHALL record error details in the log entry for troubleshooting
5. THE Sync_Worker SHALL ensure log entry updates are committed to the Source_Database

### Requirement 5: Error Handling and Resilience

**User Story:** As a system administrator, I want the synchronization worker to handle errors gracefully, so that temporary issues do not cause data loss or system crashes.

#### Acceptance Criteria

1. WHEN a database connection fails, THE Sync_Worker SHALL retry the connection with exponential backoff
2. WHEN a query fails, THE Sync_Worker SHALL log the error and continue processing other entries
3. IF multiple consecutive errors occur, THEN THE Sync_Worker SHALL increase the polling interval
4. THE Sync_Worker SHALL log all errors with sufficient detail for troubleshooting
5. WHEN the system recovers from errors, THE Sync_Worker SHALL resume normal operation

### Requirement 6: Performance and Resource Management

**User Story:** As a system administrator, I want the synchronization worker to operate efficiently, so that it does not consume excessive system resources.

#### Acceptance Criteria

1. THE Sync_Worker SHALL process log entries in configurable batch sizes
2. THE Sync_Worker SHALL use connection pooling for database connections
3. WHEN the system is idle, THE Sync_Worker SHALL use minimal CPU resources
4. THE Sync_Worker SHALL release database connections when not actively processing
5. THE Sync_Worker SHALL provide configurable polling intervals

### Requirement 7: Logging and Monitoring

**User Story:** As a system administrator, I want comprehensive logging of synchronization activities, so that I can monitor system health and troubleshoot issues.

#### Acceptance Criteria

1. THE Sync_Worker SHALL log each processing cycle with timestamp and entry count
2. WHEN an alert is updated, THE Sync_Worker SHALL log the alert_id and update status
3. THE Sync_Worker SHALL log performance metrics including processing time per entry
4. THE Sync_Worker SHALL provide log levels for different verbosity requirements
5. THE Sync_Worker SHALL log startup, shutdown, and configuration information
