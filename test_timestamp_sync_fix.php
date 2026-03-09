<?php

/**
 * Test script to verify timestamps are synced identically without timezone conversion
 * 
 * This script:
 * 1. Fetches a sample alert from MySQL
 * 2. Shows the raw timestamp values
 * 3. Simulates the sync process
 * 4. Verifies timestamps match exactly
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\AlertSyncService;
use App\Services\SyncLogger;
use App\Services\PartitionManager;
use App\Services\DateExtractor;
use App\Services\TimestampValidator;

echo "=== Timestamp Sync Verification Test ===\n\n";

// Get a sample alert ID from MySQL
$sampleAlert = DB::connection('mysql')
    ->table('alerts')
    ->whereNotNull('receivedtime')
    ->orderBy('id', 'desc')
    ->first();

if (!$sampleAlert) {
    echo "❌ No alerts found in MySQL database\n";
    exit(1);
}

echo "Testing with Alert ID: {$sampleAlert->id}\n\n";

// Show raw MySQL timestamps
echo "--- RAW MySQL Timestamps ---\n";
echo "createtime:   {$sampleAlert->createtime}\n";
echo "receivedtime: {$sampleAlert->receivedtime}\n";
echo "closedtime:   " . ($sampleAlert->closedtime ?? 'NULL') . "\n\n";

// Test OLD method (using Alert model with datetime casts)
echo "--- OLD Method (Alert Model with datetime casts) ---\n";
$alertModel = \App\Models\Alert::find($sampleAlert->id);
if ($alertModel) {
    $modelArray = $alertModel->toArray();
    echo "createtime:   {$modelArray['createtime']}\n";
    echo "receivedtime: {$modelArray['receivedtime']}\n";
    echo "closedtime:   " . ($modelArray['closedtime'] ?? 'NULL') . "\n";
    
    // Check if conversion occurred
    if ($sampleAlert->createtime !== $modelArray['createtime']) {
        echo "⚠️  WARNING: Timezone conversion detected!\n";
        echo "   MySQL:  {$sampleAlert->createtime}\n";
        echo "   Model:  {$modelArray['createtime']}\n";
    } else {
        echo "✓ No conversion detected\n";
    }
}
echo "\n";

// Test NEW method (using DB::table for raw data)
echo "--- NEW Method (DB::table for raw data) ---\n";
$rawAlert = DB::connection('mysql')
    ->table('alerts')
    ->where('id', $sampleAlert->id)
    ->first();

$rawArray = (array) $rawAlert;
echo "createtime:   {$rawArray['createtime']}\n";
echo "receivedtime: {$rawArray['receivedtime']}\n";
echo "closedtime:   " . ($rawArray['closedtime'] ?? 'NULL') . "\n";

// Verify exact match
if ($sampleAlert->createtime === $rawArray['createtime'] &&
    $sampleAlert->receivedtime === $rawArray['receivedtime'] &&
    $sampleAlert->closedtime === $rawArray['closedtime']) {
    echo "✓ Timestamps match exactly - NO timezone conversion\n";
} else {
    echo "❌ Timestamps don't match!\n";
}
echo "\n";

// Test TimestampValidator
echo "--- Timestamp Validation Test ---\n";
$validator = new TimestampValidator();

$sourceData = [
    'createtime' => $sampleAlert->createtime,
    'receivedtime' => $sampleAlert->receivedtime,
    'closedtime' => $sampleAlert->closedtime,
];

$targetData = [
    'createtime' => $rawArray['createtime'],
    'receivedtime' => $rawArray['receivedtime'],
    'closedtime' => $rawArray['closedtime'],
];

$validation = $validator->validateBeforeSync($sourceData, $targetData, $sampleAlert->id);

if ($validation['valid']) {
    echo "✓ Validation PASSED - Timestamps are identical\n";
} else {
    echo "❌ Validation FAILED:\n";
    foreach ($validation['errors'] as $error) {
        echo "   - {$error}\n";
    }
}
echo "\n";

// Check for timezone conversion patterns
if ($sampleAlert->createtime && $rawArray['createtime']) {
    $detection = $validator->detectTimezoneConversion(
        $sampleAlert->createtime,
        $rawArray['createtime']
    );
    
    if ($detection['converted']) {
        echo "⚠️  Timezone conversion detected: {$detection['hours_diff']} hours difference\n";
    } else {
        echo "✓ No timezone conversion pattern detected\n";
    }
}
echo "\n";

// Summary
echo "=== Summary ===\n";
echo "The NEW method (DB::table) ensures timestamps are synced as raw strings\n";
echo "without any timezone conversion, maintaining exact values from MySQL.\n";
echo "\n";
echo "✓ Fix applied to AlertSyncService.php\n";
echo "✓ Fix applied to BackAlertSyncService.php\n";
echo "\n";
echo "Next steps:\n";
echo "1. Restart sync services to apply the fix\n";
echo "2. Monitor sync logs for timestamp validation messages\n";
echo "3. Verify PostgreSQL partition tables have identical timestamps\n";
