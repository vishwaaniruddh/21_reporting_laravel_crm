<?php

/**
 * Re-sync Missing Alert Updates
 * 
 * This script finds alerts in MySQL that have been updated but are missing
 * from alert_pg_update_log (due to deleted trigger), and creates log entries
 * so they can be synced to PostgreSQL.
 * 
 * Use this ONCE after recreating the trigger to catch up on missed updates.
 */

require_once 'vendor/autoload.php';

// Increase memory limit for large datasets
ini_set('memory_limit', '512M');

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

echo "=== Re-sync Missing Alert Updates ===\n\n";

// Configuration
$daysBack = 7; // How many days back to check for missing updates
$batchSize = 1000; // Process in batches
$dryRun = false; // Set to true to see what would be done without doing it

// Parse command line arguments
if (in_array('--dry-run', $argv)) {
    $dryRun = true;
    echo "DRY RUN MODE - No changes will be made\n\n";
}

if (in_array('--days', $argv)) {
    $key = array_search('--days', $argv);
    if (isset($argv[$key + 1])) {
        $daysBack = (int)$argv[$key + 1];
    }
}

echo "Configuration:\n";
echo "  Days back: $daysBack\n";
echo "  Batch size: $batchSize\n";
echo "  Dry run: " . ($dryRun ? 'YES' : 'NO') . "\n\n";

$startDate = Carbon::now()->subDays($daysBack)->startOfDay();
$endDate = Carbon::now();

echo "Checking alerts from {$startDate->toDateString()} to {$endDate->toDateString()}\n\n";

// Step 1: Count alerts that are missing log entries
echo "Step 1: Counting alerts with missing log entries...\n";

$totalMissing = DB::connection('mysql')
    ->table('alerts')
    ->whereNotNull('receivedtime')
    ->whereBetween('receivedtime', [$startDate, $endDate])
    ->whereNotExists(function ($query) {
        $query->select(DB::raw(1))
            ->from('alert_pg_update_log')
            ->whereColumn('alert_pg_update_log.alert_id', 'alerts.id');
    })
    ->count();

echo "Found $totalMissing alerts with no log entries\n\n";

if ($totalMissing == 0) {
    echo "✓ No missing alerts found. All alerts have log entries.\n";
    exit(0);
}

// Step 2: Show sample of missing alerts
echo "Step 2: Fetching sample of missing alerts...\n";
$sample = DB::connection('mysql')
    ->table('alerts')
    ->select('id', 'receivedtime', 'status', 'sendtoclient')
    ->whereNotNull('receivedtime')
    ->whereBetween('receivedtime', [$startDate, $endDate])
    ->whereNotExists(function ($query) {
        $query->select(DB::raw(1))
            ->from('alert_pg_update_log')
            ->whereColumn('alert_pg_update_log.alert_id', 'alerts.id');
    })
    ->orderBy('receivedtime', 'asc')
    ->limit(10)
    ->get();

echo "Sample of missing alerts:\n";
foreach ($sample as $alert) {
    echo "  ID: {$alert->id}, Date: {$alert->receivedtime}, Status: {$alert->status}\n";
}
echo "\n";

// Step 3: Ask for confirmation
if (!$dryRun) {
    echo "This will create $totalMissing log entries in alert_pg_update_log.\n";
    echo "These alerts will then be synced to PostgreSQL by the update worker.\n\n";
    echo "Do you want to proceed? (type 'YES' to confirm): ";
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    fclose($handle);
    
    if ($line !== 'YES') {
        echo "Operation cancelled.\n";
        exit(0);
    }
    echo "\n";
}

// Step 4: Create log entries in batches using chunking
echo "Step 4: Creating log entries in batches...\n";

$processed = 0;
$offset = 0;

while ($offset < $totalMissing) {
    // Fetch a batch of alert IDs
    $alertIds = DB::connection('mysql')
        ->table('alerts')
        ->select('id')
        ->whereNotNull('receivedtime')
        ->whereBetween('receivedtime', [$startDate, $endDate])
        ->whereNotExists(function ($query) {
            $query->select(DB::raw(1))
                ->from('alert_pg_update_log')
                ->whereColumn('alert_pg_update_log.alert_id', 'alerts.id');
        })
        ->orderBy('id', 'asc')
        ->limit($batchSize)
        ->offset($offset)
        ->pluck('id');
    
    if ($alertIds->isEmpty()) {
        break;
    }
    
    // Create log entries for this batch
    $logEntries = [];
    foreach ($alertIds as $alertId) {
        $logEntries[] = [
            'alert_id' => $alertId,
            'status' => 1, // Pending
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
    
    if (!$dryRun) {
        DB::connection('mysql')
            ->table('alert_pg_update_log')
            ->insert($logEntries);
    }
    
    $processed += count($logEntries);
    $offset += $batchSize;
    
    $percentage = round(($processed / $totalMissing) * 100, 1);
    echo "  Processed: $processed / $totalMissing ($percentage%)\n";
    
    // Free memory
    unset($alertIds, $logEntries);
    gc_collect_cycles();
}

echo "\n";

if ($dryRun) {
    echo "DRY RUN COMPLETE\n";
    echo "Would have created $totalMissing log entries\n";
} else {
    echo "✓ COMPLETE\n";
    echo "Created $totalMissing log entries in alert_pg_update_log\n";
    echo "\nNext steps:\n";
    echo "1. The AlertUpdateSync service will automatically process these entries\n";
    echo "2. Monitor progress: php artisan tinker --execute=\"echo DB::connection('mysql')->table('alert_pg_update_log')->where('status', 1)->count() . ' pending' . PHP_EOL;\"\n";
    echo "3. Check logs: Get-Content storage\\logs\\update-sync-service.log -Tail 50 -Wait\n";
}

echo "\n";
