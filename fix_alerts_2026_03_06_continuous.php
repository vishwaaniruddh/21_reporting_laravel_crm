<?php

/**
 * Fix Wrong Dates in alerts_2026_03_06 Table (Continuous)
 * 
 * This script runs continuously until all alerts are fixed.
 * Run with: php fix_alerts_2026_03_06_continuous.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== FIX ALERTS_2026_03_06 TABLE (CONTINUOUS) ===\n\n";

$tableName = 'alerts_2026_03_06';
$expectedDate = '2026-03-06';
$batchSize = 500; // Larger batch size for speed
$maxBatches = 10000; // Very high limit

// Initial count
$initialCount = DB::connection('pgsql')
    ->selectOne("SELECT COUNT(*) as count FROM {$tableName} WHERE DATE(receivedtime) != ?", [$expectedDate]);

echo "Initial count of wrong dates: {$initialCount->count}\n";
echo "Batch size: {$batchSize}\n";
echo "Estimated batches needed: " . ceil($initialCount->count / $batchSize) . "\n\n";

$startTime = time();
$totalFixed = 0;
$totalNotFound = 0;
$totalErrors = 0;
$batchNumber = 0;

while ($batchNumber < $maxBatches) {
    $batchNumber++;
    
    // Get next batch
    $wrongDateAlerts = DB::connection('pgsql')
        ->select("
            SELECT id
            FROM {$tableName}
            WHERE DATE(receivedtime) != ?
            ORDER BY id
            LIMIT {$batchSize}
        ", [$expectedDate]);
    
    if (empty($wrongDateAlerts)) {
        echo "\n✓ All alerts fixed!\n";
        break;
    }
    
    $alertIds = array_map(fn($a) => $a->id, $wrongDateAlerts);
    
    // Fetch from MySQL
    $mysqlAlerts = DB::connection('mysql')
        ->table('alerts')
        ->whereIn('id', $alertIds)
        ->select('id', 'createtime', 'receivedtime', 'closedtime', 'status', 'closedBy')
        ->get()
        ->keyBy('id');
    
    // Fix each alert
    $batchFixed = 0;
    foreach ($wrongDateAlerts as $pgAlert) {
        if (!isset($mysqlAlerts[$pgAlert->id])) {
            $totalNotFound++;
            continue;
        }
        
        $mysqlAlert = $mysqlAlerts[$pgAlert->id];
        
        try {
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
            
            $bindings = [$mysqlAlert->createtime, $mysqlAlert->receivedtime];
            if ($mysqlAlert->closedtime) $bindings[] = $mysqlAlert->closedtime;
            $bindings[] = $mysqlAlert->status;
            $bindings[] = $mysqlAlert->closedBy;
            $bindings[] = $pgAlert->id;
            
            DB::connection('pgsql')->update($sql, $bindings);
            $batchFixed++;
            $totalFixed++;
            
        } catch (Exception $e) {
            $totalErrors++;
        }
    }
    
    // Progress update every 10 batches
    if ($batchNumber % 10 === 0) {
        $elapsed = time() - $startTime;
        $rate = $totalFixed / max($elapsed, 1);
        $remaining = $initialCount->count - $totalFixed;
        $eta = $remaining / max($rate, 1);
        
        echo sprintf(
            "Batch %d: Fixed %d/%d (%.1f%%) | Rate: %.0f/sec | ETA: %s\n",
            $batchNumber,
            $totalFixed,
            $initialCount->count,
            ($totalFixed / $initialCount->count) * 100,
            $rate,
            gmdate("H:i:s", $eta)
        );
    }
}

// Final verification
$finalCount = DB::connection('pgsql')
    ->selectOne("SELECT COUNT(*) as count FROM {$tableName} WHERE DATE(receivedtime) != ?", [$expectedDate]);

$elapsed = time() - $startTime;

echo "\n=== SUMMARY ===\n";
echo "Initial wrong dates: {$initialCount->count}\n";
echo "Total fixed: {$totalFixed}\n";
echo "Total not found: {$totalNotFound}\n";
echo "Total errors: {$totalErrors}\n";
echo "Remaining wrong dates: {$finalCount->count}\n";
echo "Time elapsed: " . gmdate("H:i:s", $elapsed) . "\n";
echo "Average rate: " . round($totalFixed / max($elapsed, 1), 2) . " alerts/sec\n\n";

if ($finalCount->count == 0) {
    echo "✓✓✓ ALL ALERTS FIXED SUCCESSFULLY! ✓✓✓\n";
} else {
    echo "⚠ {$finalCount->count} alerts still need fixing\n";
    echo "Run the script again to continue\n";
}

echo "\n=== COMPLETE ===\n";
