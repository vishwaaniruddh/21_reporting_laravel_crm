<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\PartitionQueryRouter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

echo "=== Testing Combined Alerts + BackAlerts Reports ===\n\n";

try {
    // Initialize the partition router
    $partitionRouter = new PartitionQueryRouter();
    
    // Test date - use a date that has both alerts and backalerts data
    $testDate = Carbon::parse('2026-01-27');
    
    echo "Testing date: {$testDate->toDateString()}\n\n";
    
    // Check what partition tables exist for this date
    echo "=== Available Partition Tables ===\n";
    
    $alertsTable = "alerts_{$testDate->format('Y_m_d')}";
    $backAlertsTable = "backalerts_{$testDate->format('Y_m_d')}";
    
    $alertsExists = DB::connection('pgsql')->select(
        "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = ?)",
        [$alertsTable]
    )[0]->exists;
    
    $backAlertsExists = DB::connection('pgsql')->select(
        "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = ?)",
        [$backAlertsTable]
    )[0]->exists;
    
    echo "- {$alertsTable}: " . ($alertsExists ? "EXISTS" : "NOT FOUND") . "\n";
    echo "- {$backAlertsTable}: " . ($backAlertsExists ? "EXISTS" : "NOT FOUND") . "\n\n";
    
    if (!$alertsExists && !$backAlertsExists) {
        echo "❌ No partition tables found for test date. Exiting.\n";
        exit(1);
    }
    
    // Count records in each table
    if ($alertsExists) {
        $alertsCount = DB::connection('pgsql')->select("SELECT COUNT(*) as count FROM {$alertsTable}")[0]->count;
        echo "- {$alertsTable} records: {$alertsCount}\n";
    }
    
    if ($backAlertsExists) {
        $backAlertsCount = DB::connection('pgsql')->select("SELECT COUNT(*) as count FROM {$backAlertsTable}")[0]->count;
        echo "- {$backAlertsTable} records: {$backAlertsCount}\n";
    }
    
    echo "\n=== Testing Single Table Queries ===\n";
    
    // Test querying alerts only
    if ($alertsExists) {
        echo "Querying alerts only...\n";
        $alertsOnly = $partitionRouter->queryWithPagination(
            $testDate->copy()->startOfDay(),
            $testDate->copy()->endOfDay(),
            [],
            10,
            1,
            ['alerts']
        );
        echo "- Alerts only result count: " . count($alertsOnly['data']) . "\n";
        echo "- Alerts only total: " . $alertsOnly['pagination']['total'] . "\n";
    }
    
    // Test querying backalerts only
    if ($backAlertsExists) {
        echo "Querying backalerts only...\n";
        $backAlertsOnly = $partitionRouter->queryWithPagination(
            $testDate->copy()->startOfDay(),
            $testDate->copy()->endOfDay(),
            [],
            10,
            1,
            ['backalerts']
        );
        echo "- BackAlerts only result count: " . count($backAlertsOnly['data']) . "\n";
        echo "- BackAlerts only total: " . $backAlertsOnly['pagination']['total'] . "\n";
    }
    
    echo "\n=== Testing Combined Query ===\n";
    
    // Test querying both alerts and backalerts combined
    echo "Querying both alerts + backalerts combined...\n";
    $combined = $partitionRouter->queryWithPagination(
        $testDate->copy()->startOfDay(),
        $testDate->copy()->endOfDay(),
        [],
        10,
        1,
        ['alerts', 'backalerts']
    );
    
    echo "- Combined result count: " . count($combined['data']) . "\n";
    echo "- Combined total: " . $combined['pagination']['total'] . "\n";
    
    // Show sample records
    if (!empty($combined['data'])) {
        echo "\n=== Sample Combined Records ===\n";
        foreach (array_slice($combined['data'], 0, 3) as $i => $record) {
            echo "Record " . ($i + 1) . ":\n";
            echo "  - ID: " . ($record->id ?? 'N/A') . "\n";
            echo "  - Panel ID: " . ($record->panelid ?? 'N/A') . "\n";
            echo "  - Alert Type: " . ($record->alerttype ?? 'N/A') . "\n";
            echo "  - Received Time: " . ($record->receivedtime ?? 'N/A') . "\n";
            echo "  - Priority: " . ($record->priority ?? 'N/A') . "\n\n";
        }
    }
    
    echo "=== Testing Filters ===\n";
    
    // Test with panel ID filter
    if (!empty($combined['data'])) {
        $samplePanelId = $combined['data'][0]->panelid ?? null;
        if ($samplePanelId) {
            echo "Testing filter by panel ID: {$samplePanelId}\n";
            $filtered = $partitionRouter->queryWithPagination(
                $testDate->copy()->startOfDay(),
                $testDate->copy()->endOfDay(),
                ['panel_id' => $samplePanelId],
                5,
                1,
                ['alerts', 'backalerts']
            );
            echo "- Filtered result count: " . count($filtered['data']) . "\n";
            echo "- Filtered total: " . $filtered['pagination']['total'] . "\n";
        }
    }
    
    echo "\n✅ Combined alerts + backalerts query test completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}