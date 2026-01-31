# Design Document: PostgreSQL Dashboard

## Overview

The PostgreSQL Dashboard is a real-time monitoring interface that displays alert count distribution across terminals using PostgreSQL date-partitioned tables. This design adapts the existing MySQL-based dashboard to leverage the new partitioned data architecture, providing improved performance and scalability while maintaining the familiar user interface.

The system consists of three main components:
1. **Backend API** - Laravel controllers and services that query PostgreSQL partitions and MySQL reference tables
2. **Frontend Dashboard** - React-based UI with real-time updates and interactive drill-down capabilities
3. **Data Integration Layer** - Services that bridge PostgreSQL partitioned alerts with MySQL reference data

## Architecture

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                     React Frontend                          │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  PostgresDashboardPage Component                     │  │
│  │  - Table Display                                     │  │
│  │  - Modal Detail View                                 │  │
│  │  - Auto-refresh (5s interval)                        │  │
│  └──────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
                            │
                            │ HTTP/JSON
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                   Laravel Backend API                       │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  PostgresDashboardController                         │  │
│  │  - GET /api/dashboard/postgres/data                  │  │
│  │  - GET /api/dashboard/postgres/details               │  │
│  └──────────────────────────────────────────────────────┘  │
│                            │                                │
│                            ▼                                │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  PostgresDashboardService                            │  │
│  │  - Shift calculation                                 │  │
│  │  - Partition query orchestration                     │  │
│  │  - Data aggregation                                  │  │
│  └──────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                    Data Layer                               │
│  ┌──────────────┐         ┌──────────────────────────────┐ │
│  │  PostgreSQL  │         │         MySQL                │ │
│  │              │         │                              │ │
│  │ Partitioned  │         │  - alertscount (terminals)   │ │
│  │ Alert Tables │         │  - loginusers (usernames)    │ │
│  │              │         │  - sites (location data)     │ │
│  │ alerts_YYYY_ │         │                              │ │
│  │ MM_DD        │         │                              │ │
│  └──────────────┘         └──────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

### Component Interaction Flow

1. **Page Load**: React component mounts and requests initial data
2. **Shift Detection**: Backend calculates current shift based on server time
3. **Partition Selection**: Service determines which partition table(s) to query
4. **Data Aggregation**: Alerts are grouped by terminal and status
5. **Reference Data Join**: Terminal IDs are enriched with usernames from MySQL
6. **Response**: JSON data returned to frontend
7. **Table Rendering**: React component displays data in table format
8. **Auto-refresh**: Process repeats every 5 seconds
9. **Detail View**: User clicks cell, modal fetches detailed alert data

## Components and Interfaces

### Backend Components

#### PostgresDashboardController

**Responsibility**: Handle HTTP requests for dashboard data and details

**Methods**:
- `data(Request $request): JsonResponse` - Get alert count distribution
- `details(Request $request): JsonResponse` - Get detailed alerts for modal

**Request Parameters**:
```php
// data() endpoint
[
    'shift' => 'nullable|integer|in:1,2,3'  // Auto-detected if not provided
]

// details() endpoint
[
    'terminal' => 'required|string',
    'status' => 'required|string|in:open,close,total,criticalopen,criticalClose,totalCritical',
    'shift' => 'required|integer|in:1,2,3'
]
```

**Response Format**:
```json
// data() response
{
    "success": true,
    "data": [
        {
            "terminal": "192.168.1.100",
            "username": "John Doe",
            "open": 15,
            "close": 8,
            "total": 23,
            "criticalopen": 3,
            "criticalClose": 1,
            "totalCritical": 4
        }
    ],
    "grandtotalOpenAlerts": 150,
    "grandtotalCloseAlerts": 80,
    "grandtotalAlerts": 230,
    "grandtoalCriticalOpen": 30,
    "grandtotalCloseCriticalAlert": 10,
    "grandtotalCritical": 40,
    "shift": 1,
    "shift_time_range": {
        "start": "2026-01-12 07:00:00",
        "end": "2026-01-12 14:59:59"
    }
}

// details() response
{
    "success": true,
    "data": [
        {
            "id": 12345,
            "ATMID": "ATM001",
            "panelid": "PANEL123",
            "Zone": "North",
            "City": "Mumbai",
            "receivedtime": "2026-01-12 08:30:15",
            "alerttype": "Door Open",
            "comment": "Main door sensor triggered",
            "closedBy": "operator1",
            "closedtime": "2026-01-12 08:35:20"
        }
    ]
}
```

#### PostgresDashboardService

**Responsibility**: Business logic for dashboard data retrieval and aggregation

**Methods**:

