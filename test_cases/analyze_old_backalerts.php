<?php

require_once 'vendor/autoload.php';

// Load Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Analyzing Old BackAlert Records (>48 hours) ===\n";

try {
    // Calculate 48 hours ago
    $cutoffTime = now()->subHours(48);
    echo "Cutoff time (48 hours ago): {$cutoffTime}\n\n";
    
    // Count records older than 48 hours
    $oldRecordsCount = DB::connection('mysql')
        ->table('backalerts')
        ->where('receivedtime', '<', $cutoffTime)
        ->count();
    
    echo "Records older than 48 hours: " . number_format($oldRecordsCount) . "\n";
    
    // Count total records
    $totalRecords = DB::connection('mysql')->table('backalerts')->count();
    echo "Total BackAlert records: " . number_format($totalRecords) . "\n";
    
    // Calculate percentage
    $percentage = $totalRecords > 0 ? ($oldRecordsCount / $totalRecords) * 100 : 0;
    echo "Percentage to be deleted: " . round($percentage, 2) . "%\n\n";
    
    // Check sync status of old records
    $oldSyncedCount = DB::connection('mysql')
        ->table('backalerts')
        ->where('receivedtime', '<', $cutoffTime)
        ->whereNotNull('synced_at')
        ->count();
    
    $oldUnsyncedCount = DB::connection('mysql')
        ->table('backalerts')
        ->where('receivedtime', '<', $cutoffTime)
        ->whereNull('synced_at')
        ->count();
    
    echo "Old records - Synced to PostgreSQL: " . number_format($oldSyncedCount) . "\n";
    echo "Old records - NOT synced to PostgreSQL: " . number_format($oldUnsyncedCount) . "\n\n";
    
    // Show date range of old records
    $dateRange = DB::connection('mysql')
        ->table('backalerts')
        ->where('receivedtime', '<', $cutoffTime)
        ->selectRaw('MIN(receivedtime) as min_date, MAX(receivedtime) as max_date')
        ->first();
    
    if ($dateRange && $dateRange->min_date) {
        echo "Date range of old records:\n";
        echo "  Oldest: {$dateRange->min_date}\n";
        echo "  Newest: {$dateRange->max_date}\n\n";
    }
    
    // Show sample of old records
    echo "Sample of old records (first 5):\n";
    $sampleRecords = DB::connection('mysql')
        ->table('backalerts')
        ->where('receivedtime', '<', $cutoffTime)
        ->orderBy('receivedtime', 'asc')
        ->limit(5)
        ->select('id', 'panelid', 'receivedtime', 'synced_at')
        ->get();
    
    foreach ($sampleRecords as $record) {
        $syncStatus = $record->synced_at ? 'SYNCED' : 'NOT SYNCED';
        echo "  ID: {$record->id}, Panel: {$record->panelid}, Received: {$record->receivedtime}, Status: {$syncStatus}\n";
    }
    
    if ($oldUnsyncedCount > 0) {
        echo "\n⚠️  WARNING: {$oldUnsyncedCount} old records are NOT synced to PostgreSQL!\n";
        echo "   These records will be LOST if deleted before sync completes.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}