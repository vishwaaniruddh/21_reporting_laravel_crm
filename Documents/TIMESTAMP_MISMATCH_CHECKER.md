# Timestamp Mismatch Checker

## Overview

Tool to compare timestamps between MySQL and PostgreSQL partition tables, identifying records where `createtime`, `receivedtime`, or `closedtime` don't match.

## Usage

### Command Line

```bash
# Check today's date
php codes/check-timestamp-mismatches.php

# Check specific date
php codes/check-timestamp-mismatches.php 2026-03-04
```

### Web Interface

Access: `http://localhost:9000/timestamp-mismatches`

Features:
- Select any date to check
- Visual summary (total alerts, matched, mismatched)
- Detailed table showing all mismatches
- Highlights timestamp differences
- Shows time difference in hours

## What It Checks

### Comparison Logic

For each alert on the specified date:

1. **Existence Check**
   - Alert in MySQL but not PostgreSQL → "missing_in_postgres"
   - Alert in PostgreSQL but not MySQL → "missing_in_mysql"

2. **Timestamp Comparison** (with 1-second tolerance)
   - `createtime`: MySQL vs PostgreSQL
   - `receivedtime`: MySQL vs PostgreSQL
   - `closedtime`: MySQL vs PostgreSQL

3. **Mismatch Detection**
   - If difference > 1 second → Mismatch flagged
   - Calculates time difference in hours
   - Identifies which specific timestamps don't match

## Output

### Command Line Output

```
=== Results ===
Total alerts in MySQL: 1250
Total alerts in PostgreSQL: 1250
Matched (timestamps identical): 1200
Mismatched: 50

❌ Found 50 mismatches!

First 10 mismatches:
Alert ID     Issue                MySQL createtime     PG createtime        Diff (hours)
40614003     timestamp_mismatch   2026-03-04 19:28:17  2026-03-04 13:58:17  5.5
...
```

### Web Interface

Visual dashboard showing:
- Summary cards (total MySQL, total PostgreSQL, matched, mismatched)
- Detailed table with all mismatches
- Color-coded indicators (red for mismatches)
- Time difference in hours

### JSON Output

Saved to: `storage/app/timestamp-mismatches-YYYY-MM-DD.json`

```json
{
    "date": "2026-03-04",
    "partition_table": "alerts_2026_03_04",
    "generated_at": "2026-03-04 15:30:00",
    "summary": {
        "total_mysql": 1250,
        "total_postgres": 1250,
        "matched": 1200,
        "mismatched": 50
    },
    "mismatches": [
        {
            "id": 40614003,
            "issue": "timestamp_mismatch",
            "createtime_mismatch": true,
            "receivedtime_mismatch": true,
            "closedtime_mismatch": false,
            "mysql_createtime": "2026-03-04 19:28:17",
            "mysql_receivedtime": "2026-03-04 19:28:12",
            "mysql_closedtime": "2026-03-04 19:31:27",
            "pg_createtime": "2026-03-04 13:58:17",
            "pg_receivedtime": "2026-03-04 13:58:12",
            "pg_closedtime": "2026-03-04 14:01:27",
            "createtime_diff_hours": 5.5,
            "receivedtime_diff_hours": 5.5,
            "closedtime_diff_hours": 5.5,
            "panelid": "096318",
            "zone": "003",
            "alarm": "BA"
        }
    ]
}
```

## Use Cases

### 1. Verify Timezone Fix

After applying timezone configuration:

```bash
php codes/check-timestamp-mismatches.php
```

Expected: 0 mismatches (all timestamps match)

### 2. Identify Data Correction Needs

Before running data fix script:

```bash
php codes/check-timestamp-mismatches.php 2026-03-04
```

Shows which records need correction.

### 3. Monitor Sync Quality

Regular checks to ensure sync is working correctly:

```bash
# Check last 7 days
for i in {0..6}; do
    date=$(date -d "-$i days" +%Y-%m-%d)
    php codes/check-timestamp-mismatches.php $date
done
```

### 4. Troubleshoot Specific Dates

If users report incorrect timestamps for a specific date:

```bash
php codes/check-timestamp-mismatches.php 2026-02-15
```

## Common Mismatch Patterns

### Pattern 1: Timezone Conversion (5.5 hours)

```
MySQL:      2026-03-04 19:28:17
PostgreSQL: 2026-03-04 13:58:17
Difference: 5.5 hours
```

**Cause**: IST → UTC conversion  
**Fix**: Run `php codes/fix-timezone-data.php`

### Pattern 2: Missing Records

```
Issue: missing_in_postgres
MySQL: Has record
PostgreSQL: No record
```

**Cause**: Sync incomplete or failed  
**Fix**: Re-run sync for that date

### Pattern 3: Null Mismatches

```
MySQL closedtime: 2026-03-04 10:30:00
PG closedtime: NULL
```

**Cause**: Update not synced  
**Fix**: Trigger update sync

## Integration with Fix Script

### Workflow

1. **Check for mismatches**:
   ```bash
   php codes/check-timestamp-mismatches.php
   ```

2. **If mismatches found, run fix**:
   ```bash
   php codes/fix-timezone-data.php
   ```

3. **Verify fix worked**:
   ```bash
   php codes/check-timestamp-mismatches.php
   ```

4. **Expected**: 0 mismatches

## Files

- **Script**: `codes/check-timestamp-mismatches.php`
- **Controller**: `app/Http/Controllers/TimestampMismatchController.php`
- **View**: `resources/views/timestamp-mismatches/index.blade.php`
- **Route**: `/timestamp-mismatches` (web), `/api/timestamp-mismatches/check` (API)
- **Output**: `storage/app/timestamp-mismatches-YYYY-MM-DD.json`

## API Endpoint

### POST /api/timestamp-mismatches/check

**Request:**
```json
{
    "date": "2026-03-04"
}
```

**Response:**
```json
{
    "success": true,
    "date": "2026-03-04",
    "partition_table": "alerts_2026_03_04",
    "summary": {
        "total_mysql": 1250,
        "total_postgres": 1250,
        "matched": 1200,
        "mismatched": 50
    },
    "mismatches": [...]
}
```

## Troubleshooting

### "Partition table does not exist"

**Cause**: No data synced for that date yet  
**Solution**: Check a different date or run initial sync

### "No alerts found in MySQL"

**Cause**: No alerts on that date  
**Solution**: Normal - no data to compare

### Large number of mismatches

**Cause**: Timezone configuration not applied or data not fixed  
**Solution**: 
1. Verify timezone config in `config/database.php`
2. Run `php codes/fix-timezone-data.php`
3. Restart NSSM services

## Best Practices

1. **Check after configuration changes**: Verify timezone fix worked
2. **Regular monitoring**: Check daily or weekly
3. **Before data migration**: Ensure data quality
4. **After sync issues**: Identify affected records
5. **Document findings**: Save JSON output for records
