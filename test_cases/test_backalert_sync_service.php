<?php

require_once 'vendor/autoload.php';

// Load Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\BackAlert;
use App\Models\BackAlertUpdateLog;
use Illuminate\Support\Facades\DB;

echo "=== Testing BackAlert Sync Service ===\n";

try {
    // Check current update log status
    echo "\n1. Current Update Log Status:\n";
    $stats = [
        'pending' => BackAlertUpdateLog::pending()->count(),
        'completed' => BackAlertUpdateLog::completed()->count(),
        'failed' => BackAlertUpdateLog::failed()->count(),
        'total' => BackAlertUpdateLog::count(),
    ];
    
    foreach ($stats as $status => $count) {
        echo "  {$status}: {$count}\n";
    }
    
    // Trigger some updates to create log entries
    echo "\n2. Creating Test Update Log Entries:\n";
    
    $testBackAlerts = BackAlert::limit(3)->get();
    
    foreach ($testBackAlerts as $backAlert) {
        echo "Updating BackAlert ID: {$backAlert->id}\n";
        
        // Update the record to trigger the UPDATE trigger
        $testComment = 'Test sync service - ' . date('Y-m-d H:i:s');
        DB::connection('mysql')->table('backalerts')
            ->where('id', $backAlert->id)
            ->update(['comment' => $testComment]);
        
        echo "  Updated comment to: {$testComment}\n";
    }
    
    // Wait a moment for the service to process
    echo "\n3. Waiting 10 seconds for service to process...\n";
    sleep(10);
    
    // Check updated stats
    echo "\n4. Updated Status:\n";
    $newStats = [
        'pending' => BackAlertUpdateLog::pending()->count(),
        'completed' => BackAlertUpdateLog::completed()->count(),
        'failed' => BackAlertUpdateLog::failed()->count(),
        'total' => BackAlertUpdateLog::count(),
    ];
    
    foreach ($newStats as $status => $count) {
        echo "  {$status}: {$count}\n";
    }
    
    // Show processing results
    $processed = $newStats['completed'] - $stats['completed'];
    $newEntries = $newStats['total'] - $stats['total'];
    
    echo "\n5. Results:\n";
    echo "  New log entries created: {$newEntries}\n";
    echo "  Entries processed by service: {$processed}\n";
    
    if ($processed > 0) {
        echo "  ✓ BackAlert Update Sync Service is working!\n";
        
        // Show latest completed entries
        $latestCompleted = BackAlertUpdateLog::completed()
            ->orderBy('updated_at', 'desc')
            ->limit(3)
            ->get();
        
        echo "\n  Latest completed entries:\n";
        foreach ($latestCompleted as $log) {
            echo "    ID: {$log->id}, BackAlert: {$log->backalert_id}, Completed: {$log->updated_at}\n";
        }
    } else {
        echo "  ⚠ Service may need more time to process entries\n";
    }
    
    // Restore original comments
    echo "\n6. Restoring original comments...\n";
    foreach ($testBackAlerts as $backAlert) {
        DB::connection('mysql')->table('backalerts')
            ->where('id', $backAlert->id)
            ->update(['comment' => $backAlert->comment]);
    }
    echo "  ✓ Original comments restored\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}