<?php

require_once 'vendor/autoload.php';

// Load Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\BackAlertUpdateLog;

echo "=== BackAlert Update Log Retry Counts ===\n";

try {
    $pendingLogs = BackAlertUpdateLog::pending()->get();
    
    echo "Pending logs and their retry counts:\n";
    foreach ($pendingLogs as $log) {
        echo "ID: {$log->id}, BackAlert: {$log->backalert_id}, Retry Count: {$log->retry_count}, Can Retry: " . ($log->canRetry(3) ? 'Yes' : 'No') . "\n";
    }
    
    echo "\nLogs within retry limit (< 3):\n";
    $retryableLogs = BackAlertUpdateLog::pending()->withinRetryLimit(3)->get();
    echo "Count: " . $retryableLogs->count() . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}