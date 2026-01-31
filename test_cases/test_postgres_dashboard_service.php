<?php

/**
 * Comprehensive test script for PostgresDashboardService
 * 
 * Tests all service methods including:
 * - Shift calculation with different times
 * - Shift time range generation
 * - Partition table selection
 * - Alert count aggregation
 * - Username enrichment
 * - Grand total calculation
 * - Complete getAlertDistribution flow
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Services\PostgresDashboardService;
use Carbon\Carbon;

// Bootstrap Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "\n";
echo str_repeat("=", 80) . "\n";
echo "PostgresDashboardService - Comprehensive Test Suite\n";
echo str_repeat("=", 80) . "\n\n";

$allTestsPassed = true;
$testResults = [];

/**
 * Helper function to run a test and track results
 */
function runTest(string $testName, callable $testFunction, &$allTestsPassed, &$testResults): void
{
    echo "Running: {$testName}\n";
    echo str_repeat("-", 80) . "\n";
    
    try {
        $result = $testFunction();
        
        if ($result === true || $result === null) {
            echo "✓ PASSED\n\n";
            $testResults[$testName] = 'PASSED';
        } else {
            echo "✗ FAILED: {$result}\n\n";
            $testResults[$testName] = 'FAILED';
            $allTestsPassed = false;
        }
    } catch (Exception $e) {
        echo "✗ FAILED with exception: {$e->getMessage()}\n";
        echo "Stack trace:\n{$e->getTraceAsString()}\n\n";
        $testResults[$testName] = 'FAILED';
        $allTestsPassed = false;
    }
}

// Initialize service
$service = new PostgresDashboardService();

// ============================================================================
// Test 1: Shift Calculation - Test all time ranges
// ============================================================================
runTest("Test 1: Shift Calculation - Shift 1 (07:00-14:59)", function() use ($service) {
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('getCurrentShift');
    
    // Test boundary times for Shift 1
    $testTimes = [
        '07:00' => 1,
        '07:01' => 1,
        '10:30' => 1,
        '14:58' => 1,
        '14:59' => 1,
    ];
    
    foreach ($testTimes as $time => $expectedShift) {
        Carbon::setTestNow(Carbon::parse("2026-01-12 {$time}:00"));
        $actualShift = $method->invoke($service);
        
        if ($actualShift !== $expectedShift) {
            return "Time {$time} returned shift {$actualShift}, expected {$expectedShift}";
        }
        
        echo "  {$time} → Shift {$actualShift} ✓\n";
    }
    
    Carbon::setTestNow(); // Reset
    return true;
}, $allTestsPassed, $testResults);

runTest("Test 2: Shift Calculation - Shift 2 (15:00-22:59)", function() use ($service) {
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('getCurrentShift');
    
    // Test boundary times for Shift 2
    $testTimes = [
        '15:00' => 2,
        '15:01' => 2,
        '18:30' => 2,
        '22:58' => 2,
        '22:59' => 2,
    ];
    
    foreach ($testTimes as $time => $expectedShift) {
        Carbon::setTestNow(Carbon::parse("2026-01-12 {$time}:00"));
        $actualShift = $method->invoke($service);
        
        if ($actualShift !== $expectedShift) {
            return "Time {$time} returned shift {$actualShift}, expected {$expectedShift}";
        }
        
        echo "  {$time} → Shift {$actualShift} ✓\n";
    }
    
    Carbon::setTestNow(); // Reset
    return true;
}, $allTestsPassed, $testResults);

runTest("Test 3: Shift Calculation - Shift 3 (23:00-06:59)", function() use ($service) {
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('getCurrentShift');
    
    // Test boundary times for Shift 3
    $testTimes = [
        '23:00' => 3,
        '23:01' => 3,
        '23:59' => 3,
        '00:00' => 3,
        '00:01' => 3,
        '03:30' => 3,
        '06:58' => 3,
        '06:59' => 3,
    ];
    
    foreach ($testTimes as $time => $expectedShift) {
        Carbon::setTestNow(Carbon::parse("2026-01-12 {$time}:00"));
        $actualShift = $method->invoke($service);
        
        if ($actualShift !== $expectedShift) {
            return "Time {$time} returned shift {$actualShift}, expected {$expectedShift}";
        }
        
        echo "  {$time} → Shift {$actualShift} ✓\n";
    }
    
    Carbon::setTestNow(); // Reset
    return true;
}, $allTestsPassed, $testResults);

