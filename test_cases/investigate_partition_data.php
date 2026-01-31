<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== INVESTIGATING PARTITION DATA ISSUE ===\n\n";

try {
    // Check if data actually exists in partition tables
    echo "1. CHECKING PARTITION TABLE RECORD COUNTS:\n";
    
    $partitionTables = [
        'alerts_2026_01_16', 'alerts_2026_01_17', 'alerts_2026_01_18',
        'alerts_2026_01_19', 'alerts_2026_01_20', 'alerts_2026_01_21',
        'alerts_2026_01_22', 'alerts_2026_01_23', 'alerts_2026_01_24',
        'alerts_2026_01_25', 'alerts_2026_01_26', 'alerts_2026_01_27',
        'alerts_2026_01_28'
    ];
    
    $totalRecords = 0;
    
    foreach ($partitionTables as $table) {
        try {
            $count = DB::connection('pgsql')->select("SELECT COUNT(*) as count FROM {$table}")[0]->count;
            $totalRecords += $count;
            
            if ($count > 0) {
                echo "✓ {$table}: " . number_format($count) . " records\n";
                
                // Show sample data
                $sample = DB::connection('pgsql')->select("SELECT id, receivedtime FROM {$table} ORDER BY id LIMIT 3");
                foreach ($sample as $record) {
                    echo "    Sample: ID {$record->id}, Time: {$record->receivedtime}\n";
                }
            } else {
                echo "✗ {$table}: 0 records\n";
            }
        } catch (Exception $e) {
            echo "✗ {$table}: ERROR - " . $e->getMessage() . "\n";
        }
    }
    
    echo "\nTotal records across all partitions: " . number_format($totalRecords) . "\n\n";
    
    // Check if data went to unexpected tables
    echo "2. CHECKING FOR DATA IN UNEXPECTED TABLES:\n";
    
    $allTables = DB::connection('pgsql')->select(
        "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'alerts_%' ORDER BY tablename"
    );
    
    foreach ($allTables as $tableObj) {
        $table = $tableObj->tablename;
        if (!in_array($table, $partitionTables)) {
            try {
                $count = DB::connection('pgsql')->select("SELECT COUNT(*) as count FROM {$table}")[0]->count;
                if ($count > 0) {
                    echo "! {$table}: " . number_format($count) . " records (unexpected table)\n";
                }
            } catch (Exception $e) {
                // Ignore errors for non-existent tables
            }
        }
    }
    
    // Check source table
    echo "\n3. CHECKING SOURCE TABLE:\n";
    try {
        $sourceCount = DB::connection('mysql')->select("SELECT COUNT(*) as count FROM alerts_all_data")[0]->count;
        echo "alerts_all_data (MySQL): " . number_format($sourceCount) . " records\n";
        
        if ($sourceCount > 0) {
            echo "⚠️  WARNING: Source table still has data - records may not have been synced!\n";
            
            // Show sample from source
            $sourceSample = DB::connection('mysql')->select("SELECT id, receivedtime FROM alerts_all_data ORDER BY id LIMIT 5");
            echo "Source sample data:\n";
            foreach ($sourceSample as $record) {
                echo "  ID: {$record->id}, Time: {$record->receivedtime}\n";
            }
        }
    } catch (Exception $e) {
        echo "Error checking source: " . $e->getMessage() . "\n";
    }
    
    // Check for transaction issues
    echo "\n4. CHECKING FOR RECENT INSERTS:\n";
    
    // Check the most recent partition table that should have data
    $recentTable = 'alerts_2026_01_27';
    try {
        $recentCount = DB::connection('pgsql')->select("SELECT COUNT(*) as count FROM {$recentTable}")[0]->count;
        echo "{$recentTable}: " . number_format($recentCount) . " records\n";
        
        if ($recentCount > 0) {
            // Check ID ranges
            $minMax = DB::connection('pgsql')->select("SELECT MIN(id) as min_id, MAX(id) as max_id FROM {$recentTable}")[0];
            echo "ID range: {$minMax->min_id} - {$minMax->max_id}\n";
            
            // Check sync timestamps
            $syncInfo = DB::connection('pgsql')->select("SELECT MIN(synced_at) as first_sync, MAX(synced_at) as last_sync FROM {$recentTable}")[0];
            echo "Sync time range: {$syncInfo->first_sync} - {$syncInfo->last_sync}\n";
        }
    } catch (Exception $e) {
        echo "Error checking {$recentTable}: " . $e->getMessage() . "\n";
    }
    
    // Summary
    echo "\n=== SUMMARY ===\n";
    if ($totalRecords == 0) {
        echo "❌ PROBLEM: No data found in partition tables!\n";
        echo "Possible causes:\n";
        echo "1. Transaction rollback during sync\n";
        echo "2. Wrong table names being used\n";
        echo "3. Date extraction issues\n";
        echo "4. Database connection problems\n";
    } elseif ($totalRecords < 9000000) {
        echo "⚠️  PARTIAL SYNC: Only " . number_format($totalRecords) . " out of 9.3M records found\n";
        echo "Some data may have been lost or went to wrong tables\n";
    } else {
        echo "✅ SUCCESS: Found " . number_format($totalRecords) . " records in partition tables\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}