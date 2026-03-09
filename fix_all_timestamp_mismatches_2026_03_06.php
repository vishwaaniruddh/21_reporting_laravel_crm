<?php

/**
 * Fix ALL Timestamp Mismatches in alerts_2026_03_06
 * 
 * This script compares EVERY alert's timestamps between MySQL and PostgreSQL
 * and fixes any mismatches (not just date mismatches, but time mismatches too).
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== FIX ALL TIMESTAMP MISMATCHES IN alerts_2026_03_06 ===\n\n";

$tableName = 'alerts_2026_03_06';
$batchSize = 500;
$maxBatches = 10000;

// Get total count
$totalCount = DB::connection('pgsql')
    ->selectOne("SELECT COUNT(*) as count FROM {$tableName}");

echo "Total alerts in table: {$totalCount->count}\n";
echo "Batch size: {$batchSize}\n";
echo "Estimated batches: " . ceil($totalCount->count / $batchSize) . "\n\n";

$startTime = time();
$totalChecked = 0;
$totalMismatches = 0;
$totalFixed = 0;
$totalErrors = 0;
$lastId = 0;

for ($batchNum = 1; $batchNum <= $maxBatches; $batchNum++) {
    // Get next batch of alerts from PostgreSQL
    $pgAlerts = DB::connection('pgsql')
        ->table($tableName)
        ->where('id', '>', $lastId)
        ->orderBy('id')
        ->limit($batchSize)
        ->get(['id', 'createtime', 'receivedtime', 'closedtime', 'status', 'closedBy']);
    
    if ($pgAlerts->isEmpty()) {
        echo "\n✓ Reached end of table\n";
        break;
    }
    
    $alertIds = $pgAlerts->pluck('id')->toArray();
    $lastId = end($alertIds);
    
    // Fetch corresponding MySQL data
    $mysqlAlerts = DB::connection('mysql')
        ->table('alerts')
        ->whereIn('id', $alertIds)
        ->get(['id', 'createtime', 'receivedtime', 'closedtime', 'status', 'closedBy'])
        ->keyBy('id');
    
    // Compare and fix mismatches
    $batchMismatches = 0;
    $batchFixed = 0;
    
    foreach ($pgAlerts as $pgAlert) {
        $totalChecked++;
        
        if (!isset($mysqlAlerts[$pgAlert->id])) {
            continue; // Alert not in MySQL
        }
        
        $mysqlAlert = $mysqlAlerts[$pgAlert->id];
        
        // Check for ANY timestamp mismatch
        $hasMismatch = false;
        
        if ($pgAlert->createtime !== $mysqlAlert->createtime) {
            $hasMismatch = true;
        }
        
        if ($pgAlert->receivedtime !== $mysqlAlert->receivedtime) {
            $hasMismatch = true;
        }
        
        if ($pgAlert->closedtime !== $mysqlAlert->closedtime) {
            $hasMismatch = true;
        }
        
        if (!$hasMismatch) {
            continue; // All timestamps match
        }
        
        $batchMismatches++;
        $totalMismatches++;
        
        // Fix the mismatch
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
            
            DB::connection('pgsql')->update($sql, $bindings);
            $batchFixed++;
            $totalFixed++;
            
        } catch (Exception $e) {
            $totalErrors++;
        }
    }
    
    // Progress update every 10 batches
    if ($batchNum % 10 === 0) {
        $elapsed = time() - $startTime;
        $rate = $totalChecked / max($elapsed, 1);
        $remaining = $totalCount->count - $totalChecked;
        $eta = $remaining / max($rate, 1);
        
        echo sprintf(
            "Batch %d: Checked %d/%d (%.1f%%) | Mismatches: %d | Fixed: %d | Rate: %.0f/sec | ETA: %s\n",
            $batchNum,
            $totalChecked,
            $totalCount->count,
            ($totalChecked / $totalCount->count) * 100,
            $totalMismatches,
            $totalFixed,
            $rate,
            gmdate("H:i:s", $eta)
        );
    }
}

$elapsed = time() - $startTime;

echo "\n=== SUMMARY ===\n";
echo "Total alerts checked: {$totalChecked}\n";
echo "Total mismatches found: {$totalMismatches}\n";
echo "Total fixed: {$totalFixed}\n";
echo "Total errors: {$totalErrors}\n";
echo "Mismatch rate: " . round(($totalMismatches / max($totalChecked, 1)) * 100, 2) . "%\n";
echo "Time elapsed: " . gmdate("H:i:s", $elapsed) . "\n";
echo "Average rate: " . round($totalChecked / max($elapsed, 1), 2) . " alerts/sec\n\n";

if ($totalFixed > 0) {
    echo "✓✓✓ Successfully fixed {$totalFixed} timestamp mismatches! ✓✓✓\n";
}

// Verify specific alert
echo "\nVerifying alert 1001407628:\n";
$verifyAlert = DB::connection('pgsql')
    ->table($tableName)
    ->where('id', 1001407628)
    ->first();

$verifyMysql = DB::connection('mysql')
    ->table('alerts')
    ->where('id', 1001407628)
    ->first();

if ($verifyAlert && $verifyMysql) {
    echo "  PG createtime: {$verifyAlert->createtime}\n";
    echo "  MySQL createtime: {$verifyMysql->createtime}\n";
    echo "  Match: " . ($verifyAlert->createtime === $verifyMysql->createtime ? 'YES ✓' : 'NO ✗') . "\n";
}

echo "\n=== COMPLETE ===\n";