// ============================================================================
// Test 4: Shift Time Range Generation
// ============================================================================
runTest("Test 4: Shift Time Range - Shift 1", function() use ($service) {
    Carbon::setTestNow(Carbon::parse("2026-01-12 10:00:00"));
    
    $timeRange = $service->getShiftTimeRange(1);
    
    $expectedStart = "2026-01-12 07:00:00";
    $expectedEnd = "2026-01-12 14:59:59";
    
    $actualStart = $timeRange['start']->toDateTimeString();
    $actualEnd = $timeRange['end']->toDateTimeString();
    
    echo "  Start: {$actualStart}\n";
    echo "  End:   {$actualEnd}\n";
    
    if ($actualStart !== $expectedStart) {
        return "Start time mismatch: got {$actualStart}, expected {$expectedStart}";
    }
    
    if ($actualEnd !== $expectedEnd) {
        return "End time mismatch: got {$actualEnd}, expected {$expectedEnd}";
    }
    
    Carbon::setTestNow(); // Reset
    return true;
}, $allTestsPassed, $testResults);

runTest("Test 5: Shift Time Range - Shift 2", function() use ($service) {
    Carbon::setTestNow(Carbon::parse("2026-01-12 18:00:00"));
    
    $timeRange = $service->getShiftTimeRange(2);
    
    $expectedStart = "2026-01-12 15:00:00";
    $expectedEnd = "2026-01-12 22:59:59";
    
    $actualStart = $timeRange['start']->toDateTimeString();
    $actualEnd = $timeRange['end']->toDateTimeString();
    
    echo "  Start: {$actualStart}\n";
    echo "  End:   {$actualEnd}\n";
    
    if ($actualStart !== $expectedStart) {
        return "Start time mismatch: got {$actualStart}, expected {$expectedStart}";
    }
    
    if ($actualEnd !== $expectedEnd) {
        return "End time mismatch: got {$actualEnd}, expected {$expectedEnd}";
    }
    
    Carbon::setTestNow(); // Reset
    return true;
}, $allTestsPassed, $testResults);

runTest("Test 6: Shift Time Range - Shift 3 (evening portion)", function() use ($service) {
    Carbon::setTestNow(Carbon::parse("2026-01-12 23:30:00"));
    
    $timeRange = $service->getShiftTimeRange(3);
    
    $expectedStart = "2026-01-12 23:00:00";
    $expectedEnd = "2026-01-13 06:59:59"; // Next day
    
    $actualStart = $timeRange['start']->toDateTimeString();
    $actualEnd = $timeRange['end']->toDateTimeString();
    
    echo "  Start: {$actualStart}\n";
    echo "  End:   {$actualEnd}\n";
    
    if ($actualStart !== $expectedStart) {
        return "Start time mismatch: got {$actualStart}, expected {$expectedStart}";
    }
    
    if ($actualEnd !== $expectedEnd) {
        return "End time mismatch: got {$actualEnd}, expected {$expectedEnd}";
    }
    
    Carbon::setTestNow(); // Reset
    return true;
}, $allTestsPassed, $testResults);

runTest("Test 7: Shift Time Range - Shift 3 (morning portion)", function() use ($service) {
    Carbon::setTestNow(Carbon::parse("2026-01-12 03:30:00"));
    
    $timeRange = $service->getShiftTimeRange(3);
    
    $expectedStart = "2026-01-11 23:00:00"; // Previous day
    $expectedEnd = "2026-01-12 06:59:59";
    
    $actualStart = $timeRange['start']->toDateTimeString();
    $actualEnd = $timeRange['end']->toDateTimeString();
    
    echo "  Start: {$actualStart}\n";
    echo "  End:   {$actualEnd}\n";
    
    if ($actualStart !== $expectedStart) {
        return "Start time mismatch: got {$actualStart}, expected {$expectedStart}";
    }
    
    if ($actualEnd !== $expectedEnd) {
        return "End time mismatch: got {$actualEnd}, expected {$expectedEnd}";
    }
    
    Carbon::setTestNow(); // Reset
    return true;
}, $allTestsPassed, $testResults);

