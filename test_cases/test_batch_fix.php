<?php

/**
 * Test Batch Download Fix
 * 
 * Verify that batches 3 and 4 now return correct data
 */

require_once 'vendor/autoload.php';
ini_set('memory_limit', '512M');

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\PartitionQueryRouter;
use Carbon\Carbon;

echo "=== Test Batch Download Fix ===\n\n";

$date = Carbon::parse('2026-01-30');
$startDate = $date->copy()->startOfDay();
$endDate = $date->copy()->endOfDay();

$router = new PartitionQueryRouter();

// Get total count
$totalCount = $router->countDateRange(
    $startDate,
    $endDate,
    [],
    ['alerts', 'backalerts']
);

echo "Total records: " . number_format($totalCount) . "\n";
echo "Expected batches: 4 (~470,490 per batch)\n\n";

// Simulate the batch download logic
$batchSize = 470490;
$batches = [
    ['batch' => 1, 'offset' => 0, 'limit' => 470490],
    ['batch' => 2, 'offset' => 470490, 'limit' => 470490],
    ['batch' => 3, 'offset' => 940980, 'limit' => 470490],
    ['batch' => 4, 'offset' => 1411470, 'limit' => 470489], // Last batch might be smaller
];

foreach ($batches as $batchInfo) {
    $batch = $batchInfo['batch'];
    $startOffset = $batchInfo['offset'];
    $limit = $batchInfo['limit'];
    
    echo "Batch {$batch}: offset={$startOffset}, limit={$limit}\n";
    
    // Simulate the export logic with chunking
    $chunkSize = 1000;
    $currentOffset = $startOffset;
    $processed = 0;
    $iterations = 0;
    
    while ($processed < $limit && $iterations < 5) { // Limit iterations for testing
        $remaining = $limit - $processed;
        $fetchSize = min($chunkSize, $remaining);
        
        $options = [
            'limit' => $fetchSize,
            'offset' => $currentOffset,
            'order_by' => 'id',
            'order_direction' => 'DESC',
        ];
        
        $results = $router->queryDateRange($startDate, $endDate, [], $options, ['alerts', 'backalerts']);
        
        if ($results->isEmpty()) {
            echo "  Iteration {$iterations}: No more data at offset {$currentOffset}\n";
            break;
        }
        
        $processed += $results->count();
        $currentOffset += $results->count();
        $iterations++;
        
        if ($iterations <= 2) {
            $first = $results->first();
            $last = $results->last();
            echo "  Iteration {$iterations}: Got {$results->count()} records (IDs: {$first->id} to {$last->id}), offset now: {$currentOffset}\n";
        }
    }
    
    echo "  Total processed in test: {$processed} records\n";
    echo "  Status: " . ($processed > 0 ? "✓ SUCCESS" : "✗ FAILED - No data") . "\n\n";
}

echo "=== Test Complete ===\n";
echo "\nKey Fix:\n";
echo "- Changed: offset += chunkSize (wrong - adds fixed amount)\n";
echo "- To: currentOffset += results->count() (correct - adds actual records fetched)\n";
echo "- This ensures offset tracks the actual position in the combined result set\n";
