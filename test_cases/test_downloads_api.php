<?php

/**
 * Test script for Downloads API
 * 
 * Tests the new downloads partition endpoint
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\PartitionRegistry;
use Illuminate\Support\Facades\DB;

echo "=== Testing Downloads API ===\n\n";

// Test 1: Check PartitionRegistry data
echo "Test 1: Checking PartitionRegistry data...\n";
$totalPartitions = PartitionRegistry::count();
echo "Total partitions in registry: {$totalPartitions}\n";

$alertsPartitions = PartitionRegistry::where('table_type', 'alerts')->count();
$backalertsPartitions = PartitionRegistry::where('table_type', 'backalerts')->count();
echo "Alerts partitions: {$alertsPartitions}\n";
echo "Backalerts partitions: {$backalertsPartitions}\n\n";

// Test 2: Get combined stats for recent dates
echo "Test 2: Getting combined stats for recent dates...\n";
$recentStats = PartitionRegistry::getAllCombinedStats()->take(5);

foreach ($recentStats as $stats) {
    echo "Date: {$stats['date']}\n";
    echo "  Alerts: " . number_format($stats['alerts_count']) . "\n";
    echo "  Backalerts: " . number_format($stats['backalerts_count']) . "\n";
    echo "  Total: " . number_format($stats['total_count']) . "\n";
    echo "  Alerts Table: {$stats['alerts_table']}\n";
    echo "  Backalerts Table: {$stats['backalerts_table']}\n";
    
    // Calculate batches
    $batchSize = 600000;
    $batches = ceil($stats['total_count'] / $batchSize);
    if ($batches > 1) {
        echo "  Batches needed: {$batches}\n";
    }
    echo "\n";
}

// Test 3: Simulate API response for all-alerts
echo "Test 3: Simulating API response for all-alerts...\n";
$allAlertsData = PartitionRegistry::getAllCombinedStats()
    ->map(function ($stats) {
        return [
            'date' => $stats['date'],
            'records' => $stats['total_count'],
            'alerts_table' => $stats['alerts_table'],
            'backalerts_table' => $stats['backalerts_table'],
            'alerts_count' => $stats['alerts_count'],
            'backalerts_count' => $stats['backalerts_count'],
        ];
    })
    ->filter(function ($partition) {
        return $partition['records'] > 0;
    })
    ->take(3);

echo "Sample all-alerts response:\n";
echo json_encode($allAlertsData->values(), JSON_PRETTY_PRINT) . "\n\n";

// Test 4: Simulate API response for vm-alerts
echo "Test 4: Simulating API response for vm-alerts...\n";
$vmAlertsData = PartitionRegistry::getAllCombinedStats()
    ->map(function ($stats) {
        return [
            'date' => $stats['date'],
            'records' => $stats['backalerts_count'],
            'alerts_table' => $stats['alerts_table'],
            'backalerts_table' => $stats['backalerts_table'],
            'alerts_count' => $stats['alerts_count'],
            'backalerts_count' => $stats['backalerts_count'],
        ];
    })
    ->filter(function ($partition) {
        return $partition['records'] > 0;
    })
    ->take(3);

echo "Sample vm-alerts response:\n";
echo json_encode($vmAlertsData->values(), JSON_PRETTY_PRINT) . "\n\n";

// Test 5: Check if export endpoints exist
echo "Test 5: Checking export endpoint routes...\n";
$routes = app('router')->getRoutes();
$exportRoutes = [];

foreach ($routes as $route) {
    $uri = $route->uri();
    if (strpos($uri, 'export/csv') !== false) {
        $exportRoutes[] = $uri;
    }
}

echo "Found export routes:\n";
foreach ($exportRoutes as $route) {
    echo "  - {$route}\n";
}
echo "\n";

echo "=== All Tests Completed ===\n";
