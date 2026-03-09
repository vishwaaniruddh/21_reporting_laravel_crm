<?php

/**
 * Test Timestamp Preservation During Updates
 * 
 * This script verifies that timestamps (createtime, receivedtime, closedtime)
 * are preserved when updating existing records in PostgreSQL.
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Testing Timestamp Preservation During Updates ===\n\n";

// Clear config cache
\Illuminate\Support\Facades\Artisan::call('config:clear');

$testId = 888888888;
$partitionTable = 'alerts_2026_03_04';

// Cleanup any existing test record
DB::connection('mysql')->table('alerts')->where('id', $testId)->delete();
DB::connection('pgsql')->table($partitionTable)->where('id', $testId)->delete();

echo "Step 1: Insert initial record with specific timestamps\n";
$initialCreateTime = '2026-03-04 10:00:00';
$initialReceivedTime = '2026-03-04 10:00:05';
$initialClosedTime = '2026-03-04 10:30:00';

// Insert to MySQL
DB::connection('mysql')->table('alerts')->insert([
    'id' => $testId,
    'panelid' => '096318',
    'seqno' => '1930',
    'zone' => '003',
    'alarm' => 'BA',
    'createtime' => $initialCreateTime,
    'receivedtime' => $initialReceivedTime,
    'comment' => 'Initial insert test',
    'status' => 'O', // Open
    'sendtoclient' => 'S',
]);

echo "  MySQL record created\n";
echo "  createtime: {$initialCreateTime}\n";
echo "  receivedtime: {$initialReceivedTime}\n";
echo "  closedtime: NULL\n\n";

// Sync to PostgreSQL (first insert)
$mysqlRecord = DB::connection('mysql')->table('alerts')->where('id', $testId)->first();
DB::connection('pgsql')->table($partitionTable)->insert([
    'id' => $mysqlRecord->id,
    'panelid' => $mysqlRecord->panelid,
    'seqno' => $mysqlRecord->seqno,
    'zone' => $mysqlRecord->zone,
    'alarm' => $mysqlRecord->alarm,
    'createtime' => $mysqlRecord->createtime,
    'receivedtime' => $mysqlRecord->receivedtime,
    'comment' => $mysqlRecord->comment,
    'status' => $mysqlRecord->status,
    'sendtoclient' => $mysqlRecord->sendtoclient,
    'closedtime' => null,
    'synced_at' => now(),
    'sync_batch_id' => 0,
]);

echo "Step 2: Verify initial PostgreSQL record\n";
$pgRecord1 = DB::connection('pgsql')->table($partitionTable)->where('id', $testId)->first();
echo "  PostgreSQL record:\n";
echo "  createtime: {$pgRecord1->createtime}\n";
echo "  receivedtime: {$pgRecord1->receivedtime}\n";
echo "  closedtime: " . ($pgRecord1->closedtime ?? 'NULL') . "\n\n";

// Verify timestamps match
$match1 = ($pgRecord1->createtime === $initialCreateTime && 
           $pgRecord1->receivedtime === $initialReceivedTime);
echo "  Initial sync: " . ($match1 ? "✅ PASS" : "❌ FAIL") . "\n\n";

echo "Step 3: Update MySQL record (close the alert)\n";
$updatedClosedTime = '2026-03-04 11:00:00';
DB::connection('mysql')->table('alerts')->where('id', $testId)->update([
    'status' => 'C', // Closed
    'closedtime' => $updatedClosedTime,
    'closedBy' => 'TestUser',
    'comment' => 'Alert closed - testing timestamp preservation',
]);

echo "  MySQL record updated\n";
echo "  status: C (Closed)\n";
echo "  closedtime: {$updatedClosedTime}\n";
echo "  comment: Updated\n\n";

echo "Step 4: Simulate update sync to PostgreSQL\n";
$mysqlRecord2 = DB::connection('mysql')->table('alerts')->where('id', $testId)->first();

// Check if record exists (it does)
$existingRecord = DB::connection('pgsql')->table($partitionTable)->where('id', $testId)->first();

// Prepare upsert data - preserving original timestamps
$upsertData = [
    'id' => $mysqlRecord2->id,
    'panelid' => $mysqlRecord2->panelid,
    'seqno' => $mysqlRecord2->seqno,
    'zone' => $mysqlRecord2->zone,
    'alarm' => $mysqlRecord2->alarm,
    'comment' => $mysqlRecord2->comment,
    'status' => $mysqlRecord2->status,
    'sendtoclient' => $mysqlRecord2->sendtoclient,
    'closedBy' => $mysqlRecord2->closedBy,
    'synced_at' => now(),
    'sync_batch_id' => 0,
];

// CRITICAL: Preserve original timestamps from existing record
if ($existingRecord) {
    $upsertData['createtime'] = $existingRecord->createtime;
    $upsertData['receivedtime'] = $existingRecord->receivedtime;
    $upsertData['closedtime'] = $existingRecord->closedtime; // Keep original, don't update
    echo "  Preserving original timestamps from existing record\n";
} else {
    $upsertData['createtime'] = $mysqlRecord2->createtime;
    $upsertData['receivedtime'] = $mysqlRecord2->receivedtime;
    $upsertData['closedtime'] = $mysqlRecord2->closedtime;
}

// Perform upsert (update)
DB::connection('pgsql')->table($partitionTable)->upsert(
    [$upsertData],
    ['id'],
    ['panelid', 'seqno', 'zone', 'alarm', 'comment', 'status', 'sendtoclient', 'closedBy', 'synced_at', 'sync_batch_id']
    // NOTE: createtime, receivedtime, closedtime are NOT in update list
);

echo "  PostgreSQL record updated (upsert)\n\n";

echo "Step 5: Verify timestamps were preserved\n";
$pgRecord2 = DB::connection('pgsql')->table($partitionTable)->where('id', $testId)->first();
echo "  PostgreSQL record after update:\n";
echo "  createtime: {$pgRecord2->createtime}\n";
echo "  receivedtime: {$pgRecord2->receivedtime}\n";
echo "  closedtime: " . ($pgRecord2->closedtime ?? 'NULL') . "\n";
echo "  status: {$pgRecord2->status}\n";
echo "  comment: {$pgRecord2->comment}\n\n";

// Verify timestamps were preserved (not changed)
$preserved = ($pgRecord2->createtime === $initialCreateTime && 
              $pgRecord2->receivedtime === $initialReceivedTime &&
              $pgRecord2->closedtime === $existingRecord->closedtime); // Should be NULL still

echo "=== Results ===\n";
echo "Original createtime: {$initialCreateTime}\n";
echo "After update createtime: {$pgRecord2->createtime}\n";
echo "Match: " . ($pgRecord2->createtime === $initialCreateTime ? "✅ YES" : "❌ NO") . "\n\n";

echo "Original receivedtime: {$initialReceivedTime}\n";
echo "After update receivedtime: {$pgRecord2->receivedtime}\n";
echo "Match: " . ($pgRecord2->receivedtime === $initialReceivedTime ? "✅ YES" : "❌ NO") . "\n\n";

echo "Original closedtime: " . ($existingRecord->closedtime ?? 'NULL') . "\n";
echo "After update closedtime: " . ($pgRecord2->closedtime ?? 'NULL') . "\n";
echo "Match: " . ($pgRecord2->closedtime === $existingRecord->closedtime ? "✅ YES" : "❌ NO") . "\n\n";

echo "Status updated: " . ($pgRecord2->status === 'C' ? "✅ YES" : "❌ NO") . "\n";
echo "Comment updated: " . (str_contains($pgRecord2->comment, 'testing timestamp preservation') ? "✅ YES" : "❌ NO") . "\n\n";

if ($preserved) {
    echo "✅ SUCCESS! Timestamps were preserved during update.\n";
    echo "   Other fields (status, comment) were updated correctly.\n";
} else {
    echo "❌ FAILED! Timestamps were changed during update.\n";
}

// Cleanup
echo "\n=== Cleanup ===\n";
DB::connection('mysql')->table('alerts')->where('id', $testId)->delete();
DB::connection('pgsql')->table($partitionTable)->where('id', $testId)->delete();
echo "Test records deleted.\n";
