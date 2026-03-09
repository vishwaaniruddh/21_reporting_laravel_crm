<?php

/**
 * Diagnostic Script for Alert ID 1001097236
 * 
 * This script investigates the timestamp mismatch between MySQL and PostgreSQL
 * for the specific alert mentioned by the user.
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== ALERT 1001097236 DIAGNOSTIC ===\n\n";

$alertId = 1001097236;

// Step 1: Check MySQL data (raw query to avoid any conversion)
echo "1. MySQL Data (Raw):\n";
echo str_repeat("-", 80) . "\n";

$mysqlAlert = DB::connection('mysql')
    ->table('alerts')
    ->where('id', $alertId)
    ->first();

if ($mysqlAlert) {
    echo "Alert found in MySQL:\n";
    echo "  ID: {$mysqlAlert->id}\n";
    echo "  Panel ID: {$mysqlAlert->panelid}\n";
    echo "  Status: {$mysqlAlert->status}\n";
    echo "  createtime: {$mysqlAlert->createtime}\n";
    echo "  receivedtime (opentime): {$mysqlAlert->receivedtime}\n";
    echo "  closedtime: " . ($mysqlAlert->closedtime ?? 'NULL') . "\n";
    echo "  closedBy: " . ($mysqlAlert->closedBy ?? 'NULL') . "\n";
} else {
    echo "Alert NOT found in MySQL!\n";
}

echo "\n";

// Step 2: Determine partition table
echo "2. Partition Table Determination:\n";
echo str_repeat("-", 80) . "\n";

if ($mysqlAlert && $mysqlAlert->receivedtime) {
    $receivedtime = $mysqlAlert->receivedtime;
    
    // Extract date (YYYY-MM-DD)
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $receivedtime, $matches)) {
        $dateStr = $matches[1];
        $partitionTable = 'alerts_' . str_replace('-', '_', $dateStr);
        
        echo "  receivedtime: {$receivedtime}\n";
        echo "  Extracted date: {$dateStr}\n";
        echo "  Partition table: {$partitionTable}\n";
    } else {
        echo "  ERROR: Could not extract date from receivedtime: {$receivedtime}\n";
        $partitionTable = null;
    }
} else {
    echo "  ERROR: No receivedtime available\n";
    $partitionTable = null;
}

echo "\n";

// Step 3: Check PostgreSQL data
echo "3. PostgreSQL Data:\n";
echo str_repeat("-", 80) . "\n";

if ($partitionTable) {
    // Check if partition table exists
    $tableExists = DB::connection('pgsql')
        ->select("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = ?)", [$partitionTable]);
    
    if ($tableExists[0]->exists) {
        echo "Partition table '{$partitionTable}' exists\n\n";
        
        $pgAlert = DB::connection('pgsql')
            ->table($partitionTable)
            ->where('id', $alertId)
            ->first();
        
        if ($pgAlert) {
            echo "Alert found in PostgreSQL:\n";
            echo "  ID: {$pgAlert->id}\n";
            echo "  Panel ID: {$pgAlert->panelid}\n";
            echo "  Status: {$pgAlert->status}\n";
            echo "  createtime: {$pgAlert->createtime}\n";
            echo "  receivedtime (opentime): {$pgAlert->receivedtime}\n";
            echo "  closedtime: " . ($pgAlert->closedtime ?? 'NULL') . "\n";
            echo "  closedBy: " . ($pgAlert->closedby ?? 'NULL') . "\n";
            echo "  synced_at: {$pgAlert->synced_at}\n";
        } else {
            echo "Alert NOT found in partition table '{$partitionTable}'\n";
        }
    } else {
        echo "Partition table '{$partitionTable}' does NOT exist\n";
    }
}

echo "\n";

// Step 4: Compare timestamps
echo "4. Timestamp Comparison:\n";
echo str_repeat("-", 80) . "\n";

if ($mysqlAlert && isset($pgAlert)) {
    $mismatches = [];
    
    // Compare createtime
    if ($mysqlAlert->createtime !== $pgAlert->createtime) {
        $mismatches[] = [
            'column' => 'createtime',
            'mysql' => $mysqlAlert->createtime,
            'postgres' => $pgAlert->createtime,
            'diff' => calculateTimeDiff($mysqlAlert->createtime, $pgAlert->createtime)
        ];
    }
    
    // Compare receivedtime
    if ($mysqlAlert->receivedtime !== $pgAlert->receivedtime) {
        $mismatches[] = [
            'column' => 'receivedtime (opentime)',
            'mysql' => $mysqlAlert->receivedtime,
            'postgres' => $pgAlert->receivedtime,
            'diff' => calculateTimeDiff($mysqlAlert->receivedtime, $pgAlert->receivedtime)
        ];
    }
    
    // Compare closedtime
    if ($mysqlAlert->closedtime !== $pgAlert->closedtime) {
        $mismatches[] = [
            'column' => 'closedtime',
            'mysql' => $mysqlAlert->closedtime ?? 'NULL',
            'postgres' => $pgAlert->closedtime ?? 'NULL',
            'diff' => calculateTimeDiff($mysqlAlert->closedtime, $pgAlert->closedtime)
        ];
    }
    
    if (empty($mismatches)) {
        echo "✓ All timestamps match perfectly!\n";
    } else {
        echo "✗ MISMATCHES DETECTED:\n\n";
        foreach ($mismatches as $mismatch) {
            echo "  Column: {$mismatch['column']}\n";
            echo "    MySQL:      {$mismatch['mysql']}\n";
            echo "    PostgreSQL: {$mismatch['postgres']}\n";
            echo "    Difference: {$mismatch['diff']}\n";
            echo "\n";
        }
    }
} else {
    echo "Cannot compare - data not available in both databases\n";
}

echo "\n";

// Step 5: Check timezone settings
echo "5. Database Timezone Settings:\n";
echo str_repeat("-", 80) . "\n";

// MySQL timezone
$mysqlTz = DB::connection('mysql')->select("SELECT @@session.time_zone as tz, NOW() as now");
echo "MySQL:\n";
echo "  Timezone: {$mysqlTz[0]->tz}\n";
echo "  NOW(): {$mysqlTz[0]->now}\n\n";

// PostgreSQL timezone
$pgTz = DB::connection('pgsql')->select("SHOW timezone");
$pgNow = DB::connection('pgsql')->select("SELECT NOW() as now");
echo "PostgreSQL:\n";
echo "  Timezone: " . (isset($pgTz[0]->TimeZone) ? $pgTz[0]->TimeZone : 'Unknown') . "\n";
echo "  NOW(): {$pgNow[0]->now}\n\n";

// PHP timezone
echo "PHP:\n";
echo "  Timezone: " . date_default_timezone_get() . "\n";
echo "  Current time: " . date('Y-m-d H:i:s') . "\n";

echo "\n";

// Step 6: Check update log
echo "6. Update Log Status:\n";
echo str_repeat("-", 80) . "\n";

$updateLogs = DB::connection('mysql')
    ->table('alert_pg_update_log')
    ->where('alert_id', $alertId)
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get();

if ($updateLogs->isEmpty()) {
    echo "No update log entries found for this alert\n";
} else {
    echo "Recent update log entries:\n";
    foreach ($updateLogs as $log) {
        echo "  ID: {$log->id}\n";
        echo "    Status: {$log->status} (1=pending, 2=completed, 3=failed)\n";
        echo "    Created: {$log->created_at}\n";
        echo "    Updated: {$log->updated_at}\n";
        if ($log->error_message) {
            echo "    Error: {$log->error_message}\n";
        }
        echo "\n";
    }
}

echo "\n";

// Step 7: Recommendations
echo "7. Recommendations:\n";
echo str_repeat("-", 80) . "\n";

if (isset($mismatches) && !empty($mismatches)) {
    echo "ISSUE DETECTED: Timestamps are not matching between MySQL and PostgreSQL.\n\n";
    
    echo "Possible causes:\n";
    echo "  1. Timezone conversion during sync\n";
    echo "  2. PDO driver converting timestamps\n";
    echo "  3. Laravel's datetime casting\n";
    echo "  4. Database session timezone mismatch\n\n";
    
    echo "Recommended actions:\n";
    echo "  1. Re-sync this specific alert using the fix script\n";
    echo "  2. Check if other alerts have similar issues\n";
    echo "  3. Verify timezone configuration in .env file\n";
    echo "  4. Review sync service logs for this alert\n";
} else {
    echo "No issues detected. Timestamps match correctly.\n";
}

echo "\n=== END OF DIAGNOSTIC ===\n";

/**
 * Calculate time difference between two timestamps
 */
function calculateTimeDiff(?string $time1, ?string $time2): string
{
    if ($time1 === null || $time2 === null) {
        return 'N/A (one or both values are NULL)';
    }
    
    try {
        $dt1 = new DateTime($time1);
        $dt2 = new DateTime($time2);
        $diff = $dt1->diff($dt2);
        
        $parts = [];
        if ($diff->d > 0) $parts[] = "{$diff->d} days";
        if ($diff->h > 0) $parts[] = "{$diff->h} hours";
        if ($diff->i > 0) $parts[] = "{$diff->i} minutes";
        if ($diff->s > 0) $parts[] = "{$diff->s} seconds";
        
        if (empty($parts)) {
            return 'No difference';
        }
        
        return implode(', ', $parts);
    } catch (Exception $e) {
        return "Error calculating diff: " . $e->getMessage();
    }
}