// ============================================================================
// Test 8: Partition Table Selection
// ============================================================================
runTest("Test 8: Partition Table Selection - Shift 1 (single partition)", function() use ($service) {
    Carbon::setTestNow(Carbon::parse("2026-01-12 10:00:00"));
    
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('getPartitionTablesForShift');
    $method->setAccessible(true);
    
    $partitions = $method->invoke($service, 1);
    
    echo "  Partitions: " . implode(', ', $partitions) . "\n";
    echo "  Count: " . count($partitions) . "\n";
    
    if (count($partitions) !== 1) {
        return "Expected 1 partition for Shift 1, got " . count($partitions);
    }
    
    // Check partition name format
    if (!preg_match('/^alerts_\d{4}_\d{2}_\d{2}$/', $partitions[0])) {
        return "Partition name format incorrect: {$partitions[0]}";
    }
    
    Carbon::setTestNow(); // Reset
    return true;
}, $allTestsPassed, $testResults);

runTest("Test 9: Partition Table Selection - Shift 2 (single partition)", function() use ($service) {
    Carbon::setTestNow(Carbon::parse("2026-01-12 18:00:00"));
    
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('getPartitionTablesForShift');
    $method->setAccessible(true);
    
    $partitions = $method->invoke($service, 2);
    
    echo "  Partitions: " . implode(', ', $partitions) . "\n";
    echo "  Count: " . count($partitions) . "\n";
    
    if (count($partitions) !== 1) {
        return "Expected 1 partition for Shift 2, got " . count($partitions);
    }
    
    // Check partition name format
    if (!preg_match('/^alerts_\d{4}_\d{2}_\d{2}$/', $partitions[0])) {
        return "Partition name format incorrect: {$partitions[0]}";
    }
    
    Carbon::setTestNow(); // Reset
    return true;
}, $allTestsPassed, $testResults);

runTest("Test 10: Partition Table Selection - Shift 3 (two partitions)", function() use ($service) {
    Carbon::setTestNow(Carbon::parse("2026-01-12 23:30:00"));
    
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('getPartitionTablesForShift');
    $method->setAccessible(true);
    
    $partitions = $method->invoke($service, 3);
    
    echo "  Partitions: " . implode(', ', $partitions) . "\n";
    echo "  Count: " . count($partitions) . "\n";
    
    if (count($partitions) !== 2) {
        return "Expected 2 partitions for Shift 3, got " . count($partitions);
    }
    
    // Check partition name format for both
    foreach ($partitions as $partition) {
        if (!preg_match('/^alerts_\d{4}_\d{2}_\d{2}$/', $partition)) {
            return "Partition name format incorrect: {$partition}";
        }
    }
    
    // Verify they are consecutive dates
    $date1 = substr($partitions[0], -10);
    $date2 = substr($partitions[1], -10);
    
    echo "  Date 1: {$date1}\n";
    echo "  Date 2: {$date2}\n";
    
    $carbon1 = Carbon::createFromFormat('Y_m_d', $date1);
    $carbon2 = Carbon::createFromFormat('Y_m_d', $date2);
    
    $daysDiff = $carbon1->diffInDays($carbon2);
    echo "  Days difference: {$daysDiff}\n";
    
    // Verify the second date is exactly 1 day after the first
    if (!$carbon2->isSameDay($carbon1->copy()->addDay())) {
        return "Partitions should be consecutive dates";
    }
    
    Carbon::setTestNow(); // Reset
    return true;
}, $allTestsPassed, $testResults);

