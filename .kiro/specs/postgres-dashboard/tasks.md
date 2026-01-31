# Implementation Plan: PostgreSQL Dashboard

## Overview

This implementation plan breaks down the PostgreSQL Dashboard feature into discrete coding tasks. The approach follows an incremental development strategy: backend services first, then API endpoints, then frontend components, with testing integrated throughout. Each task builds on previous work to ensure continuous integration.

## Tasks

- [x] 1. Create PostgresDashboardService with shift calculation logic
  - Create `app/Services/PostgresDashboardService.php`
  - Implement `getCurrentShift()` method to calculate shift based on current time
  - Implement `getShiftTimeRange(int $shift)` method to return Carbon start/end times
  - Handle Shift 3 midnight spanning (23:00 today to 06:59 tomorrow)
  - _Requirements: 2.1, 2.2, 2.3, 2.4_

- [ ]* 1.1 Write property test for shift calculation
  - **Property 1: Shift Calculation Correctness**
  - **Validates: Requirements 2.1, 2.2, 2.3**
  - Generate random times across all 24 hours
  - Verify correct shift number returned for each time range
  - Test boundary conditions (06:59, 07:00, 14:59, 15:00, 22:59, 23:00)

- [x] 2. Implement partition table selection logic
  - Add `getPartitionTablesForShift(int $shift)` method to PostgresDashboardService
  - Use PartitionManager to generate partition table names
  - For Shift 1 and 2: return single partition for current date
  - For Shift 3: return two partitions (current date and next date)
  - _Requirements: 1.2, 6.1, 6.2, 6.3_

- [ ]* 2.1 Write property test for partition table selection
  - **Property 2: Partition Table Selection for Shift**
  - **Validates: Requirements 1.2, 6.1**
  - Generate random dates and shifts
  - Verify partition names follow `alerts_YYYY_MM_DD` format
  - Verify Shift 3 returns exactly 2 partitions

- [x] 3. Implement alert count aggregation from PostgreSQL partitions
  - Add `queryPartitionsForCounts()` method to PostgresDashboardService
  - Check if partition tables exist before querying
  - Query PostgreSQL partitions with receivedtime filter
  - Group by terminal (sendip OR sip2) and status
  - Count regular alerts (status='O' or 'C')
  - Count critical alerts (critical_alerts='y')
  - Return Collection of raw count data
  - _Requirements: 1.3, 3.1, 3.2, 3.3, 6.4_

- [ ]* 3.1 Write property test for alert count aggregation
  - **Property 3: Alert Count Aggregation**
  - **Validates: Requirements 1.3**
  - Generate random alert datasets
  - Verify sum of grouped counts equals total input count

- [ ]* 3.2 Write property test for critical alert filtering
  - **Property 5: Critical Alert Filtering**
  - **Validates: Requirements 3.1, 3.2, 3.3**
  - Generate random alerts with mixed critical_alerts values
  - Verify critical counts only include alerts with critical_alerts='y'

- [x] 4. Implement username enrichment from MySQL
  - Add `enrichWithUsernames()` method to PostgresDashboardService
  - Query MySQL alertscount table for terminal-to-userid mapping
  - Query MySQL loginusers table for userid-to-name mapping
  - Apply ucwords() capitalization to usernames
  - Handle missing data gracefully (return null for username)
  - _Requirements: 1.4, 7.1, 7.2, 7.3, 7.4_

- [ ]* 4.1 Write property test for username enrichment
  - **Property 7: Terminal Username Enrichment**
  - **Validates: Requirements 1.4, 7.1, 7.2, 7.3**
  - Generate random terminal data with valid userids
  - Verify usernames are retrieved and capitalized

- [ ]* 4.2 Write property test for missing username handling
  - **Property 8: Missing Username Handling**
  - **Validates: Requirements 7.4, 9.3**
  - Generate terminal data not in alertscount
  - Verify username field is null

- [x] 5. Implement grand total calculation
  - Add `calculateGrandTotals()` method to PostgresDashboardService
  - Sum open, close, total, criticalOpen, criticalClose, totalCritical across all terminals
  - Return array with grand total values
  - _Requirements: 1.5, 4.5_

- [ ]* 5.1 Write property test for grand total calculation
  - **Property 4: Grand Total Calculation**
  - **Validates: Requirements 1.5, 4.5**
  - Generate random terminal datasets
  - Verify grand totals equal sum of individual terminal values

- [ ]* 5.2 Write property test for critical total arithmetic
  - **Property 6: Critical Total Arithmetic**
  - **Validates: Requirements 3.4**
  - Generate random terminal data
  - Verify totalCritical = criticalOpen + criticalClose for each terminal

