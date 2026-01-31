<?php

require_once 'vendor/autoload.php';

// Load Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\BackAlertUpdateLog;

echo "=== Checking Failed BackAlert Update Logs ===\n";

try {
    $failedLogs = BackAlertUpdateLog::failed()->get();
    
    echo "Total failed logs: " . $failedLogs->count() . "\n\n";
    
    foreach ($failedLogs as $log) {
        echo "Log ID: {$log->id}\n";
        echo "  BackAlert ID: {$log->backalert_id}\n";
        echo "  Status: {$log->getStatusName()}\n";
        echo "  Retry Count: {$log->retry_count}\n";
        echo "  Can Retry: " . ($log->canRetry() ? 'Yes' : 'No') . "\n";
        echo "  Error: " . ($log->error_message ?? 'No error message') . "\n";
        echo "  Created: {$log->created_at}\n";
        echo "  Updated: {$log->updated_at}\n";
        echo "---\n";
    }
    
    // Check retryable failed logs
    echo "\nRetryable failed logs (within retry limit):\n";
    $retryableLogs = BackAlertUpdateLog::failed()
        ->withinRetryLimit(3)
        ->get();
    
    echo "Count: " . $retryableLogs->count() . "\n";
    
    if ($retryableLogs->count() > 0) {
        echo "First retryable log:\n";
        $first = $retryableLogs->first();
        echo "  ID: {$first->id}, Retry Count: {$first->retry_count}, Error: " . ($first->error_message ?? 'None') . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}