// ============================================================================
// Test 11: Grand Total Calculation
// ============================================================================
runTest("Test 11: Grand Total Calculation", function() use ($service) {
    // Create mock terminal data
    $terminalData = collect([
        (object)[
            'terminal' => '192.168.1.1',
            'username' => 'User One',
            'open' => 10,
            'close' => 5,
            'total' => 15,
            'criticalopen' => 2,
            'criticalClose' => 1,
            'totalCritical' => 3
        ],
        (object)[
            'terminal' => '192.168.1.2',
            'username' => 'User Two',
            'open' => 20,
            'close' => 10,
            'total' => 30,
            'criticalopen' => 3,
            'criticalClose' => 2,
            'totalCritical' => 5
        ],
        (object)[
            'terminal' => '192.168.1.3',
            'username' => 'User Three',
            'open' => 15,
            'close' => 8,
            'total' => 23,
            'criticalopen' => 1,
            'criticalClose' => 1,
            'totalCritical' => 2
        ]
    ]);
    
    $grandTotals = $service->calculateGrandTotals($terminalData);
    
    echo "  Grand Total Open: {$grandTotals['grandtotalOpenAlerts']}\n";
    echo "  Grand Total Close: {$grandTotals['grandtotalCloseAlerts']}\n";
    echo "  Grand Total Alerts: {$grandTotals['grandtotalAlerts']}\n";
    echo "  Grand Total Critical Open: {$grandTotals['grandtoalCriticalOpen']}\n";
    echo "  Grand Total Critical Close: {$grandTotals['grandtotalCloseCriticalAlert']}\n";
    echo "  Grand Total Critical: {$grandTotals['grandtotalCritical']}\n";
    
    // Verify calculations
    $expectedOpen = 10 + 20 + 15;
    $expectedClose = 5 + 10 + 8;
    $expectedTotal = 15 + 30 + 23;
    $expectedCriticalOpen = 2 + 3 + 1;
    $expectedCriticalClose = 1 + 2 + 1;
    $expectedCriticalTotal = 3 + 5 + 2;
    
    if ($grandTotals['grandtotalOpenAlerts'] !== $expectedOpen) {
        return "Open alerts mismatch: got {$grandTotals['grandtotalOpenAlerts']}, expected {$expectedOpen}";
    }
    
    if ($grandTotals['grandtotalCloseAlerts'] !== $expectedClose) {
        return "Close alerts mismatch: got {$grandTotals['grandtotalCloseAlerts']}, expected {$expectedClose}";
    }
    
    if ($grandTotals['grandtotalAlerts'] !== $expectedTotal) {
        return "Total alerts mismatch: got {$grandTotals['grandtotalAlerts']}, expected {$expectedTotal}";
    }
    
    if ($grandTotals['grandtoalCriticalOpen'] !== $expectedCriticalOpen) {
        return "Critical open mismatch: got {$grandTotals['grandtoalCriticalOpen']}, expected {$expectedCriticalOpen}";
    }
    
    if ($grandTotals['grandtotalCloseCriticalAlert'] !== $expectedCriticalClose) {
        return "Critical close mismatch: got {$grandTotals['grandtotalCloseCriticalAlert']}, expected {$expectedCriticalClose}";
    }
    
    if ($grandTotals['grandtotalCritical'] !== $expectedCriticalTotal) {
        return "Total critical mismatch: got {$grandTotals['grandtotalCritical']}, expected {$expectedCriticalTotal}";
    }
    
    return true;
}, $allTestsPassed, $testResults);

