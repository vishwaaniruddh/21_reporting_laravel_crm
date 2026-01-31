<?php

require_once 'vendor/autoload.php';

// Load Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== BackAlert PostgreSQL Data Verification ===\n";

try {
    // Check records in partition table
    $records = DB::connection('pgsql')
        ->table('backalerts_2026_01_17')
        ->orderBy('id')
        ->get();
    
    echo "Records in backalerts_2026_01_17: " . $records->count() . "\n\n";
    
    foreach ($records as $record) {
        echo "ID: {$record->id}\n";
        echo "  Panel ID: {$record->panelid}\n";
        echo "  Zone: {$record->zone}\n";
        echo "  Alarm: {$record->alarm}\n";
        echo "  Status: {$record->status}\n";
        echo "  Received Time: {$record->receivedtime}\n";
        echo "  Alert User Status: {$record->alertuserstatus}\n";
        echo "  Closed By: {$record->closedby}\n";
        echo "  Synced At: {$record->synced_at}\n";
        echo "  Sync Batch ID: {$record->sync_batch_id}\n";
        echo "---\n";
    }
    
    // Check if MySQL records are marked as synced
    echo "\nMySQL BackAlert sync status:\n";
    $mysqlRecords = DB::connection('mysql')
        ->table('backalerts')
        ->whereIn('id', [29637338, 29637339, 29637340])
        ->select('id', 'synced_at', 'sync_batch_id')
        ->get();
    
    foreach ($mysqlRecords as $record) {
        echo "ID: {$record->id} - Synced: " . ($record->synced_at ? 'YES' : 'NO') . " - Batch: {$record->sync_batch_id}\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}