<?php

/**
 * Initial Sync Worker - Continuous MySQL to PostgreSQL Sync
 * 
 * This script runs continuously and syncs alerts from MySQL to PostgreSQL
 * partitioned tables. It's designed to run as a Windows service.
 */

// Set up environment
$projectPath = __DIR__;
require_once $projectPath . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once $projectPath . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Alert Initial Sync Worker Started ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "Process ID: " . getmypid() . "\n";
echo "Working Directory: " . getcwd() . "\n\n";

$cycleCount = 0;
$lastSyncTime = 0;
$syncInterval = 300; // 5 minutes between sync cycles

while (true) {
    $cycleCount++;
    $currentTime = time();
    
    try {
        echo "[" . date('Y-m-d H:i:s') . "] Cycle #{$cycleCount} - Checking for unsynced records...\n";
        
        // Check if it's time to sync (every 5 minutes)
        if ($currentTime - $lastSyncTime >= $syncInterval) {
            echo "Starting sync process...\n";
            
            // Run the sync command
            $exitCode = \Illuminate\Support\Facades\Artisan::call('sync:partitioned', [
                '--max-batches' => 5, // Process up to 5 batches per cycle
            ]);
            
            $output = \Illuminate\Support\Facades\Artisan::output();
            
            if ($exitCode === 0) {
                echo "Sync completed successfully\n";
                if (trim($output)) {
                    echo "Output: " . trim($output) . "\n";
                }
            } else {
                echo "Sync failed with exit code: {$exitCode}\n";
                echo "Output: " . trim($output) . "\n";
            }
            
            $lastSyncTime = $currentTime;
        } else {
            $nextSync = $syncInterval - ($currentTime - $lastSyncTime);
            echo "Next sync in {$nextSync} seconds\n";
        }
        
        // Sleep for 30 seconds before next check
        echo "Sleeping for 30 seconds...\n\n";
        sleep(30);
        
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        echo "Trace: " . $e->getTraceAsString() . "\n";
        echo "Continuing after error...\n\n";
        
        // Sleep longer after an error
        sleep(60);
    }
    
    // Garbage collection every 10 cycles
    if ($cycleCount % 10 == 0) {
        gc_collect_cycles();
        echo "Memory usage: " . memory_get_usage(true) / 1024 / 1024 . " MB\n";
    }
}