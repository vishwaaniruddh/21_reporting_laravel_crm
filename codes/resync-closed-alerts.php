<?php

/**
 * Re-sync Closed Alerts Only
 * 
 * This script finds alerts with Status='C' (closed) that are missing
 * from alert_pg_update_log and creates log entries for them.
 * 
 * This is more targeted than syncing all alerts - focuses on status changes
 * that need to be reflected in PostgreSQL.
 */

require_once 'vendor/autoload.php';

// Increase memory limit
ini_set('memory_limit', '512M');

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

echo "=== Re-sync Closed Alerts (Status='C') ===\n\n";

// Configuration
$batchSize = 5000; // Larger batch size for faster processing
$dryRun = false;

// Parse command line arguments
if (in_array('--dry-run', $argv)) {
    $dryRun = true;
    echo "DRY RUN MODE - No changes will be made\n\n";
}

echo "Configuration:\n";
echo "  Target: Alerts with Status='C' missing from log\n";
echo "  Batch size: $batchSize\n";
echo "  Dry run: " . ($dryRun ? 'YES' : 'NO') . "\n\n";

// Step 1: Count closed alerts missing from log
echo "Step 1: Counting closed alerts with missing log entries...\n";

$totalMissing = DB::connection('mysql')
    ->table('alerts')
    ->where('status', 'C')
    ->whereNotExists(function ($query) {
        $query->select(DB::raw(1))
            ->from('alert_pg_update_log')
            ->whereColumn('alert_pg_update_log.alert_id', 'alerts.id');
    })
    ->count();

echo "Found " . number_format($totalMissing) . " closed alerts with no log entries\n\n";

if ($totalMissing == 0) {
    echo "✓ No missing closed alerts found. All closed alerts have log entries.\n";
    exit(0);
}

// Step 2: Show sample
echo "Step 2: Fetching sample of missing closed alerts...\n";
$sample = DB::connection('mysql')
    ->table('alerts')
    ->select('id', 'receivedtime', 'status', 'panelid')
    ->where('status', 'C')
    ->whereNotExists(function ($query) {
        $query->select(DB::raw(1))
            ->from('alert_pg_update_log')
            ->whereColumn('alert_pg_update_log.alert_id', 'alerts.id');
    })
    ->orderBy('receivedtime', 'desc')
    ->limit(10)
    ->get();

echo "Sample of missing closed alerts:\n";
foreach ($sample as $alert) {
    echo "  ID: {$alert->id}, Date: {$alert->receivedtime}, Panel: {$alert->panelid}\n";
}
echo "\n";

// Step 3: Ask for confirmation
if (!$dryRun) {
    echo "This will create " . number_format($totalMissing) . " log entries in alert_pg_update_log.\n";
    echo "These closed alerts will then be synced to PostgreSQL by the update worker.\n\n";
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

// Step 4: Create log entries using efficient INSERT SELECT
echo "Step 4: Creating log entries...\n";

$startTime = microtime(true);

if (!$dryRun) {
    // Use INSERT SELECT for maximum efficiency
    $inserted = DB::connection('mysql')->statement("
        INSERT INTO alert_pg_update_log (alert_id, status, created_at, updated_at)
        SELECT 
            a.id,
            1 as status,
            NOW() as created_at,
            NOW() as updated_at
        FROM alerts a
        WHERE a.status = 'C'
        AND NOT EXISTS (
            SELECT 1 
            FROM alert_pg_update_log aul 
            WHERE aul.alert_id = a.id
        )
    ");
    
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);
    
    echo "✓ COMPLETE\n";
    echo "Created " . number_format($totalMissing) . " log entries in {$duration} seconds\n";
} else {
    echo "DRY RUN COMPLETE\n";
    echo "Would have created " . number_format($totalMissing) . " log entries\n";
}

echo "\n";

// Step 5: Verify
if (!$dryRun) {
    echo "Step 5: Verifying...\n";
    
    $pendingCount = DB::connection('mysql')
        ->table('alert_pg_update_log')
        ->where('status', 1)
        ->count();
    
    echo "Pending log entries: " . number_format($pendingCount) . "\n\n";
    
    echo "Next steps:\n";
    echo "1. The AlertUpdateSync service will automatically process these entries\n";
    echo "2. Monitor progress:\n";
    echo "   php artisan tinker --execute=\"echo 'Pending: ' . number_format(DB::connection('mysql')->table('alert_pg_update_log')->where('status', 1)->count()) . PHP_EOL;\"\n";
    echo "3. Check logs:\n";
    echo "   Get-Content storage\\logs\\update-sync-service.log -Tail 50 -Wait\n";
}

echo "\n";
