<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== 2026-01-08 Sync Status ===\n\n";

// Check MySQL
$mysqlTotal = DB::connection('mysql')
    ->table('alerts')
    ->whereDate('receivedtime', '2026-01-08')
    ->count();

$mysqlSynced = DB::connection('mysql')
    ->table('alerts')
    ->whereDate('receivedtime', '2026-01-08')
    ->whereNotNull('synced_at')
    ->count();

$mysqlUnsynced = DB::connection('mysql')
    ->table('alerts')
    ->whereDate('receivedtime', '2026-01-08')
    ->whereNull('synced_at')
    ->count();

echo "MySQL (2026-01-08):\n";
echo "  Total: " . number_format($mysqlTotal) . " records\n";
echo "  Synced: " . number_format($mysqlSynced) . " records\n";
echo "  Unsynced: " . number_format($mysqlUnsynced) . " records\n\n";

// Check PostgreSQL
$pgCount = DB::connection('pgsql')
    ->table('alerts_2026_01_08')
    ->count();

echo "PostgreSQL (alerts_2026_01_08):\n";
echo "  Total: " . number_format($pgCount) . " records\n\n";

// Check if there's a mismatch
$missing = $mysqlTotal - $pgCount;
echo "Missing records: " . number_format($missing) . "\n\n";

// Check the synced_at status
if ($mysqlSynced > 0) {
    echo "⚠️ Issue: {$mysqlSynced} records marked as synced in MySQL\n";
    echo "   but PostgreSQL only has {$pgCount} records\n\n";
    
    // Check if these were marked by our mark_synced.php script
    $markedRecords = DB::connection('mysql')
        ->table('alerts')
        ->whereDate('receivedtime', '2026-01-08')
        ->whereNotNull('synced_at')
        ->orderBy('synced_at')
        ->limit(5)
        ->get(['id', 'receivedtime', 'synced_at']);
    
    echo "Sample of marked records:\n";
    foreach ($markedRecords as $record) {
        echo "  ID: {$record->id}, Synced at: {$record->synced_at}\n";
    }
}

// Solution
if ($mysqlUnsynced > 0) {
    echo "\n=== Solution ===\n";
    echo "Run continuous sync to complete 2026-01-08:\n";
    echo "php artisan sync:partitioned --continuous\n";
} else {
    echo "\n=== Action Needed ===\n";
    echo "Records were incorrectly marked as synced.\n";
    echo "Need to clear synced_at for 2026-01-08 and re-sync:\n";
    echo "php clear_sync_metadata.php 2026-01-08\n";
}