// ============================================================================
// Test 12: Complete getAlertDistribution (with real database)
// ============================================================================
runTest("Test 12: Complete getAlertDistribution Flow", function() use ($service) {
    echo "  Testing complete dashboard data retrieval...\n";
    
    // Use current shift
    $result = $service->getAlertDistribution();
    
    echo "  Shift: {$result['shift']}\n";
    echo "  Time Range: {$result['shift_time_range']['start']} to {$result['shift_time_range']['end']}\n";
    echo "  Terminal Count: " . count($result['data']) . "\n";
    echo "  Grand Total Alerts: {$result['grandtotalAlerts']}\n";
    
    // Verify structure
    if (!isset($result['data'])) {
        return "Missing 'data' key in result";
    }
    
    if (!isset($result['shift'])) {
        return "Missing 'shift' key in result";
    }
    
    if (!isset($result['shift_time_range'])) {
        return "Missing 'shift_time_range' key in result";
    }
    
    if (!isset($result['grandtotalAlerts'])) {
        return "Missing 'grandtotalAlerts' key in result";
    }
    
    // Verify shift is valid
    if (!in_array($result['shift'], [1, 2, 3])) {
        return "Invalid shift number: {$result['shift']}";
    }
    
    // If we have data, verify structure
    if (count($result['data']) > 0) {
        $firstTerminal = $result['data'][0];
        
        $requiredFields = ['terminal', 'username', 'open', 'close', 'total', 
                          'criticalopen', 'criticalClose', 'totalCritical'];
        
        foreach ($requiredFields as $field) {
            if (!property_exists($firstTerminal, $field)) {
                return "Missing field '{$field}' in terminal data";
            }
        }
        
        echo "\n  Sample terminal data:\n";
        echo "    Terminal: {$firstTerminal->terminal}\n";
        echo "    Username: " . ($firstTerminal->username ?? 'NULL') . "\n";
        echo "    Open: {$firstTerminal->open}\n";
        echo "    Close: {$firstTerminal->close}\n";
        echo "    Total: {$firstTerminal->total}\n";
        echo "    Critical Open: {$firstTerminal->criticalopen}\n";
        echo "    Critical Close: {$firstTerminal->criticalClose}\n";
        echo "    Total Critical: {$firstTerminal->totalCritical}\n";
    } else {
        echo "\n  Note: No terminal data found for current shift\n";
        echo "  This is normal if no alerts exist in the current shift time range\n";
    }
    
    return true;
}, $allTestsPassed, $testResults);

// ============================================================================
// Test 13: Test with specific shift parameter
// ============================================================================
runTest("Test 13: getAlertDistribution with Shift Parameter", function() use ($service) {
    echo "  Testing with explicit shift parameter (Shift 1)...\n";
    
    $result = $service->getAlertDistribution(1);
    
    if ($result['shift'] !== 1) {
        return "Expected shift 1, got {$result['shift']}";
    }
    
    echo "  Shift: {$result['shift']}\n";
    echo "  Time Range: {$result['shift_time_range']['start']} to {$result['shift_time_range']['end']}\n";
    echo "  Terminal Count: " . count($result['data']) . "\n";
    
    return true;
}, $allTestsPassed, $testResults);

// ============================================================================
// Test 14: Invalid shift parameter handling
// ============================================================================
runTest("Test 14: Invalid Shift Parameter Handling", function() use ($service) {
    echo "  Testing invalid shift parameter (should throw exception)...\n";
    
    try {
        $service->getAlertDistribution(99);
        return "Should have thrown exception for invalid shift";
    } catch (InvalidArgumentException $e) {
        echo "  Exception caught as expected: {$e->getMessage()}\n";
        return true;
    }
}, $allTestsPassed, $testResults);

