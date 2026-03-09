<?php

/**
 * Fix Wrong Dates in alerts_2026_03_06 Table Only
 * 
 * This script finds and fixes alerts in the alerts_2026_03_06 partition
 * where the receivedtime date doesn't match 2026-03-06.
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== FIX ALERTS_2026_03_06 TABLE ===\n\n";

$tableName = 'alerts_2026_03_06';
$expectedDate = '2026-03-06';

// Step 1: Find alerts with wrong dates
echo "Step 1: Finding alerts with wrong dates in {$tableName}...\n";

$wrongDateAlerts = DB::connection('pgsql')
    ->select("
        SELECT id, panelid, createtime, receivedtime, closedtime, status, \"closedBy\"
        FROM {$tableName}
        WHERE DATE(receivedtime) != ?
        ORDER BY id
    ", [$expectedDate]);

if (empty($wrongDateAlerts)) {
    echo "✓ No alerts with wrong dates found!\n";
    echo "\n=== COMPLETE ===\n";
    exit(0);
}

echo "Found " . count($wrongDateAlerts) . " alerts with wrong dates\n\n";

// Show examples
echo "Examples of wrong dates:\n";
foreach (array_slice($wrongDateAlerts, 0, 10) as $alert) {
    echo "  Alert {$alert->id}: receivedtime = {$alert->receivedtime} (should be {$expectedDate})\n";
}
echo "\n";

// Step 2: Get alert IDs
$alertIds = array_map(fn($a) => $a->id, $wrongDateAlerts);

echo "Step 2: Fetching correct data from MySQL for " . count($alertIds) . " alerts...\n";

// Fetch correct data from MySQL in batches
$batchSize = 500;
$batches = array_chunk($alertIds, $batchSize);
$mysqlAlerts = collect();

foreach ($batches as $batchIndex => $batch) {
    echo "  Fetching batch " . ($batchIndex + 1) . " of " . count($batches) . "...\n";
    
    $batchData = DB::connection('mysql')
        ->table('alerts')
        ->whereIn('id', $batch)
        ->select('id', 'createtime', 'receivedtime', 'closedtime', 'status', 'closedBy')
        ->get();
    
    $mysqlAlerts = $mysqlAlerts->merge($batchData);
}

$mysqlAlerts = $mysqlAlerts->keyBy('id');

echo "  Fetched " . $mysqlAlerts->count() . " alerts from MySQL\n\n";

// Step 3: Fix each alert
echo "Step 3: Fixing alerts in PostgreSQL...\n";

$totalFixed = 0;
$totalNotFound = 0;
$totalErrors = 0;
$progressInterval = 10;

DB::connection('pgsql')->beginTransaction();

try {
    foreach ($wrongDateAlerts as $index => $pgAlert) {
        // Progress indicator
        if (($index + 1) % $progressInterval === 0 || $index === 0) {
            echo "  Processing alert " . ($index + 1) . " of " . count($wrongDateAlerts) . "...\r";
        }
        
        if (!isset($mysqlAlerts[$pgAlert->id])) {
            $totalNotFound++;
            continue;
        }
        
        $mysqlAlert = $mysqlAlerts[$pgAlert->id];
        
        try {
            // Update with correct timestamps from MySQL using explicit casting
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
                $totalFixed++;
            }
            
        } catch (Exception $e) {
            $totalErrors++;
            echo "\n  ✗ ERROR fixing alert {$pgAlert->id}: " . $e->getMessage() . "\n";
        }
    }
    
    DB::connection('pgsql')->commit();
    echo "\n  ✓ Transaction committed\n\n";
    
} catch (Exception $e) {
    DB::connection('pgsql')->rollBack();
    echo "\n\n✗ TRANSACTION FAILED: " . $e->getMessage() . "\n";
    echo "All changes have been rolled back.\n\n";
    exit(1);
}

// Step 4: Verify the fix
echo "Step 4: Verifying the fix...\n";

$remainingWrongDates = DB::connection('pgsql')
    ->selectOne("
        SELECT COUNT(*) as count
        FROM {$tableName}
        WHERE DATE(receivedtime) != ?
    ", [$expectedDate]);

echo "  Remaining alerts with wrong dates: {$remainingWrongDates->count}\n\n";

// Summary
echo "=== SUMMARY ===\n";
echo "Total alerts with wrong dates: " . count($wrongDateAlerts) . "\n";
echo "Total fixed: {$totalFixed}\n";
echo "Total not found in MySQL: {$totalNotFound}\n";
echo "Total errors: {$totalErrors}\n";
echo "Remaining wrong dates: {$remainingWrongDates->count}\n\n";

if ($totalFixed > 0) {
    echo "✓✓✓ Successfully fixed {$totalFixed} alerts! ✓✓✓\n\n";
}

if ($remainingWrongDates->count > 0) {
    echo "⚠ Warning: {$remainingWrongDates->count} alerts still have wrong dates\n";
    echo "   Run this script again or check manually\n\n";
}

// Show some examples of fixed alerts
echo "Verification - Sample of fixed alerts:\n";
$sampleFixed = DB::connection('pgsql')
    ->table($tableName)
    ->whereIn('id', array_slice($alertIds, 0, 5))
    ->select('id', 'receivedtime')
    ->get();

foreach ($sampleFixed as $alert) {
    $dateMatch = (substr($alert->receivedtime, 0, 10) === $expectedDate) ? '✓' : '✗';
    echo "  {$dateMatch} Alert {$alert->id}: receivedtime = {$alert->receivedtime}\n";
}

echo "\n=== COMPLETE ===\n";
