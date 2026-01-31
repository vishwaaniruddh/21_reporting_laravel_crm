<?php

require_once 'vendor/autoload.php';

// Load Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\BackAlertUpdateLog;

echo "=== Resetting Failed BackAlert Update Logs ===\n";

try {
    // Get the most recent failed logs (the ones that failed due to SQL syntax error)
    $recentFailedLogs = BackAlertUpdateLog::failed()
        ->where('created_at', '>=', '2026-01-27 22:58:00') // Only recent ones with SQL syntax error
        ->get();
    
    echo "Found {$recentFailedLogs->count()} recent failed logs to reset\n";
    
    foreach ($recentFailedLogs as $log) {
        echo "Resetting log ID {$log->id} (BackAlert ID: {$log->backalert_id})\n";
        $log->resetForRetry();
    }
    
    echo "\n=== Reset Complete ===\n";
    echo "These logs will now be retried by the BackAlertUpdateSync service\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}