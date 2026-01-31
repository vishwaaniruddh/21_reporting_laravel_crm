# Requirements Document

## Introduction

This document specifies the requirements for a second dashboard page that displays server alert count distribution using PostgreSQL partitioned alert tables. The dashboard will provide real-time monitoring of alert counts across terminals, organized by shift, with support for both regular and critical alerts. Unlike the existing MySQL-based dashboard, this implementation will query PostgreSQL date-partitioned tables for improved performance and scalability.

## Glossary

- **Dashboard_System**: The web-based interface that displays alert count distribution across terminals
- **PostgreSQL_Partition**: Date-based table partition storing alerts data (e.g., alerts_2026_01_12)
- **Terminal**: A monitoring station identified by IP address that receives alerts
- **Shift**: An 8-hour work period (Shift 1: 07:00-14:59, Shift 2: 15:00-22:59, Shift 3: 23:00-06:59)
- **Alert_Status**: The state of an alert, either 'O' (Open) or 'C' (Close)
- **Critical_Alert**: An alert marked with critical_alerts='y' flag indicating high priority
- **Alert_Count**: The number of alerts for a specific terminal and status combination
- **Grand_Total**: The sum of all alert counts across all terminals
- **Modal_Detail_View**: A popup window displaying detailed alert information for a specific terminal and status

## Requirements

### Requirement 1: Display Alert Count Distribution

**User Story:** As a monitoring operator, I want to view alert count distribution across all terminals in a table format, so that I can quickly assess the current alert situation.

#### Acceptance Criteria

1. WHEN the dashboard page loads, THE Dashboard_System SHALL display a table with columns for Terminal, User, Open, Close, Total, Critical Open, Critical Close, and Total Critical
2. WHEN querying alert data, THE Dashboard_System SHALL query PostgreSQL date-partitioned tables based on the current shift time range
3. WHEN calculating alert counts, THE Dashboard_System SHALL group alerts by terminal (sendip or sip2) and status
4. WHEN displaying terminal data, THE Dashboard_System SHALL join with the alertscount table to retrieve the associated username
5. THE Dashboard_System SHALL display grand totals for all alert count columns in a footer row

### Requirement 2: Shift-Based Time Filtering

**User Story:** As a monitoring operator, I want the dashboard to automatically filter alerts based on the current shift, so that I only see relevant data for my work period.

#### Acceptance Criteria

1. WHEN the current time is between 07:00 and 14:59, THE Dashboard_System SHALL filter alerts for Shift 1 time range
2. WHEN the current time is between 15:00 and 22:59, THE Dashboard_System SHALL filter alerts for Shift 2 time range
3. WHEN the current time is between 23:00 and 06:59, THE Dashboard_System SHALL filter alerts for Shift 3 time range (spanning two dates)
4. WHEN Shift 3 spans midnight, THE Dashboard_System SHALL query partitions for both the current date and next date
5. THE Dashboard_System SHALL use the receivedtime column for time-based filtering

### Requirement 3: Critical Alert Tracking

**User Story:** As a monitoring operator, I want to see critical alerts separately from regular alerts, so that I can prioritize high-priority situations.

#### Acceptance Criteria

1. WHEN counting critical alerts, THE Dashboard_System SHALL filter alerts where critical_alerts='y'
2. WHEN displaying critical open alerts, THE Dashboard_System SHALL count alerts with status='O' AND critical_alerts='y'
3. WHEN displaying critical close alerts, THE Dashboard_System SHALL count alerts with status='C' AND critical_alerts='y'
4. THE Dashboard_System SHALL display total critical alerts as the sum of critical open and critical close counts
5. THE Dashboard_System SHALL display critical alert counts in separate columns from regular alert counts

### Requirement 4: Real-Time Data Updates

**User Story:** As a monitoring operator, I want the dashboard to automatically refresh data every 5 seconds, so that I always see the most current alert information.

#### Acceptance Criteria

1. WHEN the dashboard is loaded, THE Dashboard_System SHALL fetch initial data immediately
2. WHEN 5 seconds have elapsed, THE Dashboard_System SHALL fetch updated data from the server
3. WHEN updating the table, THE Dashboard_System SHALL update existing rows without full page reload
4. WHEN new terminals appear in the data, THE Dashboard_System SHALL add new rows to the table
5. THE Dashboard_System SHALL update grand total values with each data refresh

### Requirement 5: Interactive Alert Details

**User Story:** As a monitoring operator, I want to click on any alert count cell to view detailed alert information, so that I can investigate specific alerts.

#### Acceptance Criteria

