<?php

/**
 * Test script for PostgresDashboardService::queryPartitionsForCounts()
 * 
 * This script tests the alert count aggregation functionality.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Services\PostgresDashboardService;
use Carbon\Carbon;

// Bootstrap Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Testing PostgresDashboardService::queryPartitionsForCounts()\n";
echo str_repeat("=", 70) . "\n\n";

try {
    $service = new PostgresDashboardService();
    
    // Test 1: Get current shift
    echo "Test 1: Get current shift\n";
    $currentShift = $service->getCurrentShift();
    echo "Current shift: {$currentShift}\n\n";
    
    // Test 2: Get shift time range
    echo "Test 2: Get shift time range\n";
    $timeRange = $service->getShiftTimeRange($currentShift);
    echo "Start: {$timeRange['start']->toDateTimeString()}\n";
    echo "End: {$timeRange['end']->toDateTimeString()}\n\n";
    
    // Test 3: Test queryPartitionsForCounts with reflection (since it's private)
    echo "Test 3: Query partitions for counts (using reflection)\n";
    
    $reflection = new ReflectionClass($service);
    
    // Get partition tables for shift
    $getPartitionsMethod = $reflection->getMethod('getPartitionTablesForShift');
    $getPartitionsMethod->setAccessible(true);
    $partitionTables = $getPartitionsMethod->invoke($service, $currentShift);
    
    echo "Partition tables for shift {$currentShift}: " . implode(', ', $partitionTables) . "\n";
    
    // Query partitions for counts
    $queryMethod = $reflection->getMethod('queryPartitionsForCounts');
    $queryMethod->setAccessible(true);
    
    $results = $queryMethod->invoke(
        $service,
        $partitionTables,
        $timeRange['start'],
        $timeRange['end']
    );
    
    echo "Query returned " . $results->count() . " result rows\n";
    
    if ($results->count() > 0) {
        echo "\nSample results (first 5 rows):\n";
        echo str_repeat("-", 70) . "\n";
        printf("%-20s %-10s %-15s %-10s\n", "Terminal", "Status", "Critical", "Count");
        echo str_repeat("-", 70) . "\n";
        
        foreach ($results->take(5) as $row) {
            printf(
                "%-20s %-10s %-15s %-10s\n",
                $row->terminal ?? 'NULL',
                $row->status ?? 'NULL',
                $row->critical_alerts ?? 'NULL',
                $row->total_count ?? '0'
            );
        }
    } else {
        echo "\nNo results found. This could mean:\n";
        echo "- Partition tables don't exist yet\n";
        echo "- No alerts in the current shift time range\n";
        echo "- Database connection issue\n";
    }
    
    echo "\n" . str_repeat("=", 70) . "\n";
    echo "✓ Test completed successfully\n";
    
} catch (Exception $e) {
    echo "\n" . str_repeat("=", 70) . "\n";
    echo "✗ Test failed with error:\n";
    echo $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
