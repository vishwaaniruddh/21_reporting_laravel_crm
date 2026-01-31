<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\PartitionQueryRouter;
use Carbon\Carbon;

echo "=== VM Alerts Data Comparison: Before vs After ===\n\n";

try {
    $partitionRouter = new PartitionQueryRouter();
    $testDate = Carbon::parse('2026-01-27');
    
    // VM-specific filters
    $vmFilters = [
        'vm_status' => ['O', 'C'],  // Status IN ('O','C')
        'vm_sendtoclient' => 'S'    // sendtoclient = 'S'
    ];
    
    echo "Testing date: {$testDate->toDateString()}\n";
    echo "VM Filters: status IN ('O','C') AND sendtoclient = 'S'\n\n";
    
    // Test alerts only (before)
    echo "=== BEFORE: Alerts Only ===\n";
    $alertsOnly = $partitionRouter->queryWithPagination(
        $testDate->copy()->startOfDay(),
        $testDate->copy()->endOfDay(),
        $vmFilters,
        10,
        1,
        ['alerts'] // Only alerts tables
    );
    echo "- VM Alerts from alerts tables only: " . number_format($alertsOnly['pagination']['total']) . "\n\n";
    
    // Test backalerts only
    echo "=== BackAlerts Only ===\n";
    $backAlertsOnly = $partitionRouter->queryWithPagination(
        $testDate->copy()->startOfDay(),
        $testDate->copy()->endOfDay(),
        $vmFilters,
        10,
        1,
        ['backalerts'] // Only backalerts tables
    );
    echo "- VM Alerts from backalerts tables only: " . number_format($backAlertsOnly['pagination']['total']) . "\n\n";
    
    // Test combined (after)
    echo "=== AFTER: Combined Alerts + BackAlerts ===\n";
    $combined = $partitionRouter->queryWithPagination(
        $testDate->copy()->startOfDay(),
        $testDate->copy()->endOfDay(),
        $vmFilters,
        10,
        1,
        ['alerts', 'backalerts'] // Both table types
    );
    echo "- VM Alerts from combined tables: " . number_format($combined['pagination']['total']) . "\n\n";
    
    // Calculate improvement
    $alertsCount = $alertsOnly['pagination']['total'];
    $backAlertsCount = $backAlertsOnly['pagination']['total'];
    $combinedCount = $combined['pagination']['total'];
    $expectedTotal = $alertsCount + $backAlertsCount;
    
    echo "=== Summary ===\n";
    echo "- Alerts tables VM records: " . number_format($alertsCount) . "\n";
    echo "- BackAlerts tables VM records: " . number_format($backAlertsCount) . "\n";
    echo "- Expected combined total: " . number_format($expectedTotal) . "\n";
    echo "- Actual combined total: " . number_format($combinedCount) . "\n";
    
    if ($combinedCount == $expectedTotal) {
        echo "✅ Perfect match! Combined total equals sum of individual tables.\n";
    } else {
        echo "⚠️  Difference detected: " . number_format(abs($combinedCount - $expectedTotal)) . "\n";
    }
    
    $improvement = $backAlertsCount > 0 ? (($backAlertsCount / $alertsCount) * 100) : 0;
    echo "\n=== Impact ===\n";
    echo "- Data increase: +" . number_format($backAlertsCount) . " VM alerts\n";
    echo "- Percentage increase: +" . number_format($improvement, 1) . "%\n";
    echo "- Total VM alerts now available: " . number_format($combinedCount) . "\n";
    
    echo "\n✅ VM Alerts data comparison completed!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}