- [x] 6. Implement main getAlertDistribution method
  - Add `getAlertDistribution(?int $shift = null)` method to PostgresDashboardService
  - Auto-detect shift if not provided
  - Get shift time range
  - Get partition tables for shift
  - Query partitions for alert counts
  - Enrich with usernames
  - Calculate grand totals
  - Return complete dashboard data array
  - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5_

- [x] 7. Checkpoint - Test service layer
  - Ensure all PostgresDashboardService methods work correctly
  - Verify shift calculation with different times
  - Verify partition queries return expected data
  - Ask the user if questions arise

- [x] 8. Implement alert details query for modal
  - Add `getAlertDetails(string $terminal, string $status, int $shift)` method to PostgresDashboardService
  - Get partition tables for shift
  - Build query with terminal, status, and shift filters
  - Handle different status types (open, close, total, criticalopen, criticalClose, totalCritical)
  - LEFT JOIN with MySQL sites table (OldPanelID or NewPanelID)
  - Select columns: id, ATMID, panelid, Zone, City, receivedtime, alerttype, comment, closedBy, closedtime
  - Return Collection of alert details
  - _Requirements: 5.2, 5.3, 5.4, 9.4_

- [ ]* 8.1 Write property test for modal query filtering
  - **Property 10: Modal Query Filtering**
  - **Validates: Requirements 5.2**
  - Generate random terminal/status/shift combinations
  - Verify query includes all three filters

- [ ]* 8.2 Write property test for site data enrichment
  - **Property 11: Site Data Enrichment**
  - **Validates: Requirements 5.3**
  - Generate alerts with matching site records
  - Verify site data is included in results

- [ ]* 8.3 Write property test for missing site data
  - **Property 12: Missing Site Data Handling**
  - **Validates: Requirements 9.4**
  - Generate alerts without matching sites
  - Verify site columns are null

- [x] 9. Create PostgresDashboardController
  - Create `app/Http/Controllers/PostgresDashboardController.php`
  - Inject PostgresDashboardService dependency
  - Add authentication middleware (auth:sanctum)
  - Add permission middleware (permission:dashboard.view)
  - _Requirements: 8.1, 8.3, 11.1, 11.2_

- [x] 10. Implement data endpoint
  - Add `data(Request $request)` method to PostgresDashboardController
  - Validate shift parameter (nullable|integer|in:1,2,3)
  - Call service->getAlertDistribution($shift)
  - Return JSON response with data, totals, shift, and shift_time_range
  - Wrap in try-catch for error handling
  - Log errors and return HTTP 500 on failure
  - _Requirements: 8.1, 8.2, 8.5, 9.2, 9.5_

- [ ]* 10.1 Write property test for shift parameter validation
  - **Property 15: Shift Parameter Validation**
  - **Validates: Requirements 8.2**
  - Test valid shift values (1, 2, 3) are accepted
  - Test invalid values are rejected with validation error

- [ ]* 10.2 Write property test for error response format
  - **Property 14: Query Failure Error Response**
  - **Validates: Requirements 9.2, 9.5**
  - Simulate database query failure
  - Verify HTTP 500 response with error message

- [x] 11. Implement details endpoint
  - Add `details(Request $request)` method to PostgresDashboardController
  - Validate parameters: terminal (required|string), status (required|string|in:open,close,total,criticalopen,criticalClose,totalCritical), shift (required|integer|in:1,2,3)
  - Call service->getAlertDetails($terminal, $status, $shift)
  - Return JSON response with alert details array
  - Wrap in try-catch for error handling
  - _Requirements: 8.3, 8.4, 8.5_

- [ ]* 11.1 Write property test for details parameter validation
  - **Property 16: Details Parameter Validation**
  - **Validates: Requirements 8.4**
  - Test requests with missing parameters are rejected
  - Test requests with all parameters are accepted

- [x] 12. Add API routes
  - Add routes to `routes/api.php`
  - GET /api/dashboard/postgres/data - fetch alert distribution
  - GET /api/dashboard/postgres/details - fetch alert details
  - Apply auth:sanctum and permission:dashboard.view middleware
  - _Requirements: 8.1, 8.3, 11.1, 11.2_

- [ ]* 12.1 Write unit tests for authentication
  - **Property 18: Authentication Requirement**
  - **Validates: Requirements 11.1, 11.4**
  - Test unauthenticated requests return HTTP 401

