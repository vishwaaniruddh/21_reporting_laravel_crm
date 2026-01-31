<?php

require_once 'vendor/autoload.php';

// Load Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\BackAlertUpdateLog;

echo "=== Resetting All Failed BackAlert Update Logs ===\n";

try {
    // Get all failed logs
    $failedLogs = BackAlertUpdateLog::failed()->get();
    
    echo "Found {$failedLogs->count()} failed logs to reset\n";
    
    foreach ($failedLogs as $log) {
        echo "Resetting log ID {$log->id} (BackAlert ID: {$log->backalert_id})\n";
        
        // Reset to pending with retry_count = 0
        $log->update([
            'status' => BackAlertUpdateLog::STATUS_PENDING,
            'error_message' => null,
            'retry_count' => 0,
        ]);
    }
    
    echo "\n=== Reset Complete ===\n";
    echo "All failed logs are now pending and will be retried by the BackAlertUpdateSync service\n";
    
    // Verify the reset
    echo "\nVerification:\n";
    $pendingLogs = BackAlertUpdateLog::pending()->withinRetryLimit(3)->get();
    echo "Pending logs within retry limit: " . $pendingLogs->count() . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}