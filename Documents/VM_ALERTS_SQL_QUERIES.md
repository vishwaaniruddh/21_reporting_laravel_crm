# VM Alerts SQL Queries

## Main Query Structure

The VM Alerts controller performs **2 main SQL queries**:

---

## Query 1: Fetch Alerts from PostgreSQL

### Base Query (without filters)
```sql
SELECT 
    alerts.id,
    alerts.panelid,
    alerts.zone,
    alerts.alarm,
    alerts.alerttype,
    alerts.createtime,
    alerts.receivedtime,
    alerts.closedtime,
    alerts.closedBy,
    alerts.comment,
    alerts.sendip,
    CASE 
        WHEN alerts.closedtime IS NOT NULL AND alerts.receivedtime IS NOT NULL 
        THEN ROUND(EXTRACT(EPOCH FROM (alerts.closedtime::timestamp - alerts.receivedtime::timestamp))/3600, 2)
        ELSE 0 
    END as aging
FROM alerts
WHERE createtime >= '2026-01-09 00:00:00'
  AND createtime <= '2026-01-09 23:59:59'
ORDER BY id DESC
LIMIT 25 OFFSET 0;
```

### With Filters Applied
```sql
SELECT 
    alerts.id,
    alerts.panelid,
    alerts.zone,
    alerts.alarm,
    alerts.alerttype,
    alerts.createtime,
    alerts.receivedtime,
    alerts.closedtime,
    alerts.closedBy,
    alerts.comment,
    alerts.sendip,
    CASE 
        WHEN alerts.closedtime IS NOT NULL AND alerts.receivedtime IS NOT NULL 
        THEN ROUND(EXTRACT(EPOCH FROM (alerts.closedtime::timestamp - alerts.receivedtime::timestamp))/3600, 2)
        ELSE 0 
    END as aging
FROM alerts
WHERE createtime >= '2026-01-09 00:00:00'
  AND createtime <= '2026-01-09 23:59:59'
  AND panelid = 'PANEL123'              -- if panelid filter applied
  AND sendip = '192.168.1.100'          -- if dvrip filter applied
  AND alerttype = 'Motion'               -- if panel_type filter applied
ORDER BY id DESC
LIMIT 25 OFFSET 0;
```

---

## Query 2: Enrich with Sites Data

After fetching alerts, the controller extracts all unique `panelid` values and queries the `sites` table:

```sql
SELECT 
    OldPanelID,
    NewPanelID,
    Customer,
    Zone,
    ATMID,
    SiteAddress,
    City,
    State,
    DVRIP,
    Panel_Make,
    Bank
FROM sites
WHERE (OldPanelID IN ('PANEL123', 'PANEL456', 'PANEL789')
   OR NewPanelID IN ('PANEL123', 'PANEL456', 'PANEL789'));
```

### With Site Filters (customer, atmid)
If customer or atmid filters are applied, the query first fetches matching panel IDs:

```sql
-- Step 1: Get panel IDs from sites table
SELECT OldPanelID, NewPanelID
FROM sites
WHERE Customer = 'ABC Bank'           -- if customer filter applied
  AND ATMID = 'ATM001'                -- if atmid filter applied
  AND DVRIP = '192.168.1.100';        -- if dvrip filter applied

-- Step 2: Use those panel IDs to filter alerts
SELECT ... FROM alerts
WHERE panelid IN ('PANEL123', 'PANEL456')
  AND createtime >= '2026-01-09 00:00:00'
  AND createtime <= '2026-01-09 23:59:59';

-- Step 3: Enrich with full sites data
SELECT ... FROM sites
WHERE (OldPanelID IN ('PANEL123', 'PANEL456')
   OR NewPanelID IN ('PANEL123', 'PANEL456'));
```

---

## Query 3: Filter Options

### Get Customers
```sql
SELECT DISTINCT Customer
FROM sites
WHERE Customer IS NOT NULL
  AND Customer != ''
ORDER BY Customer ASC;
```

### Get Panel Types
```sql
SELECT DISTINCT Panel_Make
FROM sites
WHERE Panel_Make IS NOT NULL
  AND Panel_Make != ''
ORDER BY Panel_Make ASC;
```

---

## Partition Router Queries

When using date-partitioned tables (for dates with partitions):

```sql
-- Query partition table instead of main alerts table
SELECT 
    alerts.id,
    alerts.panelid,
    alerts.zone,
    alerts.alarm,
    alerts.alerttype,
    alerts.createtime,
    alerts.receivedtime,
    alerts.closedtime,
    alerts.closedBy,
    alerts.comment,
    alerts.sendip,
    CASE 
        WHEN alerts.closedtime IS NOT NULL AND alerts.receivedtime IS NOT NULL 
        THEN ROUND(EXTRACT(EPOCH FROM (alerts.closedtime::timestamp - alerts.receivedtime::timestamp))/3600, 2)
        ELSE 0 
    END as aging
FROM alerts_2026_01_09                  -- Partition table for specific date
WHERE createtime >= '2026-01-09 00:00:00'
  AND createtime <= '2026-01-09 23:59:59'
ORDER BY id DESC
LIMIT 25 OFFSET 0;
```

