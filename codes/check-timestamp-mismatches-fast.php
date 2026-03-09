<?php

/**
 * Fast Timestamp Mismatch Checker
 * 
 * Uses SQL JOIN to compare timestamps directly in the database.
 * Much faster than fetching and comparing in PHP.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

// Get date to check (default: today)
$checkDate = isset($argv[1]) ? Carbon::parse($argv[1]) : Carbon::today();
$dateStr = $checkDate->toDateString();
$partitionTable = 'alerts_' . $checkDate->format('Y_m_d');

echo "=== Fast Timestamp Mismatch Checker ===\n";
echo "Date: {$dateStr}\n";
echo "Partition Table: {$partitionTable}\n\n";

// Check if partition table exists
$tableExists = DB::connection('pgsql')
    ->select("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = ?)", [$partitionTable]);

if (!$tableExists[0]->exists) {
    echo "❌ Partition table '{$partitionTable}' does not exist in PostgreSQL.\n";
    exit(1);
}

echo "Checking mismatches using SQL JOIN (fast method)...\n\n";

$startTime = microtime(true);

// Use dblink to query MySQL from PostgreSQL and compare
// This is much faster than fetching in PHP
$query = "
WITH mysql_data AS (
    SELECT 
        id,
        panelid,
        zone,
        createtime,
        receivedtime,
        closedtime
    FROM dblink(
        'host=127.0.0.1 port=3306 dbname=esurv user=root password=',
        'SELECT id, panelid, zone, createtime, receivedtime, closedtime 
         FROM alerts 
         WHERE DATE(receivedtime) = ''{$dateStr}'''
    ) AS mysql_alerts(
        id bigint,
        panelid varchar,
        zone varchar,
        createtime timestamp,
        receivedtime timestamp,
        closedtime timestamp
    )
),
comparison AS (
    SELECT 
        COALESCE(m.id, p.id) as alert_id,
        m.panelid as mysql_panelid,
        m.zone as mysql_zone,
        m.createtime as mysql_createtime,
        m.receivedtime as mysql_receivedtime,
        m.closedtime as mysql_closedtime,
        p.createtime as pg_createtime,
        p.receivedtime as pg_receivedtime,
        p.closedtime as pg_closedtime,
        CASE 
            WHEN m.id IS NULL THEN 'missing_in_mysql'
            WHEN p.id IS NULL THEN 'missing_in_postgres'
            WHEN ABS(EXTRACT(EPOCH FROM (m.createtime - p.createtime))) > 1 
                OR ABS(EXTRACT(EPOCH FROM (m.receivedtime - p.receivedtime))) > 1
                OR (m.closedtime IS NOT NULL AND p.closedtime IS NOT NULL 
                    AND ABS(EXTRACT(EPOCH FROM (m.closedtime - p.closedtime))) > 1)
                OR (m.closedtime IS NULL AND p.closedtime IS NOT NULL)
                OR (m.closedtime IS NOT NULL AND p.closedtime IS NULL)
            THEN 'timestamp_mismatch'
            ELSE 'matched'
        END as status,
        ROUND(EXTRACT(EPOCH FROM (m.createtime - p.createtime)) / 3600, 2) as createtime_diff_hours,
        ROUND(EXTRACT(EPOCH FROM (m.receivedtime - p.receivedtime)) / 3600, 2) as receivedtime_diff_hours,
        ROUND(EXTRACT(EPOCH FROM (m.closedtime - p.closedtime)) / 3600, 2) as closedtime_diff_hours
    FROM mysql_data m
    FULL OUTER JOIN {$partitionTable} p ON m.id = p.id
)
SELECT 
    COUNT(*) FILTER (WHERE status = 'matched') as matched_count,
    COUNT(*) FILTER (WHERE status = 'timestamp_mismatch') as mismatch_count,
    COUNT(*) FILTER (WHERE status = 'missing_in_mysql') as missing_mysql_count,
    COUNT(*) FILTER (WHERE status = 'missing_in_postgres') as missing_pg_count,
    COUNT(*) as total_count
FROM comparison;
";

try {
    // Note: This requires dblink extension in PostgreSQL
    // If not available, fall back to PHP method
    $summary = DB::connection('pgsql')->select($query);
    
    $duration = microtime(true) - $startTime;
    
    echo "=== Results (completed in " . round($duration, 2) . " seconds) ===\n";
    echo "Total records: " . number_format($summary[0]->total_count) . "\n";
    echo "Matched: " . number_format($summary[0]->matched_count) . "\n";
    echo "Timestamp mismatches: " . number_format($summary[0]->mismatch_count) . "\n";
    echo "Missing in MySQL: " . number_format($summary[0]->missing_mysql_count) . "\n";
    echo "Missing in PostgreSQL: " . number_format($summary[0]->missing_pg_count) . "\n\n";
    
    if ($summary[0]->mismatch_count > 0 || $summary[0]->missing_mysql_count > 0 || $summary[0]->missing_pg_count > 0) {
        echo "❌ Found issues! Use web interface for details.\n";
        echo "URL: http://localhost:9000/timestamp-mismatches\n";
    } else {
        echo "✅ All timestamps match perfectly!\n";
    }
    
} catch (\Exception $e) {
    if (str_contains($e->getMessage(), 'dblink')) {
        echo "⚠️  dblink extension not available. Using standard method...\n\n";
        
        // Fall back to counting method (still faster than full comparison)
        $mysqlCount = DB::connection('mysql')
            ->table('alerts')
            ->whereDate('receivedtime', $dateStr)
            ->count();
        
        $pgCount = DB::connection('pgsql')
            ->table($partitionTable)
            ->count();
        
        echo "MySQL alerts: " . number_format($mysqlCount) . "\n";
        echo "PostgreSQL alerts: " . number_format($pgCount) . "\n\n";
        
        if ($mysqlCount !== $pgCount) {
            echo "⚠️  Record count mismatch! Difference: " . abs($mysqlCount - $pgCount) . "\n";
        }
        
        echo "For detailed comparison, use the web interface:\n";
        echo "http://localhost:9000/timestamp-mismatches\n";
    } else {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}
