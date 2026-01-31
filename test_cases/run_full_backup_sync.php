<?php
/**
 * Full Backup Sync Runner
 * Fixes the max-batches issue and runs the complete sync
 */

echo "=== FULL BACKUP SYNC (Fixed) ===\n";
echo "Issue identified: --max-batches defaults to 1\n";
echo "Solution: Set --max-batches to high value\n\n";

// Calculate required batches
$totalRecords = 9307050; // From your output
$batchSize = 10000;
$requiredBatches = ceil($totalRecords / $batchSize);

echo "Total records: " . number_format($totalRecords) . "\n";
echo "Batch size: " . number_format($batchSize) . "\n";
echo "Required batches: " . number_format($requiredBatches) . "\n\n";

// Set max-batches to required + buffer
$maxBatches = $requiredBatches + 50; // Add buffer

echo "Setting --max-batches to: {$maxBatches}\n\n";

$command = "php artisan sync:backup-data --batch-size=10000 --continuous --max-batches={$maxBatches} --force";

echo "Running command:\n";
echo $command . "\n\n";

echo "Expected completion time: ~15-20 minutes\n";
echo "Expected speed: ~8,000-10,000 records/second\n\n";

echo "Starting sync...\n";
echo str_repeat('=', 60) . "\n\n";

// Execute the command
$startTime = time();
passthru($command, $returnCode);

$endTime = time();
$duration = $endTime - $startTime;

echo "\n" . str_repeat('=', 60) . "\n";
echo "SYNC COMPLETED\n";
echo "Duration: " . gmdate('H:i:s', $duration) . "\n";
echo "Return code: {$returnCode}\n";

if ($returnCode === 0) {
    echo "✅ SUCCESS: All records synced!\n";
} else {
    echo "❌ FAILED: Return code {$returnCode}\n";
}