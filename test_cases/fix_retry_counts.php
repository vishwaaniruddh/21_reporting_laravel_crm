<?php

require_once 'vendor/autoload.php';

// Load Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\BackAlertUpdateLog;

echo "=== Fixing BackAlert Update Log Retry Counts ===\n";

try {
    // Get the pending logs that have exceeded retry limit
    $pendingLogs = BackAlertUpdateLog::pending()
        ->where('retry_count', '>=', 3)
        ->get();
    
    echo "Found {$pendingLogs->count()} pending logs with high retry counts\n";
    
    foreach ($pendingLogs as $log) {
        echo "Fixing log ID {$log->id} (BackAlert ID: {$log->backalert_id}) - retry_count: {$log->retry_count} -> 0\n";
        
        // Reset to pending with retry_count = 0
        $log->update([
            'status' => BackAlertUpdateLog::STATUS_PENDING,
            'error_message' => null,
            'retry_count' => 0,
        ]);
    }
    
    echo "\n=== Fix Complete ===\n";
    echo "All pending logs now have retry_count = 0 and can be processed\n";
    
    // Verify the fix
    echo "\nVerification:\n";
    $retryableLogs = BackAlertUpdateLog::pending()->withinRetryLimit(3)->get();
    echo "Pending logs within retry limit: " . $retryableLogs->count() . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}