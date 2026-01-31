<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\PartitionRegistry;

echo "=== REFRESHING PARTITION REGISTRY COUNTS ===\n\n";

try {
    // Get all partition tables from PostgreSQL
    $partitionTables = DB::connection('pgsql')->select(
        "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'alerts_%' ORDER BY tablename"
    );
    
    $totalUpdated = 0;
    $totalRecords = 0;
    
    echo "Found " . count($partitionTables) . " partition tables to check:\n\n";
    
    foreach ($partitionTables as $tableObj) {
        $tableName = $tableObj->tablename;
        
        try {
            // Get actual record count from the table
            $actualCount = DB::connection('pgsql')->select("SELECT COUNT(*) as count FROM {$tableName}")[0]->count;
            
            // Get current registry entry
            $registryEntry = PartitionRegistry::where('table_name', $tableName)->first();
            
            if ($registryEntry) {
                $oldCount = $registryEntry->record_count;
                
                // Update the registry with actual count
                $registryEntry->record_count = $actualCount;
                $registryEntry->last_synced_at = now();
                $registryEntry->save();
                
                $difference = $actualCount - $oldCount;
                $status = $difference == 0 ? '✓' : ($difference > 0 ? '↑' : '↓');
                
                echo "{$status} {$tableName}: {$oldCount} → " . number_format($actualCount);
                if ($difference != 0) {
                    echo " (+" . number_format($difference) . ")";
                }
                echo "\n";
                
                $totalUpdated++;
            } else {
                // Create new registry entry if it doesn't exist
                $dateStr = str_replace('alerts_', '', $tableName);
                $partitionDate = \Carbon\Carbon::createFromFormat('Y_m_d', $dateStr);
                
                PartitionRegistry::create([
                    'table_name' => $tableName,
                    'partition_date' => $partitionDate,
                    'record_count' => $actualCount,
                    'last_synced_at' => now(),
                ]);
                
                echo "✓ {$tableName}: NEW → " . number_format($actualCount) . " (created registry entry)\n";
                $totalUpdated++;
            }
            
            $totalRecords += $actualCount;
            
        } catch (Exception $e) {
            echo "✗ {$tableName}: ERROR - " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n=== SUMMARY ===\n";
    echo "✅ Updated {$totalUpdated} partition registry entries\n";
    echo "📊 Total records across all partitions: " . number_format($totalRecords) . "\n";
    echo "🕒 Registry last updated: " . now()->toDateTimeString() . "\n";
    
    // Verify the web interface will now show correct data
    echo "\n=== VERIFICATION ===\n";
    echo "The partition web interface at http://192.168.100.21:9000/partitions should now show:\n";
    
    $recentPartitions = PartitionRegistry::where('partition_date', '>=', now()->subDays(7)->toDateString())
        ->orderBy('partition_date', 'desc')
        ->limit(5)
        ->get();
    
    foreach ($recentPartitions as $partition) {
        echo "- {$partition->table_name}: " . number_format($partition->record_count) . " records\n";
    }
    
    echo "\n✅ Partition registry refresh completed successfully!\n";
    echo "🌐 Please refresh the web interface to see updated counts.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}