// ============================================================================
// Test 15: getAlertDetails method
// ============================================================================
runTest("Test 15: getAlertDetails - Basic Functionality", function() use ($service) {
    echo "  Testing getAlertDetails method...\n";
    
    // First, get current dashboard data to find a terminal with alerts
    $dashboardData = $service->getAlertDistribution();
    
    if (count($dashboardData['data']) === 0) {
        echo "  Note: No terminals with alerts found in current shift\n";
        echo "  Skipping detailed test (this is normal if no alerts exist)\n";
        return true;
    }
    
    // Get first terminal with open alerts
    $testTerminal = null;
    foreach ($dashboardData['data'] as $terminal) {
        if ($terminal->open > 0) {
            $testTerminal = $terminal;
            break;
        }
    }
    
    if (!$testTerminal) {
        echo "  Note: No terminals with open alerts found\n";
        echo "  Skipping detailed test\n";
        return true;
    }
    
    echo "  Testing with terminal: {$testTerminal->terminal}\n";
    echo "  Terminal has {$testTerminal->open} open alerts\n";
    
    // Get alert details for this terminal
    $details = $service->getAlertDetails(
        $testTerminal->terminal,
        'open',
        $dashboardData['shift']
    );
    
    echo "  Retrieved {$details->count()} alert details\n";
    
    // Verify we got results
    if ($details->isEmpty()) {
        return "Expected alert details but got empty collection";
    }
    
    // Verify structure of first alert
    $firstAlert = $details->first();
    
    $requiredFields = ['id', 'panelid', 'receivedtime', 'alerttype', 
                      'comment', 'closedBy', 'closedtime', 'ATMID', 'Zone', 'City'];
    
    foreach ($requiredFields as $field) {
        if (!property_exists($firstAlert, $field)) {
            return "Missing field '{$field}' in alert details";
        }
    }
    
    echo "\n  Sample alert detail:\n";
    echo "    ID: {$firstAlert->id}\n";
    echo "    Panel ID: {$firstAlert->panelid}\n";
    echo "    ATMID: " . ($firstAlert->ATMID ?? 'NULL') . "\n";
    echo "    Zone: " . ($firstAlert->Zone ?? 'NULL') . "\n";
    echo "    City: " . ($firstAlert->City ?? 'NULL') . "\n";
    echo "    Received Time: {$firstAlert->receivedtime}\n";
    echo "    Alert Type: {$firstAlert->alerttype}\n";
    
    return true;
}, $allTestsPassed, $testResults);

// ============================================================================
// Test 16: getAlertDetails with different status types
// ============================================================================
runTest("Test 16: getAlertDetails - Different Status Types", function() use ($service) {
    echo "  Testing getAlertDetails with different status types...\n";
    
    $dashboardData = $service->getAlertDistribution();
    
    if (count($dashboardData['data']) === 0) {
        echo "  Note: No terminals found, skipping test\n";
        return true;
    }
    
    $testTerminal = $dashboardData['data'][0]->terminal;
    $shift = $dashboardData['shift'];
    
    // Test different status types
    $statusTypes = ['open', 'close', 'total', 'criticalopen', 'criticalClose', 'totalCritical'];
    
    foreach ($statusTypes as $status) {
        try {
            $details = $service->getAlertDetails($testTerminal, $status, $shift);
            echo "    Status '{$status}': {$details->count()} alerts\n";
        } catch (Exception $e) {
            return "Failed for status '{$status}': {$e->getMessage()}";
        }
    }
    
    return true;
}, $allTestsPassed, $testResults);

// ============================================================================
// Test 17: getAlertDetails with invalid shift
// ============================================================================
runTest("Test 17: getAlertDetails - Invalid Shift Parameter", function() use ($service) {
    echo "  Testing getAlertDetails with invalid shift (should throw exception)...\n";
    
    try {
        $service->getAlertDetails('192.168.1.1', 'open', 99);
        return "Should have thrown exception for invalid shift";
    } catch (InvalidArgumentException $e) {
        echo "  Exception caught as expected: {$e->getMessage()}\n";
        return true;
    }
}, $allTestsPassed, $testResults);

// ============================================================================
// Print Summary
// ============================================================================
echo "\n";
echo str_repeat("=", 80) . "\n";
echo "Test Summary\n";
echo str_repeat("=", 80) . "\n\n";

$passedCount = 0;
$failedCount = 0;

foreach ($testResults as $testName => $result) {
    $status = $result === 'PASSED' ? '✓' : '✗';
    echo "{$status} {$testName}: {$result}\n";
    
    if ($result === 'PASSED') {
        $passedCount++;
    } else {
        $failedCount++;
    }
}

echo "\n";
echo "Total Tests: " . count($testResults) . "\n";
echo "Passed: {$passedCount}\n";
echo "Failed: {$failedCount}\n";
echo "\n";

if ($allTestsPassed) {
    echo str_repeat("=", 80) . "\n";
    echo "✓ ALL TESTS PASSED\n";
    echo str_repeat("=", 80) . "\n";
    exit(0);
} else {
    echo str_repeat("=", 80) . "\n";
    echo "✗ SOME TESTS FAILED\n";
    echo str_repeat("=", 80) . "\n";
    exit(1);
}
