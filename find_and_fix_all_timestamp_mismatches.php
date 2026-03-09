<?php

/**
 * Find and Fix All Timestamp Mismatches
 * 
 * This script will:
 * 1. Scan all partition tables for timestamp mismatches
 * 2. Fix them using explicit timestamp casting
 * 3. Report on the results
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== FINDING AND FIXING TIMESTAMP MISMATCHES ===\n\n";

// Configuration
$batchSize = 100;
$maxAlertsToCheck = 1000; // Limit for safety
$dryRun = false; // Set to true to only report, not fix

if ($argc > 1 && $argv[1] === '--dry-run') {
    $dryRun = true;
    echo "DRY RUN MODE: Will only report mismatches, not fix them\n\n";
}

// Step 1: Get all partition tables
echo "Step 1: Finding partition tables...\n";

$partitionTables = DB::connection('pgsql')
    ->select("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
        AND table_name LIKE 'alerts_%'
        ORDER BY table_name DESC
        LIMIT 10
    ");

echo "  Found " . count($partitionTables) . " partition tables\n\n";

$totalChecked = 0;
$totalMismatches = 0;
$totalFixed = 0;
$totalErrors = 0;

// Step 2: Check each partition table
foreach ($partitionTables as $table) {
    $tableName = $table->table_name;
    echo "Checking table: {$tableName}\n";
    
    // Get sample of alerts from this partition
    $pgAlerts = DB::connection('pgsql')
        ->table($tableName)
        ->select('id', 'createtime', 'receivedtime', 'closedtime')
        ->limit($batchSize)
        ->get();
    
    if ($pgAlerts->isEmpty()) {
        echo "  No alerts in this partition\n\n";
        continue;
    }
    
    $alertIds = $pgAlerts->pluck('id')->toArray();
    
    // Fetch corresponding MySQL data
    $mysqlAlerts = DB::connection('mysql')
        ->table('alerts')
        ->whereIn('id', $alertIds)
        ->select('id', 'createtime', 'receivedtime', 'closedtime')
        ->get()
        ->keyBy('id');
    
    // Compare
    $mismatches = [];
    foreach ($pgAlerts as $pgAlert) {
        $totalChecked++;
        
        if (!isset($mysqlAlerts[$pgAlert->id])) {
            continue; // Alert not in MySQL (maybe deleted)
        }
        
        $mysqlAlert = $mysqlAlerts[$pgAlert->id];
        
        // Check for mismatches
        $hasMismatch = false;
        $mismatchDetails = [];
        
        if ($mysqlAlert->createtime !== $pgAlert->createtime) {
            $hasMismatch = true;
            $mismatchDetails[] = "createtime";
        }
        
        if ($mysqlAlert->receivedtime !== $pgAlert->receivedtime) {
            $hasMismatch = true;
            $mismatchDetails[] = "receivedtime";
        }
        
        if ($mysqlAlert->closedtime !== $pgAlert->closedtime) {
            $hasMismatch = true;
            $mismatchDetails[] = "closedtime";
        }
        
        if ($hasMismatch) {
            $totalMismatches++;
            $mismatches[] = [
                'id' => $pgAlert->id,
                'columns' => $mismatchDetails,
                'mysql' => $mysqlAlert,
                'pg' => $pgAlert
            ];
        }
        
        if ($totalChecked >= $maxAlertsToCheck) {
            echo "  Reached maximum check limit ({$maxAlertsToCheck})\n";
            break 2;
        }
    }
    
    // Report mismatches for this table
    if (!empty($mismatches)) {
        echo "  Found " . count($mismatches) . " mismatches\n";
        
        if (!$dryRun) {
            echo "  Fixing mismatches...\n";
            
            foreach ($mismatches as $mismatch) {
                try {
                    $mysql = $mismatch['mysql'];
                    
                    $sql = "
                        UPDATE {$tableName}
                        SET 
                            createtime = ?::timestamp,
                            receivedtime = ?::timestamp,
                            closedtime = " . ($mysql->closedtime ? "?::timestamp" : "NULL") . ",
                            synced_at = NOW()
                        WHERE id = ?
                    ";
                    
                    $bindings = [
                        $mysql->createtime,
                        $mysql->receivedtime
                    ];
                    
                    if ($mysql->closedtime) {
                        $bindings[] = $mysql->closedtime;
                    }
                    
                    $bindings[] = $mismatch['id'];
                    
                    DB::connection('pgsql')->update($sql, $bindings);
                    $totalFixed++;
                    
                } catch (Exception $e) {
                    $totalErrors++;
                    echo "    ERROR fixing alert {$mismatch['id']}: " . $e->getMessage() . "\n";
                }
            }
            
            echo "  Fixed " . count($mismatches) . " alerts\n";
        } else {
            // Dry run - just show first few examples
            echo "  Examples:\n";
            foreach (array_slice($mismatches, 0, 3) as $mismatch) {
                echo "    Alert {$mismatch['id']}: " . implode(', ', $mismatch['columns']) . "\n";
            }
        }
    } else {
        echo "  No mismatches found\n";
    }
    
    echo "\n";
}

// Summary
echo "=== SUMMARY ===\n";
echo "Total alerts checked: {$totalChecked}\n";
echo "Total mismatches found: {$totalMismatches}\n";

if (!$dryRun) {
    echo "Total fixed: {$totalFixed}\n";
    echo "Total errors: {$totalErrors}\n";
    
    if ($totalFixed > 0) {
        echo "\n✓ Successfully fixed {$totalFixed} timestamp mismatches!\n";
    }
    
    if ($totalMismatches > $totalFixed) {
        echo "\n⚠ Warning: " . ($totalMismatches - $totalFixed) . " mismatches were not fixed\n";
    }
} else {
    echo "\nDRY RUN: No changes were made\n";
    echo "Run without --dry-run to fix the mismatches\n";
}

echo "\n=== COMPLETE ===\n";
