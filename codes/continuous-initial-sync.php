<?php
/**
 * Continuous Initial Sync Worker
 * Runs sync:partitioned command in a loop with 20-minute intervals
 */

$projectPath = __DIR__;
$pollInterval = 20 * 60; // 20 minutes in seconds

echo "Starting Continuous Initial Sync Worker\n";
echo "Poll Interval: {$pollInterval} seconds (20 minutes)\n";
echo "Project Path: {$projectPath}\n";
echo str_repeat('-', 50) . "\n\n";

while (true) {
    $startTime = microtime(true);
    echo "[" . date('Y-m-d H:i:s') . "] Running sync:partitioned...\n";
    
    // Run the artisan command
    $command = "php artisan sync:partitioned --continuous";
    $output = [];
    $returnCode = 0;
    
    exec($command . " 2>&1", $output, $returnCode);
    
    // Display output
    foreach ($output as $line) {
        echo $line . "\n";
    }
    
    $duration = round(microtime(true) - $startTime, 2);
    echo "\n[" . date('Y-m-d H:i:s') . "] Sync completed in {$duration}s\n";
    echo "Return code: {$returnCode}\n";
    echo "Next sync in {$pollInterval} seconds...\n";
    echo str_repeat('-', 50) . "\n\n";
    
    // Sleep for the poll interval
    sleep($pollInterval);
}