```php
/**
 * Get alert count distribution for current shift
 * 
 * @param int|null $shift Optional shift override (1, 2, or 3)
 * @return array Dashboard data with counts and totals
 */
public function getAlertDistribution(?int $shift = null): array

/**
 * Get detailed alerts for a specific terminal and status
 * 
 * @param string $terminal Terminal IP address
 * @param string $status Status filter (open, close, total, etc.)
 * @param int $shift Shift number (1, 2, or 3)
 * @return Collection Alert details with site information
 */
public function getAlertDetails(string $terminal, string $status, int $shift): Collection

/**
 * Calculate current shift based on server time
 * 
 * @return int Shift number (1, 2, or 3)
 */
public function getCurrentShift(): int

/**
 * Get shift time range
 * 
 * @param int $shift Shift number
 * @return array ['start' => Carbon, 'end' => Carbon]
 */
public function getShiftTimeRange(int $shift): array

/**
 * Get partition table names for shift
 * 
 * @param int $shift Shift number
 * @return array Array of partition table names
 */
private function getPartitionTablesForShift(int $shift): array

/**
 * Query partition tables for alert counts
 * 
 * @param array $partitionTables Partition table names
 * @param Carbon $startTime Range start
 * @param Carbon $endTime Range end
 * @return Collection Raw alert count data
 */
private function queryPartitionsForCounts(array $partitionTables, Carbon $startTime, Carbon $endTime): Collection

/**
 * Enrich terminal data with usernames
 * 
 * @param Collection $terminalData Terminal alert counts
 * @return Collection Enriched data with usernames
 */
private function enrichWithUsernames(Collection $terminalData): Collection
```

**Dependencies**:
- `PartitionManager` - For partition table name resolution
- `DateExtractor` - For date-based partition naming
- `DB` facade - For database queries

### Frontend Components

#### PostgresDashboardPage Component

**Responsibility**: Main dashboard page with table display and auto-refresh

**State**:
```typescript
interface DashboardState {
    data: TerminalData[];
    totals: GrandTotals;
    loading: boolean;
    error: string | null;
    shift: number;
    shiftTimeRange: { start: string; end: string };
}

interface TerminalData {
    terminal: string;
    username: string;
    open: number;
    close: number;
    total: number;
    criticalopen: number;
    criticalClose: number;
    totalCritical: number;
}

interface GrandTotals {
    grandtotalOpenAlerts: number;
    grandtotalCloseAlerts: number;
    grandtotalAlerts: number;
    grandtoalCriticalOpen: number;
    grandtotalCloseCriticalAlert: number;
    grandtotalCritical: number;
}
```

**Methods**:
- `fetchDashboardData()` - Fetch data from API
- `handleCellClick(terminal, status)` - Open modal with details
- `startAutoRefresh()` - Start 5-second interval timer
- `stopAutoRefresh()` - Clear interval timer

**Lifecycle**:
1. `componentDidMount` - Fetch initial data, start auto-refresh
2. `componentWillUnmount` - Stop auto-refresh
3. Auto-refresh interval - Fetch updated data every 5 seconds

#### AlertDetailsModal Component

**Responsibility**: Display detailed alert information in modal popup

**Props**:
```typescript
interface AlertDetailsModalProps {
    isOpen: boolean;
    terminal: string;
    status: string;
    shift: number;
    onClose: () => void;
}
```

**State**:
```typescript
interface ModalState {
    alerts: AlertDetail[];
    loading: boolean;
    error: string | null;
}

interface AlertDetail {
    id: number;
    ATMID: string;
    panelid: string;
    Zone: string;
    City: string;
    receivedtime: string;
    alerttype: string;
    comment: string;
    closedBy: string;
    closedtime: string;
}
```

## Data Models

### PostgreSQL Partition Tables

**Table Name Pattern**: `alerts_YYYY_MM_DD` (e.g., `alerts_2026_01_12`)

**Schema** (mirrors MySQL alerts table):
```sql
CREATE TABLE alerts_2026_01_12 (
    id BIGINT PRIMARY KEY,
    panelid VARCHAR(50),
    seqno VARCHAR(50),
    zone VARCHAR(50),
    alarm VARCHAR(255),
    createtime TIMESTAMP,
    receivedtime TIMESTAMP,
    comment TEXT,
    status VARCHAR(50),
    sendtoclient VARCHAR(50),
    closedBy VARCHAR(100),
    closedtime TIMESTAMP,
    sendip VARCHAR(50),
    alerttype VARCHAR(100),
    location VARCHAR(255),
    priority VARCHAR(50),
    AlertUserStatus VARCHAR(50),
    level VARCHAR(50),
    sip2 VARCHAR(100),
    c_status VARCHAR(50),
    auto_alert VARCHAR(50),
    critical_alerts VARCHAR(50),
    Readstatus VARCHAR(50),
    synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sync_batch_id BIGINT NOT NULL
);

-- Indexes for performance
CREATE INDEX idx_alerts_2026_01_12_panelid ON alerts_2026_01_12 (panelid);
CREATE INDEX idx_alerts_2026_01_12_receivedtime ON alerts_2026_01_12 (receivedtime);
CREATE INDEX idx_alerts_2026_01_12_sendip ON alerts_2026_01_12 (sendip);
CREATE INDEX idx_alerts_2026_01_12_sip2 ON alerts_2026_01_12 (sip2);
CREATE INDEX idx_alerts_2026_01_12_status ON alerts_2026_01_12 (status);
CREATE INDEX idx_alerts_2026_01_12_critical ON alerts_2026_01_12 (critical_alerts);
```