- [ ]* 12.2 Write unit tests for authorization
  - **Property 19: Authorization Requirement**
  - **Validates: Requirements 11.2, 11.3**
  - Test requests without permission return HTTP 403

- [x] 13. Checkpoint - Test API endpoints
  - Test data endpoint with Postman or curl
  - Test details endpoint with various parameters
  - Verify authentication and authorization work correctly
  - Ensure all tests pass, ask the user if questions arise

- [x] 14. Create PostgresDashboardPage React component
  - Create `resources/js/pages/PostgresDashboardPage.jsx`
  - Set up component state for data, totals, loading, error, shift, shiftTimeRange
  - Implement fetchDashboardData() method using axios
  - Call fetchDashboardData() on component mount
  - Display loading indicator while fetching
  - Display error message if fetch fails
  - _Requirements: 10.1, 10.2, 10.5, 4.1_

- [x] 15. Implement dashboard table rendering
  - Render Bootstrap table with 8 columns: Terminal, User, Open, Close, Total, Critical Open, Critical Close, Total Critical
  - Map data array to table rows
  - Add data-terminal and data-status attributes to clickable cells
  - Render grand totals in footer row
  - Apply Bootstrap styling (table-striped, table-hover)
  - _Requirements: 1.1, 1.5, 10.3_

- [x] 16. Implement auto-refresh functionality
  - Add useEffect hook to set up 5-second interval
  - Call fetchDashboardData() every 5 seconds
  - Clear interval on component unmount
  - Update table data without full page reload
  - _Requirements: 4.2, 4.3, 4.4, 4.5_

- [ ]* 16.1 Write unit test for auto-refresh setup
  - Test interval is set to 5000ms on mount
  - Test interval is cleared on unmount

- [ ]* 16.2 Write property test for new terminal row addition
  - **Property 9: New Terminal Row Addition**
  - **Validates: Requirements 4.4**
  - Simulate data updates with new terminals
  - Verify all terminals appear in rendered output

- [x] 17. Create AlertDetailsModal component
  - Create `resources/js/components/AlertDetailsModal.jsx`
  - Accept props: isOpen, terminal, status, shift, onClose
  - Set up state for alerts, loading, error
  - Implement fetchAlertDetails() method
  - Fetch data when modal opens
  - Display loading indicator while fetching
  - _Requirements: 5.1, 5.5_

- [x] 18. Implement modal table rendering
  - Render Bootstrap table with columns: Sr no, ATMID, Panel ID, Zone, City, Received At, Alert Type, Comment, Close By, Closed At
  - Map alerts array to table rows
  - Display modal header with terminal and status
  - Add close button
  - _Requirements: 5.4_

- [x] 19. Integrate modal with dashboard page
  - Add state for modal visibility and selected cell
  - Implement handleCellClick(terminal, status) method
  - Add onClick handlers to clickable table cells
  - Pass props to AlertDetailsModal component
  - Show pointer cursor on hover for clickable cells
  - _Requirements: 5.1, 10.4_

- [x] 20. Add dashboard route to React Router
  - Add route in `resources/js/router.jsx` or equivalent
  - Path: /dashboard/postgres
  - Component: PostgresDashboardPage
  - Require authentication
  - _Requirements: 10.1_

- [x] 21. Add navigation menu item
  - Add "PostgreSQL Dashboard" link to navigation menu
  - Link to /dashboard/postgres route
  - Show only to users with dashboard.view permission

- [x] 22. Final checkpoint - End-to-end testing
  - Load dashboard page in browser
  - Verify data displays correctly
  - Verify auto-refresh updates data every 5 seconds
  - Click on alert count cells and verify modal opens
  - Verify modal displays correct alert details
  - Test with different shifts (manually change server time if needed)
  - Ensure all tests pass, ask the user if questions arise

- [ ]* 23. Write integration test for complete dashboard flow
  - Test page load → data fetch → table render → cell click → modal open → details fetch
  - Verify data flows correctly through all components

- [ ]* 24. Write property test for JSON response format
  - **Property 17: JSON Response Format**
  - **Validates: Requirements 8.5**
  - Test all successful responses are valid JSON with success=true

- [ ]* 25. Write property test for non-existent partition handling
  - **Property 13: Non-Existent Partition Handling**
  - **Validates: Requirements 6.4, 9.1**
  - Test queries for non-existent partitions return empty results without errors

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties
- Unit tests validate specific examples and edge cases
- The implementation follows the existing Laravel and React patterns in the codebase
- All PostgreSQL queries are read-only (no updates or deletes)
- MySQL queries for reference data (alertscount, loginusers, sites) are also read-only
