<?php

/**
 * Fix Wrong Partition Dates
 * 
 * This script finds alerts in partition tables where the receivedtime date
 * doesn't match the partition date (e.g., alerts in alerts_2026_03_06 but
 * with receivedtime showing 2026-03-07), and fixes them by fetching the
 * correct timestamps from MySQL.
 * 
 * Example issue:
 * - Partition: alerts_2026_03_06
 * - PostgreSQL receivedtime: 2026-03-07 18:01:44 (WRONG DATE)
 * - MySQL receivedtime: 2026-03-06 03:31:44 (CORRECT)
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== FIX WRONG PARTITION DATES ===\n\n";

// Configuration
$dryRun = false;
$batchSize = 100;

if ($argc > 1 && $argv[1] === '--dry-run') {
    $dryRun = true;
    echo "DRY RUN MODE: Will only report issues, not fix them\n\n";
}

// Step 1: Get all partition tables
echo "Step 1: Finding partition tables...\n";

$partitionTables = DB::connection('pgsql')
    ->select("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
        AND table_name LIKE 'alerts_%'
        AND table_name ~ '^alerts_[0-9]{4}_[0-9]{2}_[0-9]{2}$'
        ORDER BY table_name DESC
    ");

echo "  Found " . count($partitionTables) . " partition tables\n\n";

$totalChecked = 0;
$totalWrongDates = 0;
$totalFixed = 0;
$totalErrors = 0;

// Step 2: Check each partition table
foreach ($partitionTables as $table) {
    $tableName = $table->table_name;
    
    // Extract expected date from table name (alerts_2026_03_06 -> 2026-03-06)
    if (!preg_match('/^alerts_(\d{4})_(\d{2})_(\d{2})$/', $tableName, $matches)) {
        echo "Skipping invalid table name: {$tableName}\n";
        continue;
    }
    
    $expectedDate = "{$matches[1]}-{$matches[2]}-{$matches[3]}";
    
    echo "Checking table: {$tableName} (expected date: {$expectedDate})\n";
    
    // Find alerts where the receivedtime date doesn't match the partition date
    $wrongDateAlerts = DB::connection('pgsql')
        ->select("
            SELECT id, panelid, createtime, receivedtime, closedtime, status
            FROM {$tableName}
            WHERE DATE(receivedtime) != ?
            LIMIT {$batchSize}
        ", [$expectedDate]);
    
    if (empty($wrongDateAlerts)) {
        echo "  ✓ All alerts have correct dates\n\n";
        continue;
    }
    
    echo "  ✗ Found " . count($wrongDateAlerts) . " alerts with wrong dates\n";
    $totalWrongDates += count($wrongDateAlerts);
    
    // Show examples
    echo "  Examples:\n";
    foreach (array_slice($wrongDateAlerts, 0, 3) as $alert) {
        echo "    Alert {$alert->id}: receivedtime = {$alert->receivedtime} (should be {$expectedDate})\n";
    }
    echo "\n";
    
    if ($dryRun) {
        echo "  DRY RUN: Skipping fixes\n\n";
        continue;
    }
    
    // Fix each alert
    echo "  Fixing alerts...\n";
    
    $alertIds = array_map(fn($a) => $a->id, $wrongDateAlerts);
    
    // Fetch correct data from MySQL
    $mysqlAlerts = DB::connection('mysql')
        ->table('alerts')
        ->whereIn('id', $alertIds)
        ->select('id', 'createtime', 'receivedtime', 'closedtime', 'status', 'closedBy')
        ->get()
        ->keyBy('id');
    
    $fixedCount = 0;
    $notFoundCount = 0;
    
    foreach ($wrongDateAlerts as $pgAlert) {
        $totalChecked++;
        
        if (!isset($mysqlAlerts[$pgAlert->id])) {
            $notFoundCount++;
            echo "    ⚠ Alert {$pgAlert->id} not found in MySQL\n";
            continue;
        }
        
        $mysqlAlert = $mysqlAlerts[$pgAlert->id];
        
        try {
            // Update with correct timestamps from MySQL
            $sql = "
                UPDATE {$tableName}
                SET 
                    createtime = ?::timestamp,
                    receivedtime = ?::timestamp,
                    closedtime = " . ($mysqlAlert->closedtime ? "?::timestamp" : "NULL") . ",
                    status = ?,
                    \"closedBy\" = ?,
                    synced_at = NOW()
                WHERE id = ?
            ";
            
            $bindings = [
                $mysqlAlert->createtime,
                $mysqlAlert->receivedtime
            ];
            
            if ($mysqlAlert->closedtime) {
                $bindings[] = $mysqlAlert->closedtime;
            }
            
            $bindings[] = $mysqlAlert->status;
            $bindings[] = $mysqlAlert->closedBy;
            $bindings[] = $pgAlert->id;
            
            $affected = DB::connection('pgsql')->update($sql, $bindings);
            
            if ($affected > 0) {
                $fixedCount++;
                $totalFixed++;
                
                // Verify the fix
                $verifyDate = DB::connection('pgsql')
                    ->selectOne("SELECT DATE(receivedtime) as date FROM {$tableName} WHERE id = ?", [$pgAlert->id]);
                
                if ($verifyDate->date === $expectedDate) {
                    echo "    ✓ Fixed alert {$pgAlert->id}: {$pgAlert->receivedtime} -> {$mysqlAlert->receivedtime}\n";
                } else {
                    echo "    ⚠ Alert {$pgAlert->id} still has wrong date after fix: {$verifyDate->date}\n";
                }
            }
            
        } catch (Exception $e) {
            $totalErrors++;
            echo "    ✗ ERROR fixing alert {$pgAlert->id}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "  Fixed {$fixedCount} alerts";
    if ($notFoundCount > 0) {
        echo " ({$notFoundCount} not found in MySQL)";
    }
    echo "\n\n";
}

// Summary
echo "=== SUMMARY ===\n";
echo "Total alerts checked: {$totalChecked}\n";
echo "Total with wrong dates: {$totalWrongDates}\n";

if (!$dryRun) {
    echo "Total fixed: {$totalFixed}\n";
    echo "Total errors: {$totalErrors}\n";
    
    if ($totalFixed > 0) {
        echo "\n✓✓✓ Successfully fixed {$totalFixed} alerts with wrong partition dates! ✓✓✓\n";
    }
    
    if ($totalWrongDates > $totalFixed) {
        $remaining = $totalWrongDates - $totalFixed;
        echo "\n⚠ Warning: {$remaining} alerts still need fixing\n";
        echo "   (They may not exist in MySQL or encountered errors)\n";
    }
    
    if ($totalErrors > 0) {
        echo "\n✗ {$totalErrors} errors occurred during fixing\n";
    }
} else {
    echo "\nDRY RUN: No changes were made\n";
    echo "Run without --dry-run to fix the issues:\n";
    echo "  php fix_wrong_partition_dates.php\n";
}

echo "\n=== COMPLETE ===\n";

// Additional recommendations
if ($totalWrongDates > 0) {
    echo "\nRECOMMENDATIONS:\n";
    echo "1. After fixing, verify with:\n";
    echo "   SELECT COUNT(*) FROM alerts_2026_03_06 WHERE DATE(receivedtime) != '2026-03-06';\n";
    echo "\n";
    echo "2. Check if alerts need to be moved to correct partitions:\n";
    echo "   - If receivedtime is 2026-03-07, alert should be in alerts_2026_03_07\n";
    echo "   - Current script fixes timestamps in place\n";
    echo "\n";
    echo "3. Restart sync services to prevent new wrong dates:\n";
    echo "   .\\codes\\restart-services-for-timestamp-fix.ps1\n";
}
