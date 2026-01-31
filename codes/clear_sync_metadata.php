<?php

/**
 * Clear Sync Metadata Script
 * 
 * Clears the synced_at markers for records that were incorrectly marked as synced
 * but don't actually exist in PostgreSQL partition tables.
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$date = $argv[1] ?? '2026-01-08';

echo "=== Clear Sync Metadata for {$date} ===\n\n";

// Check current state
$mysqlTotal = DB::connection('mysql')
    ->table('alerts')
    ->whereDate('receivedtime', $date)
    ->count();

$mysqlSynced = DB::connection('mysql')
    ->table('alerts')
    ->whereDate('receivedtime', $date)
    ->whereNotNull('synced_at')
    ->count();

$partitionTable = 'alerts_' . str_replace('-', '_', $date);
$pgCount = 0;

try {
    $pgCount = DB::connection('pgsql')
        ->table($partitionTable)
        ->count();
} catch (\Exception $e) {
    echo "⚠️ Partition table {$partitionTable} doesn't exist yet\n";
}

echo "Current state:\n";
echo "  MySQL total: " . number_format($mysqlTotal) . " records\n";
echo "  MySQL marked synced: " . number_format($mysqlSynced) . " records\n";
echo "  PostgreSQL actual: " . number_format($pgCount) . " records\n";
echo "  Incorrectly marked: " . number_format($mysqlSynced - $pgCount) . " records\n\n";

if ($mysqlSynced <= $pgCount) {
    echo "✅ No incorrect markers found. Sync state is correct.\n";
    exit(0);
}

// Ask for confirmation
echo "This will clear synced_at for {$mysqlSynced} records from {$date}.\n";
echo "Type 'yes' to continue: ";

$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));
fclose($handle);

if ($line !== 'yes') {
    echo "Aborted.\n";
    exit(1);
}

echo "\nClearing synced_at markers...\n";

$updated = DB::connection('mysql')
    ->table('alerts')
    ->whereDate('receivedtime', $date)
    ->whereNotNull('synced_at')
    ->update(['synced_at' => null]);

echo "✅ Cleared synced_at for " . number_format($updated) . " records\n\n";

// Show new state
$mysqlUnsynced = DB::connection('mysql')
    ->table('alerts')
    ->whereDate('receivedtime', $date)
    ->whereNull('synced_at')
    ->count();

echo "New state:\n";
echo "  MySQL unsynced: " . number_format($mysqlUnsynced) . " records\n";
echo "  PostgreSQL: " . number_format($pgCount) . " records\n";
echo "  Need to sync: " . number_format($mysqlUnsynced) . " records\n\n";

echo "=== Next Steps ===\n";
echo "Run continuous sync to complete {$date}:\n";
echo "php continuous_sync.php\n";
