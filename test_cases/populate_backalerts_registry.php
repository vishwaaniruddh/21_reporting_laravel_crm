<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\PartitionRegistry;
use Carbon\Carbon;

echo "=== POPULATING BACKALERTS PARTITION REGISTRY ===\n\n";

try {
    // Get all backalerts partition tables from PostgreSQL
    $backAlertTables = DB::connection('pgsql')->select(
        "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'backalerts_%' ORDER BY tablename"
    );
    
    echo "Found " . count($backAlertTables) . " backalerts partition tables to register:\n\n";
    
    $totalRegistered = 0;
    $totalRecords = 0;
    
    foreach ($backAlertTables as $tableObj) {
        $tableName = $tableObj->tablename;
        
        try {
            // Get actual record count from the table
            $actualCount = DB::connection('pgsql')->select("SELECT COUNT(*) as count FROM {$tableName}")[0]->count;
            
            // Extract date from table name (e.g., backalerts_2026_01_27 -> 2026-01-27)
            $dateStr = str_replace('backalerts_', '', $tableName);
            $partitionDate = Carbon::createFromFormat('Y_m_d', $dateStr);
            
            // Check if registry entry already exists
            $existingEntry = PartitionRegistry::where('table_name', $tableName)->first();
            
            if ($existingEntry) {
                // Update existing entry
                $existingEntry->record_count = $actualCount;
                $existingEntry->table_type = 'backalerts';
                $existingEntry->last_synced_at = now();
                $existingEntry->save();
                
                echo "✓ Updated {$tableName}: " . number_format($actualCount) . " records\n";
            } else {
                // Create new registry entry
                PartitionRegistry::create([
                    'table_name' => $tableName,
                    'partition_date' => $partitionDate,
                    'record_count' => $actualCount,
                    'table_type' => 'backalerts',
                    'last_synced_at' => now(),
                ]);
                
                echo "✓ Created {$tableName}: " . number_format($actualCount) . " records\n";
            }
            
            $totalRegistered++;
            $totalRecords += $actualCount;
            
        } catch (Exception $e) {
            echo "✗ {$tableName}: ERROR - " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n=== REGISTRY SUMMARY ===\n";
    echo "✅ Registered {$totalRegistered} backalerts partition tables\n";
    echo "📊 Total backalerts records: " . number_format($totalRecords) . "\n";
    
    // Get combined statistics
    $alertsCount = PartitionRegistry::getTotalRecordCountByType('alerts');
    $backalertsCount = PartitionRegistry::getTotalRecordCountByType('backalerts');
    $totalCombined = $alertsCount + $backalertsCount;
    
    echo "\n=== COMBINED STATISTICS ===\n";
    echo "📈 Alerts records: " . number_format($alertsCount) . "\n";
    echo "📈 Backalerts records: " . number_format($backalertsCount) . "\n";
    echo "📈 Total combined: " . number_format($totalCombined) . "\n";
    
    // Show sample combined stats for recent dates
    echo "\n=== SAMPLE COMBINED STATS BY DATE ===\n";
    $recentDates = ['2026-01-27', '2026-01-26', '2026-01-25'];
    
    foreach ($recentDates as $dateStr) {
        $date = Carbon::parse($dateStr);
        $stats = PartitionRegistry::getCombinedStatsForDate($date);
        
        echo "{$dateStr}:\n";
        echo "  - Alerts: " . number_format($stats['alerts_count']) . "\n";
        echo "  - Backalerts: " . number_format($stats['backalerts_count']) . "\n";
        echo "  - Total: " . number_format($stats['total_count']) . "\n\n";
    }
    
    echo "✅ Backalerts registry population completed successfully!\n";
    echo "🌐 The partition web interface now supports:\n";
    echo "   - Combined view (alerts + backalerts by date)\n";
    echo "   - Alerts-only view (?table_type=alerts)\n";
    echo "   - Backalerts-only view (?table_type=backalerts)\n";
    echo "   - Default combined view (?table_type=combined)\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}