---

## CSV Export Query

For CSV export (downloads ALL data for the date):

```sql
-- No LIMIT, no OFFSET - fetches all records
SELECT 
    alerts.id,
    alerts.panelid,
    alerts.zone,
    alerts.alarm,
    alerts.alerttype,
    alerts.createtime,
    alerts.receivedtime,
    alerts.closedtime,
    alerts.closedBy,
    alerts.comment,
    alerts.sendip,
    CASE 
        WHEN alerts.closedtime IS NOT NULL AND alerts.receivedtime IS NOT NULL 
        THEN ROUND(EXTRACT(EPOCH FROM (alerts.closedtime::timestamp - alerts.receivedtime::timestamp))/3600, 2)
        ELSE 0 
    END as aging
FROM alerts
WHERE createtime >= '2026-01-09 00:00:00'
  AND createtime <= '2026-01-09 23:59:59'
ORDER BY id DESC;

-- Then enriched with sites data
-- Processes in chunks of 1000 records
```

---

## Complete Flow Example

### Request
```
GET /api/vm-alerts?from_date=2026-01-09&customer=ABC Bank&per_page=25&page=1
```

### SQL Execution Order

**Step 1: Get panel IDs for customer filter**
```sql
SELECT OldPanelID, NewPanelID
FROM sites
WHERE Customer = 'ABC Bank';
-- Returns: ['PANEL123', 'PANEL456', 'PANEL789']
```

**Step 2: Fetch alerts with those panel IDs**
```sql
SELECT 
    alerts.id,
    alerts.panelid,
    alerts.zone,
    alerts.alarm,
    alerts.alerttype,
    alerts.createtime,
    alerts.receivedtime,
    alerts.closedtime,
    alerts.closedBy,
    alerts.comment,
    alerts.sendip,
    CASE 
        WHEN alerts.closedtime IS NOT NULL AND alerts.receivedtime IS NOT NULL 
        THEN ROUND(EXTRACT(EPOCH FROM (alerts.closedtime::timestamp - alerts.receivedtime::timestamp))/3600, 2)
        ELSE 0 
    END as aging
FROM alerts
WHERE createtime >= '2026-01-09 00:00:00'
  AND createtime <= '2026-01-09 23:59:59'
  AND panelid IN ('PANEL123', 'PANEL456', 'PANEL789')
ORDER BY id DESC
LIMIT 25 OFFSET 0;
-- Returns: 25 alert records
```

**Step 3: Enrich with full sites data**
```sql
SELECT 
    OldPanelID,
    NewPanelID,
    Customer,
    Zone,
    ATMID,
    SiteAddress,
    City,
    State,
    DVRIP,
    Panel_Make,
    Bank
FROM sites
WHERE (OldPanelID IN ('PANEL123', 'PANEL456', 'PANEL789')
   OR NewPanelID IN ('PANEL123', 'PANEL456', 'PANEL789'));
-- Returns: Site details for matching panels
```

**Step 4: Merge data in PHP**
```php
// Controller merges alerts with sites data
foreach ($alerts as $alert) {
    $site = $siteLookup[$alert->panelid] ?? null;
    $alert->Customer = $site->Customer ?? null;
    $alert->ATMID = $site->ATMID ?? null;
    $alert->SiteAddress = $site->SiteAddress ?? null;
    // ... etc
}
```

---

## Performance Notes

1. **Indexes Required**:
   - `alerts.createtime` (for date filtering)
   - `alerts.panelid` (for joins)
   - `sites.OldPanelID` (for lookups)
   - `sites.NewPanelID` (for lookups)
   - `sites.Customer` (for customer filter)

2. **Partition Tables**:
   - For dates with partitions, queries use `alerts_YYYY_MM_DD` tables
   - Significantly faster for large datasets
   - Automatic routing via PartitionQueryRouter

3. **Caching**:
   - Filter options (customers, panel types) cached for 1 hour
   - Reduces repeated queries to sites table

---

## To Add VM-Specific Logic

You can modify the queries in `VMAlertController.php`:

### Example: Filter only VM alerts
```php
// In buildBaseQuery() method
return DB::connection('pgsql')
    ->table('alerts')
    ->select([...])
    ->where('alert_type', 'VM')  // Add VM filter
    ->orWhere('alarm', 'LIKE', 'VM%');  // Or VM-specific alarm codes
```

### Example: Join with VM-specific table
```php
// In buildBaseQuery() method
return DB::connection('pgsql')
    ->table('alerts')
    ->leftJoin('vm_details', 'alerts.panelid', '=', 'vm_details.panel_id')
    ->select([
        'alerts.*',
        'vm_details.vm_type',
        'vm_details.vm_status'
    ]);
```

This is the current query structure. You can now modify it for VM-specific requirements!
