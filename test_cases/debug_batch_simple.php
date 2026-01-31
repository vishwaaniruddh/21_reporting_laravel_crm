<?php

/**
 * Simple Debug - Check if offset works correctly
 */

require_once 'vendor/autoload.php';
ini_set('memory_limit', '512M');

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\PartitionQueryRouter;
use Carbon\Carbon;

echo "=== Simple Batch Test ===\n\n";

$date = Carbon::parse('2026-01-30');
$startDate = $date->copy()->startOfDay();
$endDate = $date->copy()->endOfDay();

$router = new PartitionQueryRouter();

// Test with small limits
$testCases = [
    ['offset' => 0, 'limit' => 100],
    ['offset' => 100, 'limit' => 100],
    ['offset' => 1000, 'limit' => 100],
    ['offset' => 10000, 'limit' => 100],
    ['offset' => 100000, 'limit' => 100],
    ['offset' => 500000, 'limit' => 100],
    ['offset' => 940980, 'limit' => 100], // Batch 3 offset
    ['offset' => 1411470, 'limit' => 100], // Batch 4 offset
    ['offset' => 1800000, 'limit' => 100],
];

foreach ($testCases as $test) {
    $offset = $test['offset'];
    $limit = $test['limit'];
    
    $options = [
        'limit' => $limit,
        'offset' => $offset,
        'order_by' => 'id',
        'order_direction' => 'DESC',
    ];
    
    $results = $router->queryDateRange($startDate, $endDate, [], $options, ['alerts', 'backalerts']);
    
    echo "Offset: " . number_format($offset) . ", Limit: {$limit} => Records: {$results->count()}";
    
    if ($results->count() > 0) {
        $first = $results->first();
        echo " (First ID: {$first->id})";
    }
    echo "\n";
}

echo "\n=== Test Complete ===\n";
