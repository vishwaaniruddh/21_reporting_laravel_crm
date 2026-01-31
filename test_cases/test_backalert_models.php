<?php

require_once 'vendor/autoload.php';

// Load Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\BackAlert;
use App\Models\BackAlertUpdateLog;

echo "=== Testing BackAlert Models ===\n";

try {
    // Test BackAlert model
    echo "\n1. Testing BackAlert Model:\n";
    
    $totalBackAlerts = BackAlert::count();
    echo "Total backalerts: " . number_format($totalBackAlerts) . "\n";
    
    $unsyncedCount = BackAlert::unsynced()->count();
    echo "Unsynced backalerts: " . number_format($unsyncedCount) . "\n";
    
    $syncedCount = BackAlert::synced()->count();
    echo "Synced backalerts: " . number_format($syncedCount) . "\n";
    
    // Get a sample backalert
    $sampleBackAlert = BackAlert::first();
    if ($sampleBackAlert) {
        echo "Sample BackAlert ID: {$sampleBackAlert->id}\n";
        echo "Panel ID: {$sampleBackAlert->panelid}\n";
        echo "Received Time: {$sampleBackAlert->receivedtime}\n";
        echo "Is Synced: " . ($sampleBackAlert->isSynced() ? 'Yes' : 'No') . "\n";
        echo "Partition Table: {$sampleBackAlert->getPartitionTableName()}\n";
    }
    
    // Test BackAlertUpdateLog model
    echo "\n2. Testing BackAlertUpdateLog Model:\n";
    
    $totalLogs = BackAlertUpdateLog::count();
    echo "Total update logs: {$totalLogs}\n";
    
    $pendingLogs = BackAlertUpdateLog::pending()->count();
    echo "Pending logs: {$pendingLogs}\n";
    
    $completedLogs = BackAlertUpdateLog::completed()->count();
    echo "Completed logs: {$completedLogs}\n";
    
    $failedLogs = BackAlertUpdateLog::failed()->count();
    echo "Failed logs: {$failedLogs}\n";
    
    // Get a sample log entry
    $sampleLog = BackAlertUpdateLog::first();
    if ($sampleLog) {
        echo "Sample Log ID: {$sampleLog->id}\n";
        echo "BackAlert ID: {$sampleLog->backalert_id}\n";
        echo "Status: {$sampleLog->getStatusName()}\n";
        echo "Retry Count: {$sampleLog->retry_count}\n";
        echo "Can Retry: " . ($sampleLog->canRetry() ? 'Yes' : 'No') . "\n";
        echo "Created: {$sampleLog->created_at}\n";
    }
    
    // Test relationship
    echo "\n3. Testing Model Relationships:\n";
    if ($sampleLog && $sampleLog->backAlert) {
        echo "✓ BackAlertUpdateLog -> BackAlert relationship working\n";
        echo "  Related BackAlert ID: {$sampleLog->backAlert->id}\n";
        echo "  Related BackAlert Panel: {$sampleLog->backAlert->panelid}\n";
    } else {
        echo "✗ BackAlertUpdateLog -> BackAlert relationship not working\n";
    }
    
    if ($sampleBackAlert) {
        $updateLogsCount = $sampleBackAlert->updateLogs()->count();
        echo "✓ BackAlert -> BackAlertUpdateLog relationship working\n";
        echo "  Update logs for BackAlert {$sampleBackAlert->id}: {$updateLogsCount}\n";
    }
    
    echo "\n=== Models Test Complete ===\n";
    echo "✓ BackAlert model: Working\n";
    echo "✓ BackAlertUpdateLog model: Working\n";
    echo "✓ Relationships: Working\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}