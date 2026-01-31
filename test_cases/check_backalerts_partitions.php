<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== CHECKING BACKALERTS PARTITION TABLES ===\n\n";

try {
    // Get all backalerts partition tables from PostgreSQL
    $backAlertTables = DB::connection('pgsql')->select(
        "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'backalerts_%' ORDER BY tablename"
    );
    
    echo "Found " . count($backAlertTables) . " backalerts partition tables:\n\n";
    
    $totalBackAlertRecords = 0;
    
    foreach ($backAlertTables as $tableObj) {
        $tableName = $tableObj->tablename;
        
        try {
            $count = DB::connection('pgsql')->select("SELECT COUNT(*) as count FROM {$tableName}")[0]->count;
            $totalBackAlertRecords += $count;
            
            if ($count > 0) {
                echo "✓ {$tableName}: " . number_format($count) . " records\n";
                
                // Show sample data
                $sample = DB::connection('pgsql')->select("SELECT id, receivedtime FROM {$tableName} ORDER BY id LIMIT 2");
                foreach ($sample as $record) {
                    echo "    Sample: ID {$record->id}, Time: {$record->receivedtime}\n";
                }
            } else {
                echo "✗ {$tableName}: 0 records\n";
            }
        } catch (Exception $e) {
            echo "✗ {$tableName}: ERROR - " . $e->getMessage() . "\n";
        }
    }
    
    echo "\nTotal backalerts records: " . number_format($totalBackAlertRecords) . "\n";
    
    // Also check alerts for comparison
    echo "\n=== ALERTS PARTITION SUMMARY ===\n";
    $alertTables = DB::connection('pgsql')->select(
        "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'alerts_%' ORDER BY tablename"
    );
    
    $totalAlertRecords = 0;
    foreach ($alertTables as $tableObj) {
        $tableName = $tableObj->tablename;
        try {
            $count = DB::connection('pgsql')->select("SELECT COUNT(*) as count FROM {$tableName}")[0]->count;
            $totalAlertRecords += $count;
        } catch (Exception $e) {
            // Skip errors
        }
    }
    
    echo "Total alerts records: " . number_format($totalAlertRecords) . "\n";
    echo "Total combined (alerts + backalerts): " . number_format($totalAlertRecords + $totalBackAlertRecords) . "\n";
    
    echo "\n=== PARTITION INTERFACE REQUIREMENTS ===\n";
    echo "The partition web interface should show:\n";
    echo "1. Alerts partition tables with counts\n";
    echo "2. Backalerts partition tables with counts\n";
    echo "3. Combined totals for each date\n";
    echo "4. Separate sections or combined view\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}