1. WHEN a user clicks on an alert count cell, THE Dashboard_System SHALL open a modal window with detailed alert information
2. WHEN loading modal details, THE Dashboard_System SHALL query PostgreSQL partitions filtered by terminal, status, and shift
3. WHEN displaying alert details, THE Dashboard_System SHALL join with the sites table to show ATMID, Zone, and City
4. WHEN showing alert details, THE Dashboard_System SHALL display columns for Sr no, ATMID, Panel ID, Zone, City, Received At, Alert Type, Comment, Close By, and Closed At
5. WHEN the modal is opened, THE Dashboard_System SHALL display a loading indicator until data is fetched

### Requirement 6: PostgreSQL Partition Query Strategy

**User Story:** As a system administrator, I want the dashboard to efficiently query PostgreSQL partitioned tables, so that performance remains optimal as data grows.

#### Acceptance Criteria

1. WHEN determining which partitions to query, THE Dashboard_System SHALL calculate partition table names based on the shift date range
2. WHEN querying for Shift 1 or Shift 2, THE Dashboard_System SHALL query a single partition table for the current date
3. WHEN querying for Shift 3, THE Dashboard_System SHALL query two partition tables (current date and next date)
4. WHEN a required partition does not exist, THE Dashboard_System SHALL return empty results without error
5. THE Dashboard_System SHALL use the PartitionManager service to determine partition table names

### Requirement 7: Terminal and User Association

**User Story:** As a monitoring operator, I want to see which user is associated with each terminal, so that I can contact the appropriate person if needed.

#### Acceptance Criteria

1. WHEN displaying terminal data, THE Dashboard_System SHALL query the alertscount table to find the userid for each terminal
2. WHEN a userid is found, THE Dashboard_System SHALL query the loginusers table to retrieve the user's name
3. WHEN displaying the username, THE Dashboard_System SHALL format it with proper capitalization
4. WHEN a terminal has no associated user, THE Dashboard_System SHALL display an empty username field
5. THE Dashboard_System SHALL query MySQL for alertscount and loginusers data (read-only)

### Requirement 8: API Endpoint Structure

**User Story:** As a frontend developer, I want well-structured API endpoints for dashboard data, so that I can easily integrate the dashboard interface.

#### Acceptance Criteria

1. THE Dashboard_System SHALL provide a GET endpoint at /api/dashboard/postgres/data for fetching alert counts
2. WHEN the data endpoint is called, THE Dashboard_System SHALL accept a shift parameter (1, 2, or 3)
3. THE Dashboard_System SHALL provide a GET endpoint at /api/dashboard/postgres/details for fetching alert details
4. WHEN the details endpoint is called, THE Dashboard_System SHALL accept terminal, status, and shift parameters
5. THE Dashboard_System SHALL return JSON responses with appropriate HTTP status codes

### Requirement 9: Error Handling and Resilience

**User Story:** As a system administrator, I want the dashboard to handle errors gracefully, so that temporary issues don't disrupt monitoring operations.

#### Acceptance Criteria

1. WHEN a partition table does not exist, THE Dashboard_System SHALL return empty results without throwing an error
2. WHEN a database query fails, THE Dashboard_System SHALL log the error and return an appropriate error response
3. WHEN the alertscount table has no data for a terminal, THE Dashboard_System SHALL skip that terminal
4. WHEN the sites table has no matching data, THE Dashboard_System SHALL display null values for site-related columns
5. THE Dashboard_System SHALL return HTTP 500 status with error message when critical failures occur

### Requirement 10: Frontend Dashboard Page

**User Story:** As a monitoring operator, I want a dedicated dashboard page in the React application, so that I can access the PostgreSQL dashboard through the main interface.

#### Acceptance Criteria

1. THE Dashboard_System SHALL provide a React page component at /dashboard/postgres route
2. WHEN the page loads, THE Dashboard_System SHALL display a header with the title "Server Alert Count Distribution (PostgreSQL)"
3. WHEN displaying the table, THE Dashboard_System SHALL use Bootstrap styling consistent with the reference implementation
4. WHEN a cell is clickable, THE Dashboard_System SHALL show a pointer cursor on hover
5. THE Dashboard_System SHALL display a loading indicator while fetching initial data

### Requirement 11: Authentication and Authorization

**User Story:** As a system administrator, I want the dashboard to require authentication and appropriate permissions, so that only authorized users can view alert data.

#### Acceptance Criteria

1. THE Dashboard_System SHALL require authentication via Sanctum middleware for all dashboard endpoints
2. THE Dashboard_System SHALL require the 'dashboard.view' permission for accessing dashboard data
3. WHEN a user lacks required permissions, THE Dashboard_System SHALL return HTTP 403 Forbidden status
4. WHEN a user is not authenticated, THE Dashboard_System SHALL return HTTP 401 Unauthorized status
5. THE Dashboard_System SHALL validate the user's session on each API request
