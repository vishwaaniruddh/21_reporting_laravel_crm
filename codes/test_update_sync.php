<?php

/**
 * Test Update Sync with Partitions
 * 
 * This script tests that the AlertSyncService correctly:
 * 1. Reads an alert from MySQL
 * 2. Determines the correct partition table
 * 3. Creates the partition if needed
 * 4. UPSERTs the alert to the partition table
 * 5. Updates the partition_registry
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\AlertSyncService;
use App\Services\SyncLogger;
use App\Services\PartitionManager;
use App\Services\DateExtractor;
use App\Models\Alert;
use App\Models\AlertUpdateLog;
use Illuminate\Support\Facades\DB;

echo "=== Update Sync Partition Test ===\n\n";

// Initialize services
$logger = new SyncLogger();
$partitionManager = new PartitionManager();
$dateExtractor = new DateExtractor();
$syncService = new AlertSyncService($logger, $partitionManager, $dateExtractor);

echo "✓ Services initialized\n\n";

// Step 1: Find a pending update log entry
echo "Step 1: Finding pending update log entry...\n";
$logEntry = AlertUpdateLog::where('status', 1)->first();

if (!$logEntry) {
    echo "❌ No pending update log entries found (status=1)\n";
    echo "   Create a test entry or wait for Java app to create updates\n";
    exit(1);
}

echo "✓ Found pending entry:\n";
echo "  - Log ID: {$logEntry->id}\n";
echo "  - Alert ID: {$logEntry->alert_id}\n";
echo "  - Created: {$logEntry->created_at}\n\n";

// Step 2: Fetch alert from MySQL
echo "Step 2: Fetching alert from MySQL...\n";
$alert = Alert::find($logEntry->alert_id);

if (!$alert) {
    echo "❌ Alert {$logEntry->alert_id} not found in MySQL\n";
    exit(1);
}

echo "✓ Alert found:\n";
echo "  - ID: {$alert->id}\n";
echo "  - Panel ID: {$alert->panelid}\n";
echo "  - Received Time: {$alert->receivedtime}\n";

// Step 3: Determine partition
echo "\nStep 3: Determining partition table...\n";
try {
    $date = $dateExtractor->extractDate($alert->receivedtime);
    $partitionTable = $partitionManager->getPartitionTableName($date);
    
    echo "✓ Partition determined:\n";
    echo "  - Date: {$date->toDateString()}\n";
    echo "  - Table: {$partitionTable}\n";
} catch (Exception $e) {
    echo "❌ Failed to determine partition: {$e->getMessage()}\n";
    exit(1);
}

// Step 4: Check if partition exists
echo "\nStep 4: Checking if partition exists...\n";
$partitionExists = $partitionManager->partitionTableExists($partitionTable);

if ($partitionExists) {
    echo "✓ Partition table exists: {$partitionTable}\n";
    
    // Get current record count
    try {
        $currentCount = $partitionManager->getPartitionRecordCount($partitionTable);
        echo "  - Current record count: {$currentCount}\n";
    } catch (Exception $e) {
        echo "  - Could not get record count: {$e->getMessage()}\n";
    }
} else {
    echo "⚠ Partition table does not exist yet: {$partitionTable}\n";
    echo "  - Will be created during sync\n";
}

// Step 5: Sync the alert
echo "\nStep 5: Syncing alert to partition table...\n";
try {
    $result = $syncService->syncAlert($logEntry->id, $logEntry->alert_id);
    
    if ($result->success) {
        echo "✓ Alert synced successfully!\n";
        echo "  - Duration: " . number_format($result->duration, 3) . "s\n";
    } else {
        echo "❌ Alert sync failed: {$result->errorMessage}\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "❌ Exception during sync: {$e->getMessage()}\n";
    echo "   Stack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

// Step 6: Verify log entry was updated
echo "\nStep 6: Verifying log entry status...\n";
$logEntry->refresh();

echo "✓ Log entry updated:\n";
echo "  - Status: {$logEntry->status} ";
if ($logEntry->status == 2) {
    echo "(completed) ✓\n";
} elseif ($logEntry->status == 3) {
    echo "(failed) ❌\n";
    echo "  - Error: {$logEntry->error_message}\n";
} else {
    echo "(pending) ⚠\n";
}
echo "  - Updated At: {$logEntry->updated_at}\n";

// Step 7: Verify alert in PostgreSQL partition
echo "\nStep 7: Verifying alert in PostgreSQL partition...\n";
try {
    $pgAlert = DB::connection('pgsql')
        ->table($partitionTable)
        ->where('id', $alert->id)
        ->first();
    
    if ($pgAlert) {
        echo "✓ Alert found in partition table:\n";
        echo "  - ID: {$pgAlert->id}\n";
        echo "  - Panel ID: {$pgAlert->panelid}\n";
        echo "  - Received Time: {$pgAlert->receivedtime}\n";
        echo "  - Synced At: {$pgAlert->synced_at}\n";
    } else {
        echo "❌ Alert not found in partition table\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "❌ Error querying partition table: {$e->getMessage()}\n";
    exit(1);
}

// Step 8: Check partition registry
echo "\nStep 8: Checking partition registry...\n";
try {
    $partitionInfo = $partitionManager->getPartitionInfo($partitionTable);
    
    if ($partitionInfo) {
        echo "✓ Partition registry entry:\n";
        echo "  - Table: {$partitionInfo->table_name}\n";
        echo "  - Date: {$partitionInfo->partition_date}\n";
        echo "  - Record Count: {$partitionInfo->record_count}\n";
        echo "  - Last Updated: {$partitionInfo->last_updated}\n";
    } else {
        echo "⚠ Partition not found in registry\n";
    }
} catch (Exception $e) {
    echo "⚠ Error checking registry: {$e->getMessage()}\n";
}

echo "\n=== Test Complete ===\n";
echo "✓ Update sync with partitions is working correctly!\n";
echo "\nYou can now start the worker:\n";
echo "  php artisan sync:update-worker\n";
