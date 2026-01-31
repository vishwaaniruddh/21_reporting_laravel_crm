<?php

require_once 'vendor/autoload.php';

// Load Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\BackAlertUpdateLog;
use App\Services\BackAlertSyncService;

echo "=== BackAlert Sync Service Debug ===\n";

try {
    // Check pending entries directly
    echo "1. Direct query for pending entries:\n";
    $pendingLogs = BackAlertUpdateLog::pending()->get();
    echo "   Found: " . $pendingLogs->count() . " pending logs\n";
    
    if ($pendingLogs->count() > 0) {
        echo "   First pending log: ID {$pendingLogs->first()->id}, BackAlert ID {$pendingLogs->first()->backalert_id}\n";
    }
    
    // Check with retry limit
    echo "\n2. Pending entries within retry limit (3):\n";
    $retryableLogs = BackAlertUpdateLog::pending()
        ->withinRetryLimit(3)
        ->get();
    echo "   Found: " . $retryableLogs->count() . " retryable pending logs\n";
    
    // Test the sync service
    echo "\n3. Testing BackAlertSyncService:\n";
    $syncService = new BackAlertSyncService();
    
    echo "   Getting stats...\n";
    $stats = $syncService->getPendingUpdateStats();
    echo "   Stats - Pending: {$stats['pending']}, Completed: {$stats['completed']}, Failed: {$stats['failed']}\n";
    
    echo "\n   Processing pending updates (batch size 5)...\n";
    $result = $syncService->processPendingUpdates(5);
    echo "   Result: {$result['message']}\n";
    echo "   Processed: {$result['processed']}, Successful: {$result['successful']}, Failed: {$result['failed']}\n";
    
    if ($result['processed'] > 0) {
        echo "\n4. Checking if partition table was created:\n";
        $tableExists = \Illuminate\Support\Facades\DB::connection('pgsql')
            ->select("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'backalerts_2026_01_17')");
        
        $exists = $tableExists[0]->exists ?? false;
        echo "   backalerts_2026_01_17 table exists: " . ($exists ? 'YES' : 'NO') . "\n";
        
        if ($exists) {
            $count = \Illuminate\Support\Facades\DB::connection('pgsql')->table('backalerts_2026_01_17')->count();
            echo "   Records in table: $count\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}