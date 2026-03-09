<?php

/**
 * Quick Timestamp Mismatch Checker
 * 
 * Fast method: Samples random records and checks for patterns.
 * Use for quick verification without processing all records.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

// Get date to check (default: today)
$checkDate = isset($argv[1]) ? Carbon::parse($argv[1]) : Carbon::today();
$dateStr = $checkDate->toDateString();
$partitionTable = 'alerts_' . $checkDate->format('Y_m_d');
$sampleSize = isset($argv[2]) ? (int)$argv[2] : 100; // Sample 100 records by default

echo "=== Quick Timestamp Mismatch Checker ===\n";
echo "Date: {$dateStr}\n";
echo "Partition Table: {$partitionTable}\n";
echo "Sample Size: {$sampleSize} records\n\n";

// Check if partition table exists
$tableExists = DB::connection('pgsql')
    ->select("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = ?)", [$partitionTable]);

if (!$tableExists[0]->exists) {
    echo "❌ Partition table '{$partitionTable}' does not exist in PostgreSQL.\n";
    exit(1);
}

$startTime = microtime(true);

// Step 1: Count records
echo "Step 1: Counting records...\n";
$mysqlCount = DB::connection('mysql')
    ->table('alerts')
    ->whereDate('receivedtime', $dateStr)
    ->count();

$pgCount = DB::connection('pgsql')
    ->table($partitionTable)
    ->count();

echo "  MySQL: " . number_format($mysqlCount) . " alerts\n";
echo "  PostgreSQL: " . number_format($pgCount) . " alerts\n";

if ($mysqlCount !== $pgCount) {
    echo "  ⚠️  Count mismatch! Difference: " . number_format(abs($mysqlCount - $pgCount)) . "\n";
}
echo "\n";

if ($mysqlCount === 0) {
    echo "No data to check.\n";
    exit(0);
}

// Step 2: Sample random records
echo "Step 2: Sampling {$sampleSize} random records...\n";

$sampleIds = DB::connection('mysql')
    ->table('alerts')
    ->whereDate('receivedtime', $dateStr)
    ->inRandomOrder()
    ->limit($sampleSize)
    ->pluck('id');

echo "  Sampled " . $sampleIds->count() . " alert IDs\n\n";

// Step 3: Compare timestamps for sample
echo "Step 3: Comparing timestamps...\n";

$mysqlSample = DB::connection('mysql')
    ->table('alerts')
    ->whereIn('id', $sampleIds)
    ->get()
    ->keyBy('id');

$pgSample = DB::connection('pgsql')
    ->table($partitionTable)
    ->whereIn('id', $sampleIds)
    ->get()
    ->keyBy('id');

$matched = 0;
$mismatched = 0;
$missing = 0;
$mismatchDetails = [];

foreach ($sampleIds as $id) {
    if (!$mysqlSample->has($id) || !$pgSample->has($id)) {
        $missing++;
        continue;
    }
    
    $mysql = $mysqlSample->get($id);
    $pg = $pgSample->get($id);
    
    // Compare timestamps (1 second tolerance)
    $createDiff = abs(strtotime($mysql->createtime) - strtotime($pg->createtime));
    $receivedDiff = abs(strtotime($mysql->receivedtime) - strtotime($pg->receivedtime));
    $closedDiff = 0;
    
    if ($mysql->closedtime && $pg->closedtime) {
        $closedDiff = abs(strtotime($mysql->closedtime) - strtotime($pg->closedtime));
    }
    
    if ($createDiff > 1 || $receivedDiff > 1 || $closedDiff > 1) {
        $mismatched++;
        
        // Store first 5 mismatches for display
        if (count($mismatchDetails) < 5) {
            $mismatchDetails[] = [
                'id' => $id,
                'mysql_received' => $mysql->receivedtime,
                'pg_received' => $pg->receivedtime,
                'diff_hours' => round($receivedDiff / 3600, 2),
            ];
        }
    } else {
        $matched++;
    }
}

$duration = microtime(true) - $startTime;

// Display results
echo "\n=== Sample Results (completed in " . round($duration, 2) . " seconds) ===\n";
echo "Sample size: {$sampleSize} records\n";
echo "Matched: {$matched} (" . round(($matched / $sampleSize) * 100, 1) . "%)\n";
echo "Mismatched: {$mismatched} (" . round(($mismatched / $sampleSize) * 100, 1) . "%)\n";
echo "Missing: {$missing}\n\n";

if ($mismatched > 0) {
    echo "❌ Found timestamp mismatches in sample!\n\n";
    
    // Estimate total mismatches
    $estimatedTotal = round(($mismatched / $sampleSize) * $mysqlCount);
    echo "Estimated total mismatches: ~" . number_format($estimatedTotal) . " records\n\n";
    
    echo "Sample mismatches:\n";
    echo str_repeat("-", 100) . "\n";
    printf("%-12s %-25s %-25s %-15s\n", "Alert ID", "MySQL receivedtime", "PG receivedtime", "Diff (hours)");
    echo str_repeat("-", 100) . "\n";
    
    foreach ($mismatchDetails as $detail) {
        printf("%-12s %-25s %-25s %-15s\n",
            $detail['id'],
            $detail['mysql_received'],
            $detail['pg_received'],
            $detail['diff_hours']
        );
    }
    
    echo "\n";
    echo "Pattern detected: " . ($mismatchDetails[0]['diff_hours'] ?? 0) . " hour difference\n";
    
    if (abs($mismatchDetails[0]['diff_hours'] ?? 0) == 5.5) {
        echo "⚠️  This is a timezone conversion issue (IST → UTC)\n";
        echo "Fix: Run 'php codes/fix-timezone-data.php'\n";
    }
    
    echo "\nFor full analysis, use: php codes/check-timestamp-mismatches.php {$dateStr}\n";
    echo "Or web interface: http://localhost:9000/timestamp-mismatches\n";
    
} else {
    echo "✅ All sampled timestamps match perfectly!\n";
    echo "Sample indicates data is consistent.\n";
}

echo "\n=== Usage ===\n";
echo "Quick check today: php codes/check-timestamp-mismatches-quick.php\n";
echo "Check specific date: php codes/check-timestamp-mismatches-quick.php 2026-03-04\n";
echo "Custom sample size: php codes/check-timestamp-mismatches-quick.php 2026-03-04 500\n";
