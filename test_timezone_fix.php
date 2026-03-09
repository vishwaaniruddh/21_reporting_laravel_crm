<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Testing Timezone Fix ===\n\n";

// Clear config cache to ensure new settings are loaded
\Illuminate\Support\Facades\Artisan::call('config:clear');

echo "Configuration reloaded.\n\n";

// Test: Insert a test record with known datetime
$testId = 999999999;
$testTime = '2026-03-04 19:28:17';

echo "Step 1: Inserting test record into MySQL\n";
echo "  ID: {$testId}\n";
echo "  receivedtime: {$testTime}\n\n";

// Clean up any existing test record
DB::connection('mysql')->table('alerts')->where('id', $testId)->delete();

// Insert test record
DB::connection('mysql')->table('alerts')->insert([
    'id' => $testId,
    'panelid' => '096318',
    'seqno' => '1930',
    'zone' => '003',
    'alarm' => 'BA',
    'createtime' => $testTime,
    'receivedtime' => $testTime,
    'comment' => 'Timezone test record',
    'status' => 'C',
    'sendtoclient' => 'S',
]);

echo "Test record inserted into MySQL.\n\n";

// Read back from MySQL
$mysqlRecord = DB::connection('mysql')->table('alerts')->where('id', $testId)->first();
echo "Step 2: Reading from MySQL\n";
echo "  receivedtime: {$mysqlRecord->receivedtime}\n\n";

// Now simulate the sync process - read from MySQL and insert to PostgreSQL
echo "Step 3: Simulating sync to PostgreSQL (alerts_2026_03_04)\n";

// Ensure partition table exists
$partitionTable = 'alerts_2026_03_04';
if (!DB::connection('pgsql')->table($partitionTable)->where('id', $testId)->exists()) {
    // Clean up any existing test record
    DB::connection('pgsql')->table($partitionTable)->where('id', $testId)->delete();
}

// Insert to PostgreSQL using the same method as sync service
$insertData = [
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
    'synced_at' => now(),
    'sync_batch_id' => 0,
];

DB::connection('pgsql')->table($partitionTable)->insert($insertData);

echo "Test record synced to PostgreSQL.\n\n";

// Read back from PostgreSQL
$pgRecord = DB::connection('pgsql')->table($partitionTable)->where('id', $testId)->first();
echo "Step 4: Reading from PostgreSQL\n";
echo "  receivedtime: {$pgRecord->receivedtime}\n\n";

// Compare
echo "=== Comparison ===\n";
echo "MySQL:      {$mysqlRecord->receivedtime}\n";
echo "PostgreSQL: {$pgRecord->receivedtime}\n";

if ($mysqlRecord->receivedtime === $pgRecord->receivedtime) {
    echo "\n✅ SUCCESS! Times match exactly - no timezone conversion occurred.\n";
} else {
    echo "\n❌ FAILED! Times don't match - timezone conversion still happening.\n";
    
    $mysqlTime = strtotime($mysqlRecord->receivedtime);
    $pgTime = strtotime($pgRecord->receivedtime);
    $diff = ($mysqlTime - $pgTime) / 3600;
    echo "Time difference: {$diff} hours\n";
}

// Cleanup
echo "\n=== Cleanup ===\n";
DB::connection('mysql')->table('alerts')->where('id', $testId)->delete();
DB::connection('pgsql')->table($partitionTable)->where('id', $testId)->delete();
echo "Test records deleted.\n";
