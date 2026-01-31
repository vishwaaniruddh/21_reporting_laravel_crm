# How Partition Queries Work - Explained

## Your Question
> "We don't have an `alerts` table in PostgreSQL, only partition tables like `alerts_2026_01_08`, `alerts_2026_01_09`, etc. How is this working?"

## The Answer

You're absolutely correct! There is **NO** `alerts` table in PostgreSQL. The controller **ALWAYS** uses the **PartitionQueryRouter** to query the partition tables.

---

## How It Works

### Step 1: Controller Checks
```php
// In VMAlertController.php - index() method
$usePartitions = $this->shouldUsePartitionRouter($fromDate, $toDate);

// This ALWAYS returns true!
protected function shouldUsePartitionRouter(): bool
{
    // ALWAYS use partition router now (single alerts table has been removed)
    return true;
}
```

### Step 2: PartitionQueryRouter Takes Over
```php
// Controller calls:
$result = $this->getAlertsViaRouter($fromDate, $toDate, $validated, $perPage, $page);

// Which uses:
$this->partitionRouter->queryDateRange($startDate, $endDate, $filters, $options);
```

### Step 3: Router Finds Partition Tables
```php
// PartitionQueryRouter.php
public function queryDateRange(Carbon $startDate, Carbon $endDate, ...)
{
    // 1. Get all partitions in the date range
    $partitions = $this->getPartitionsInRange($startDate, $endDate);
    
    // For date 2026-01-09, this returns:
    // ['alerts_2026_01_09']
    
    // 2. Build UNION ALL query across partitions
    $sql = $this->buildUnionQuery($partitions, $filters, $options);
    
    // 3. Execute and return results
    return DB::connection('pgsql')->select($sql);
}
```

---

## Actual SQL Generated

### For Single Date (2026-01-09)
```sql
SELECT 
    id,
    panelid,
    zone,
    alarm,
    alerttype,
    createtime,
    receivedtime,
    closedtime,
    closedBy,
    comment,
    sendip,
    CASE 
        WHEN closedtime IS NOT NULL AND receivedtime IS NOT NULL 
        THEN ROUND(EXTRACT(EPOCH FROM (closedtime::timestamp - receivedtime::timestamp))/3600, 2)
        ELSE 0 
    END as aging
FROM alerts_2026_01_09                    -- ← Queries partition table directly!
WHERE createtime >= '2026-01-09 00:00:00'
  AND createtime <= '2026-01-09 23:59:59'
ORDER BY id DESC
LIMIT 25 OFFSET 0;
```

### For Date Range (2026-01-08 to 2026-01-10)
```sql
(
    SELECT * FROM alerts_2026_01_08
    WHERE createtime >= '2026-01-08 00:00:00'
      AND createtime <= '2026-01-08 23:59:59'
)
UNION ALL
(
    SELECT * FROM alerts_2026_01_09
    WHERE createtime >= '2026-01-09 00:00:00'
      AND createtime <= '2026-01-09 23:59:59'
)
UNION ALL
(
    SELECT * FROM alerts_2026_01_10
    WHERE createtime >= '2026-01-10 00:00:00'
      AND createtime <= '2026-01-10 23:59:59'
)
ORDER BY id DESC
LIMIT 25 OFFSET 0;
```

---

## The Flow

```
User Request: GET /api/vm-alerts?from_date=2026-01-09
    ↓
VMAlertController.index()
    ↓
shouldUsePartitionRouter() → ALWAYS returns TRUE
    ↓
getAlertsViaRouter()
    ↓
PartitionQueryRouter.queryDateRange()
    ↓
getPartitionsInRange() → Finds: ['alerts_2026_01_09']
    ↓
buildUnionQuery() → Builds SQL for partition table
    ↓
DB::connection('pgsql')->select($sql)
    ↓
Query: SELECT * FROM alerts_2026_01_09 WHERE ...
    ↓
Returns results
```

---

## Why This Design?

### Benefits:
1. **No single large table** - Data split by date
2. **Faster queries** - Only scans relevant partition
3. **Easy maintenance** - Can drop old partitions
4. **Scalable** - Add new partitions daily

### How Partitions Are Created:
```php
// Sync process creates partition tables daily
CREATE TABLE alerts_2026_01_09 (
    LIKE alerts_template INCLUDING ALL
);

// Data synced from MySQL → PostgreSQL partition table
INSERT INTO alerts_2026_01_09 
SELECT * FROM mysql.alerts 
WHERE DATE(createtime) = '2026-01-09';
```

---

## What About Missing Partitions?

If you query a date without a partition:

```php
// Request: from_date=2026-01-15 (partition doesn't exist yet)

$partitions = $this->getPartitionsInRange($startDate, $endDate);
// Returns: empty collection

if ($partitions->isEmpty()) {
    Log::info('No partitions found in date range');
    return collect([]); // Returns empty results
}
```

**Result**: Empty response, no error!

---

## Summary

✅ **NO** `alerts` table exists in PostgreSQL
✅ **ONLY** partition tables exist: `alerts_2026_01_08`, `alerts_2026_01_09`, etc.
✅ Controller **ALWAYS** uses PartitionQueryRouter
✅ Router automatically finds and queries the correct partition table(s)
✅ For date ranges, uses UNION ALL across multiple partitions
✅ Handles missing partitions gracefully

The code you saw with `->table('alerts')` is actually **dead code** in the `getAlertsSingleTable()` method that is **never called** because `shouldUsePartitionRouter()` always returns `true`!

---

## To Verify

Check your PostgreSQL database:

```sql
-- List all partition tables
SELECT tablename 
FROM pg_tables 
WHERE schemaname = 'public' 
  AND tablename LIKE 'alerts_%'
ORDER BY tablename;

-- You'll see:
-- alerts_2026_01_08
-- alerts_2026_01_09
-- alerts_2026_01_10
-- etc.

-- But NO 'alerts' table!
```

This is the correct architecture for handling large time-series data!
