<?php

/**
 * Continuous Sync Script
 * 
 * Runs the partitioned sync continuously until all records are synced.
 * Processes batches back-to-back without waiting.
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Continuous Partitioned Sync ===\n\n";
echo "This will sync ALL remaining records without waiting.\n";
echo "Press Ctrl+C to stop at any time.\n\n";

// Check initial state
$unsyncedCount = DB::connection('mysql')
    ->table('alerts')
    ->whereNull('synced_at')
    ->count();

echo "Initial unsynced records: " . number_format($unsyncedCount) . "\n\n";

if ($unsyncedCount === 0) {
    echo "✅ All records are already synced!\n";
    exit(0);
}

$totalSynced = 0;
$cycleNumber = 0;
$startTime = microtime(true);

while (true) {
    $cycleNumber++;
    echo "--- Cycle {$cycleNumber} ---\n";
    
    // Check if there are records to sync
    $remainingCount = DB::connection('mysql')
        ->table('alerts')
        ->whereNull('synced_at')
        ->count();
    
    if ($remainingCount === 0) {
        echo "\n✅ All records synced!\n";
        break;
    }
    
    echo "Remaining: " . number_format($remainingCount) . " records\n";
    
    // Run sync command (10 batches = ~100,000 records)
    $output = [];
    $returnCode = 0;
    exec('php artisan sync:partitioned --max-batches=10 2>&1', $output, $returnCode);
    
    // Parse output to get synced count
    $syncedThisCycle = 0;
    foreach ($output as $line) {
        if (preg_match('/Total Records Synced\s+\|\s+([\d,]+)/', $line, $matches)) {
            $syncedThisCycle = (int)str_replace(',', '', $matches[1]);
            break;
        }
    }
    
    $totalSynced += $syncedThisCycle;
    
    if ($syncedThisCycle > 0) {
        echo "Synced: " . number_format($syncedThisCycle) . " records\n";
        echo "Total synced so far: " . number_format($totalSynced) . " records\n";
    } else {
        echo "⚠️ No records synced in this cycle\n";
        
        // Check if there was an error
        if ($returnCode !== 0) {
            echo "❌ Error occurred. Output:\n";
            echo implode("\n", $output) . "\n";
            echo "\nWaiting 10 seconds before retry...\n";
            sleep(10);
        } else {
            // No error but no records synced - might be done
            break;
        }
    }
    
    // Show progress
    $elapsed = microtime(true) - $startTime;
    $rate = $totalSynced / $elapsed;
    echo "Speed: " . number_format($rate, 0) . " records/second\n";
    
    // Brief pause to avoid overwhelming the system
    echo "Waiting 2 seconds before next cycle...\n\n";
    sleep(2);
}

$totalTime = microtime(true) - $startTime;
$avgRate = $totalSynced / $totalTime;

echo "\n=== Sync Complete ===\n";
echo "Total cycles: {$cycleNumber}\n";
echo "Total records synced: " . number_format($totalSynced) . "\n";
echo "Total time: " . number_format($totalTime, 2) . " seconds\n";
echo "Average speed: " . number_format($avgRate, 0) . " records/second\n";

// Show final state
echo "\n=== Final State ===\n";
$tables = DB::connection('pgsql')->select("
    SELECT tablename 
    FROM pg_tables 
    WHERE schemaname = 'public' 
    AND tablename LIKE 'alerts_%' 
    ORDER BY tablename
");

foreach ($tables as $table) {
    if (strpos($table->tablename, 'alerts_2026') === 0) {
        $count = DB::connection('pgsql')->table($table->tablename)->count();
        echo "{$table->tablename}: " . number_format($count) . " records\n";
    }
}
