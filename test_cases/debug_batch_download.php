<?php

/**
 * Debug Batch Download Issue
 * 
 * Check why batches 3 and 4 only return headers for 2026-01-30
 */

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\PartitionQueryRouter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

echo "=== Debug Batch Download Issue ===\n\n";

$date = Carbon::parse('2026-01-30');
$startDate = $date->copy()->startOfDay();
$endDate = $date->copy()->endOfDay();

$router = new PartitionQueryRouter();

// Test 1: Get total count
echo "Test 1: Total Count\n";
$totalCount = $router->countDateRange(
    $startDate,
    $endDate,
    [],
    ['alerts', 'backalerts']
);
echo "Total records: " . number_format($totalCount) . "\n\n";

// Test 2: Check individual partition counts
echo "Test 2: Individual Partition Counts\n";
$alertsTable = 'alerts_2026_01_30';
$backalertsTable = 'backalerts_2026_01_30';

$alertsCount = DB::connection('pgsql')->table($alertsTable)->count();
$backalertsCount = DB::connection('pgsql')->table($backalertsTable)->count();

echo "alerts_2026_01_30: " . number_format($alertsCount) . "\n";
echo "backalerts_2026_01_30: " . number_format($backalertsCount) . "\n";
echo "Sum: " . number_format($alertsCount + $backalertsCount) . "\n\n";

// Test 3: Test each batch offset
echo "Test 3: Testing Batch Offsets\n";
$batchSize = 470490;

for ($batch = 1; $batch <= 4; $batch++) {
    $offset = ($batch - 1) * $batchSize;
    $limit = $batchSize;
    
    echo "Batch {$batch}: offset={$offset}, limit={$limit}\n";
    
    $options = [
        'limit' => $limit,
        'offset' => $offset,
        'order_by' => 'id',
        'order_direction' => 'DESC',
    ];
    
    $results = $router->queryDateRange($startDate, $endDate, [], $options, ['alerts', 'backalerts']);
    
    echo "  Records returned: " . number_format($results->count()) . "\n";
    
    if ($results->count() > 0) {
        $first = $results->first();
        $last = $results->last();
        echo "  First ID: {$first->id}, Last ID: {$last->id}\n";
    } else {
        echo "  NO RECORDS RETURNED!\n";
    }
    echo "\n";
}

// Test 4: Check if ORDER BY DESC is causing issues
echo "Test 4: Testing with Different Order\n";
$offset = 940980; // Batch 3 offset
$limit = 1000;

echo "With ORDER BY id DESC:\n";
$options = [
    'limit' => $limit,
    'offset' => $offset,
    'order_by' => 'id',
    'order_direction' => 'DESC',
];
$results = $router->queryDateRange($startDate, $endDate, [], $options, ['alerts', 'backalerts']);
echo "  Records: " . $results->count() . "\n\n";

echo "With ORDER BY id ASC:\n";
$options = [
    'limit' => $limit,
    'offset' => $offset,
    'order_by' => 'id',
    'order_direction' => 'ASC',
];
$results = $router->queryDateRange($startDate, $endDate, [], $options, ['alerts', 'backalerts']);
echo "  Records: " . $results->count() . "\n\n";

// Test 5: Direct SQL query to verify
echo "Test 5: Direct SQL Query\n";
$sql = "
SELECT COUNT(*) as total FROM (
    SELECT * FROM alerts_2026_01_30
    UNION ALL
    SELECT 
        id, panelid, seqno, zone, alarm, createtime, receivedtime, comment, status, sendtoclient,
        closedby as \"closedBy\", closedtime, sendip, alerttype, location, priority,
        alertuserstatus as \"AlertUserStatus\", level::varchar as level, sip2, c_status,
        auto_alert::varchar as auto_alert, critical_alerts, NULL as \"Readstatus\",
        synced_at, sync_batch_id::bigint as sync_batch_id
    FROM backalerts_2026_01_30
) AS combined_results
";

$result = DB::connection('pgsql')->select($sql);
echo "Direct count: " . number_format($result[0]->total) . "\n\n";

echo "=== Analysis Complete ===\n";
