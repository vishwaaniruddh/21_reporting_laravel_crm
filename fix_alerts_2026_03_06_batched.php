<?php

/**
 * Fix Wrong Dates in alerts_2026_03_06 Table (Batched)
 * 
 * This script fixes alerts in batches to avoid memory issues.
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== FIX ALERTS_2026_03_06 TABLE (BATCHED) ===\n\n";

$tableName = 'alerts_2026_03_06';
$expectedDate = '2026-03-06';
$batchSize = 10000;

// Step 1: Count alerts with wrong dates
echo "Step 1: Counting alerts with wrong dates...\n";

$totalWrongCount = DB::connection('pgsql')
    ->selectOne("
        SELECT COUNT(*) as count
        FROM {$tableName}
        WHERE DATE(receivedtime) != ?
    ", [$expectedDate]);

$totalWrong = $totalWrongCount->count;

if ($totalWrong == 0) {
    echo "✓ No alerts with wrong dates found!\n";
    echo "\n=== COMPLETE ===\n";
    exit(0);
}

echo "Found {$totalWrong} alerts with wrong dates\n\n";

// Step 2: Process in batches
echo "Step 2: Processing in batches of {$batchSize}...\n\n";

$totalFixed = 0;
$totalNotFound = 0;
$totalErrors = 0;
$batchNumber = 0;

while (true) {
    $batchNumber++;
    
    // Get next batch of alerts with wrong dates
    $wrongDateAlerts = DB::connection('pgsql')
        ->select("
            SELECT id, panelid, createtime, receivedtime, closedtime, status, \"closedBy\"
            FROM {$tableName}
            WHERE DATE(receivedtime) != ?
            ORDER BY id
            LIMIT {$batchSize}
        ", [$expectedDate]);
    
    if (empty($wrongDateAlerts)) {
        echo "\n✓ No more alerts to fix\n";
        break;
    }
    
    echo "Batch {$batchNumber}: Processing " . count($wrongDateAlerts) . " alerts...\n";
    
    // Get alert IDs
    $alertIds = array_map(fn($a) => $a->id, $wrongDateAlerts);
    
    // Fetch correct data from MySQL
    $mysqlAlerts = DB::connection('mysql')
        ->table('alerts')
        ->whereIn('id', $alertIds)
        ->select('id', 'createtime', 'receivedtime', 'closedtime', 'status', 'closedBy')
        ->get()
        ->keyBy('id');
    
    // Fix each alert in this batch
    $batchFixed = 0;
    $batchNotFound = 0;
    $batchErrors = 0;
    
    foreach ($wrongDateAlerts as $pgAlert) {
        if (!isset($mysqlAlerts[$pgAlert->id])) {
            $batchNotFound++;
            $totalNotFound++;
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
                $batchFixed++;
                $totalFixed++;
            }
            
        } catch (Exception $e) {
            $batchErrors++;
            $totalErrors++;
            echo "  ✗ ERROR fixing alert {$pgAlert->id}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "  Fixed: {$batchFixed}, Not found: {$batchNotFound}, Errors: {$batchErrors}\n";
    echo "  Total progress: {$totalFixed} / {$totalWrong}\n\n";
    
    // Safety check - don't run forever
    if ($batchNumber > 100) {
        echo "⚠ Reached maximum batch limit (100). Stopping.\n";
        break;
    }
    
    // Small delay to avoid overwhelming the database
    usleep(100000); // 0.1 second
}

// Step 3: Verify the fix
echo "\nStep 3: Verifying the fix...\n";

$remainingWrongDates = DB::connection('pgsql')
    ->selectOne("
        SELECT COUNT(*) as count
        FROM {$tableName}
        WHERE DATE(receivedtime) != ?
    ", [$expectedDate]);

echo "  Remaining alerts with wrong dates: {$remainingWrongDates->count}\n\n";

// Summary
echo "=== SUMMARY ===\n";
echo "Total alerts with wrong dates (initial): {$totalWrong}\n";
echo "Total fixed: {$totalFixed}\n";
echo "Total not found in MySQL: {$totalNotFound}\n";
echo "Total errors: {$totalErrors}\n";
echo "Remaining wrong dates: {$remainingWrongDates->count}\n\n";

if ($totalFixed > 0) {
    echo "✓✓✓ Successfully fixed {$totalFixed} alerts! ✓✓✓\n\n";
}

if ($remainingWrongDates->count > 0) {
    echo "⚠ Warning: {$remainingWrongDates->count} alerts still have wrong dates\n";
    
    if ($totalNotFound > 0) {
        echo "   {$totalNotFound} alerts were not found in MySQL (may have been deleted)\n";
    }
    
    if ($totalErrors > 0) {
        echo "   {$totalErrors} alerts had errors during fixing\n";
    }
    
    echo "\n   Run this script again to retry, or check manually\n\n";
}

// Show verification query
echo "Verification query:\n";
echo "  SELECT COUNT(*) FROM {$tableName} WHERE DATE(receivedtime) != '{$expectedDate}';\n\n";

// Show some examples of fixed alerts
echo "Sample of alerts (should all be {$expectedDate}):\n";
$sampleAlerts = DB::connection('pgsql')
    ->table($tableName)
    ->select('id', 'receivedtime')
    ->orderBy('id')
    ->limit(5)
    ->get();

foreach ($sampleAlerts as $alert) {
    $dateMatch = (substr($alert->receivedtime, 0, 10) === $expectedDate) ? '✓' : '✗';
    echo "  {$dateMatch} Alert {$alert->id}: receivedtime = {$alert->receivedtime}\n";
}

echo "\n=== COMPLETE ===\n";
