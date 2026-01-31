<?php
/**
 * Fast Backup Sync - Optimized for 9.3M records
 * 
 * This script runs the backup sync with optimal settings:
 * - Large batch size (10,000 records)
 * - No deletion during sync (for speed)
 * - Increased memory limit
 * - Progress monitoring
 */

echo "=== FAST BACKUP SYNC (Optimized) ===\n";
echo "Target: 9.3M records in ~10 minutes\n";
echo "Batch size: 10,000 records\n";
echo "Strategy: Sync first, delete later\n\n";

// Set high memory limit
ini_set('memory_limit', '2G');

$startTime = time();

echo "Starting optimized sync...\n";
echo "Command: php artisan sync:backup-data --batch-size=10000 --continuous --force\n\n";

// Execute the optimized command
$command = 'php artisan sync:backup-data --batch-size=10000 --continuous --force';
$descriptorspec = array(
    0 => array("pipe", "r"),  // stdin
    1 => array("pipe", "w"),  // stdout
    2 => array("pipe", "w")   // stderr
);

$process = proc_open($command, $descriptorspec, $pipes);

if (is_resource($process)) {
    // Close stdin
    fclose($pipes[0]);
    
    // Read output in real-time
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    
    $lastProgressTime = time();
    
    while (true) {
        $stdout = fgets($pipes[1]);
        $stderr = fgets($pipes[2]);
        
        if ($stdout !== false) {
            echo $stdout;
            
            // Track progress
            if (strpos($stdout, 'Progress:') !== false) {
                $currentTime = time();
                $elapsed = $currentTime - $startTime;
                echo "  [Elapsed: " . gmdate('H:i:s', $elapsed) . "]\n";
            }
        }
        
        if ($stderr !== false) {
            echo "ERROR: " . $stderr;
        }
        
        // Check if process is still running
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        
        usleep(100000); // 0.1 second delay
    }
    
    // Read any remaining output
    echo stream_get_contents($pipes[1]);
    echo stream_get_contents($pipes[2]);
    
    fclose($pipes[1]);
    fclose($pipes[2]);
    
    $returnCode = proc_close($process);
    
    $totalTime = time() - $startTime;
    
    echo "\n=== SYNC COMPLETED ===\n";
    echo "Total time: " . gmdate('H:i:s', $totalTime) . "\n";
    echo "Return code: {$returnCode}\n";
    
    if ($returnCode === 0) {
        echo "✅ Sync successful!\n\n";
        
        echo "Next step: Clean up source table\n";
        echo "Run: TRUNCATE TABLE alerts_all_data;\n";
    } else {
        echo "❌ Sync failed with return code: {$returnCode}\n";
    }
    
} else {
    echo "❌ Failed to start sync process\n";
}