### MySQL Reference Tables

**alertscount** (Terminal configuration):
```sql
-- Read-only access
SELECT ip AS terminal, userid 
FROM alertscount 
WHERE status = 1
```

**loginusers** (User information):
```sql
-- Read-only access
SELECT id, name 
FROM loginusers 
WHERE id = ?
```

**sites** (Location information):
```sql
-- Read-only access
SELECT OldPanelID, NewPanelID, ATMID, Zone, City, SiteAddress
FROM sites
WHERE OldPanelID = ? OR NewPanelID = ?
```

### Shift Time Ranges

**Shift Calculation Logic**:
```
Current Time    → Shift → Date Range
07:00 - 14:59   → 1     → [today 07:00:00, today 14:59:59]
15:00 - 22:59   → 2     → [today 15:00:00, today 22:59:59]
23:00 - 06:59   → 3     → [today 23:00:00, tomorrow 06:59:59]
```

**Partition Selection**:
- Shift 1: Query `alerts_{today}`
- Shift 2: Query `alerts_{today}`
- Shift 3: Query `alerts_{today}` UNION `alerts_{tomorrow}`

## Error Handling

### Partition Not Found

**Scenario**: Required partition table does not exist

**Handling**:
1. Check if partition exists using `PartitionManager::partitionTableExists()`
2. If not exists, skip that partition and continue
3. Return empty results for that date range
4. Log warning but do not throw exception

**Example**:
```php
$partitions = $this->getPartitionTablesForShift($shift);
$existingPartitions = array_filter($partitions, function($table) {
    return $this->partitionManager->partitionTableExists($table);
});

if (empty($existingPartitions)) {
    Log::warning('No partitions found for shift', ['shift' => $shift]);
    return collect();
}
```

### Database Query Failure

**Scenario**: PostgreSQL or MySQL query fails

**Handling**:
1. Catch exception in service layer
2. Log error with context (query, parameters, error message)
3. Return error response with HTTP 500 status
4. Include user-friendly error message

**Example**:
```php
try {
    $results = DB::connection('pgsql')->select($query);
} catch (\Exception $e) {
    Log::error('Dashboard query failed', [
        'error' => $e->getMessage(),
        'shift' => $shift,
        'partitions' => $partitions
    ]);
    
    throw new \Exception('Failed to fetch dashboard data: ' . $e->getMessage());
}
```

### Missing Reference Data

**Scenario**: Terminal has no user or site has no location data

**Handling**:
1. Use LEFT JOIN for optional data
2. Return null for missing fields
3. Frontend displays empty cells for null values
4. No error thrown

### Authentication Failure

**Scenario**: User not authenticated or lacks permissions

**Handling**:
1. Middleware returns HTTP 401 (not authenticated) or 403 (not authorized)
2. Frontend redirects to login page
3. Error message displayed to user

## Testing Strategy

### Unit Tests

**Backend Unit Tests**:

1. **PostgresDashboardService Tests**:
   - Test shift calculation for all time ranges
   - Test shift time range generation
   - Test partition table name generation for each shift
   - Test handling of missing partitions
   - Test username enrichment logic
   - Test grand total calculation

2. **PostgresDashboardController Tests**:
   - Test data endpoint with valid shift parameter
   - Test data endpoint with auto-detected shift
   - Test details endpoint with all status types
   - Test authentication middleware
   - Test permission middleware
   - Test error responses

**Frontend Unit Tests**:

1. **PostgresDashboardPage Tests**:
   - Test initial data fetch on mount
   - Test auto-refresh interval setup
   - Test auto-refresh cleanup on unmount
   - Test cell click handler
   - Test loading state display
   - Test error state display

2. **AlertDetailsModal Tests**:
   - Test modal open/close
   - Test data fetch on open
   - Test loading indicator
   - Test table rendering with data

### Property-Based Tests

Property-based tests will be defined in the Correctness Properties section below.

### Integration Tests

1. **End-to-End Dashboard Flow**:
   - Load dashboard page
   - Verify data fetched from correct partitions
   - Verify table displays correct counts
   - Click cell and verify modal opens
   - Verify modal displays correct details

2. **Shift Transition Test**:
   - Test dashboard behavior when shift changes
   - Verify correct partition selection after transition

3. **Multi-Partition Query Test**:
   - Test Shift 3 queries both current and next day partitions
   - Verify results are correctly merged

### Performance Tests

1. **Query Performance**:
   - Measure query time for single partition
   - Measure query time for multi-partition (Shift 3)
   - Verify queries complete within 1 second

2. **Auto-Refresh Performance**:
   - Verify 5-second refresh doesn't cause memory leaks
   - Verify UI remains responsive during refresh



## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system—essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Shift Calculation Correctness

*For any* server time, the calculated shift number should correspond to the correct time range: shift 1 for 07:00-14:59, shift 2 for 15:00-22:59, and shift 3 for 23:00-06:59.

**Validates: Requirements 2.1, 2.2, 2.3**

### Property 2: Partition Table Selection for Shift

*For any* shift number and date, the partition table names should follow the format `alerts_YYYY_MM_DD` for all dates within that shift's time range.

**Validates: Requirements 1.2, 6.1**

### Property 3: Alert Count Aggregation

*For any* set of alerts, when grouped by terminal and status, the sum of all individual terminal counts should equal the total number of alerts in the input set.

**Validates: Requirements 1.3**

### Property 4: Grand Total Calculation

*For any* collection of terminal data, the grand total for each alert type (open, close, critical open, critical close) should equal the sum of that alert type across all terminals.

**Validates: Requirements 1.5, 4.5**

### Property 5: Critical Alert Filtering

*For any* set of alerts, the count of critical open alerts should equal the number of alerts where status='O' AND critical_alerts='y', and the count of critical close alerts should equal the number where status='C' AND critical_alerts='y'.

**Validates: Requirements 3.1, 3.2, 3.3**

### Property 6: Critical Total Arithmetic

*For any* terminal's alert data, the totalCritical value should equal the sum of criticalOpen and criticalClose values.

**Validates: Requirements 3.4**

### Property 7: Terminal Username Enrichment

*For any* terminal that exists in the alertscount table with a valid userid, the enriched data should contain the username from the loginusers table, properly capitalized.

**Validates: Requirements 1.4, 7.1, 7.2, 7.3**

### Property 8: Missing Username Handling

*For any* terminal that does not exist in the alertscount table, the username field should be null or empty in the result set.

**Validates: Requirements 7.4, 9.3**

### Property 9: New Terminal Row Addition

*For any* data refresh where new terminals appear in the updated data, the table should contain rows for all terminals from both the previous and new datasets.

**Validates: Requirements 4.4**

### Property 10: Modal Query Filtering

*For any* request to fetch alert details, the query should filter by all three parameters: terminal, status, and shift.

**Validates: Requirements 5.2**

### Property 11: Site Data Enrichment

*For any* alert with a panelid that matches a site's OldPanelID or NewPanelID, the result should include the site's ATMID, Zone, and City fields.

**Validates: Requirements 5.3**

### Property 12: Missing Site Data Handling

*For any* alert whose panelid does not match any site record, the site-related columns (ATMID, Zone, City) should be null.

**Validates: Requirements 9.4**

### Property 13: Non-Existent Partition Handling

*For any* partition table name that does not exist in the database, the query should return an empty result set without throwing an exception.

**Validates: Requirements 6.4, 9.1**

### Property 14: Query Failure Error Response

*For any* database query that fails with an exception, the system should log the error and return an HTTP 500 response with an error message.

**Validates: Requirements 9.2, 9.5**

### Property 15: Shift Parameter Validation

*For any* request to the data endpoint with a shift parameter, the system should accept values 1, 2, or 3, and reject any other values with a validation error.

**Validates: Requirements 8.2**

### Property 16: Details Parameter Validation

*For any* request to the details endpoint, the system should require terminal, status, and shift parameters, and reject requests missing any of these with a validation error.

**Validates: Requirements 8.4**

### Property 17: JSON Response Format

*For any* successful API request, the response should be valid JSON with an HTTP 200 status code and include a "success" field set to true.

**Validates: Requirements 8.5**

### Property 18: Authentication Requirement

*For any* unauthenticated request to dashboard endpoints, the system should return HTTP 401 Unauthorized status.

**Validates: Requirements 11.1, 11.4**

### Property 19: Authorization Requirement

*For any* authenticated request from a user without the 'dashboard.view' permission, the system should return HTTP 403 Forbidden status.

**Validates: Requirements 11.2, 11.3**

### Property 20: Username Capitalization

*For any* username retrieved from the loginusers table, the displayed username should have the first letter of each word capitalized (ucwords format).

**Validates: Requirements 7.3**

