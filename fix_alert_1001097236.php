<?php

/**
 * Fix Script for Alert ID 1001097236
 * 
 * This script will:
 * 1. Fetch the correct data from MySQL
 * 2. Update PostgreSQL using explicit timezone handling
 * 3. Verify the fix
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== FIXING ALERT 1001097236 ===\n\n";

$alertId = 1001097236;

// Step 1: Fetch correct data from MySQL
echo "Step 1: Fetching data from MySQL...\n";

$mysqlAlert = DB::connection('mysql')
    ->table('alerts')
    ->where('id', $alertId)
    ->first();

if (!$mysqlAlert) {
    die("ERROR: Alert not found in MySQL!\n");
}

echo "  ✓ Alert found in MySQL\n";
echo "    createtime: {$mysqlAlert->createtime}\n";
echo "    receivedtime: {$mysqlAlert->receivedtime}\n";
echo "    closedtime: " . ($mysqlAlert->closedtime ?? 'NULL') . "\n";
echo "\n";

// Step 2: Determine partition table
echo "Step 2: Determining partition table...\n";

if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $mysqlAlert->receivedtime, $matches)) {
    $dateStr = $matches[1];
    $partitionTable = 'alerts_' . str_replace('-', '_', $dateStr);
    echo "  ✓ Partition table: {$partitionTable}\n\n";
} else {
    die("ERROR: Could not extract date from receivedtime\n");
}

// Step 3: Update PostgreSQL with explicit timezone handling
echo "Step 3: Updating PostgreSQL with correct timestamps...\n";

try {
    DB::connection('pgsql')->beginTransaction();
    
    // Build the UPDATE query with explicit TIMESTAMP casting (no timezone conversion)
    $sql = "
        UPDATE {$partitionTable}
        SET 
            createtime = ?::timestamp,
            receivedtime = ?::timestamp,
            closedtime = " . ($mysqlAlert->closedtime ? "?::timestamp" : "NULL") . ",
            status = ?,
            \"closedBy\" = ?,
            synced_at = NOW()
        WHERE id = ?
    ";
    
    $bindings = [
        $mysqlAlert->createtime,
        $mysqlAlert->receivedtime
    ];
    
    if ($mysqlAlert->closedtime) {
        $bindings[] = $mysqlAlert->closedtime;
    }
    
    $bindings[] = $mysqlAlert->status;
    $bindings[] = $mysqlAlert->closedBy;
    $bindings[] = $alertId;
    
    $affected = DB::connection('pgsql')->update($sql, $bindings);
    
    if ($affected > 0) {
        echo "  ✓ Updated {$affected} row(s) in PostgreSQL\n";
    } else {
        throw new Exception("No rows were updated");
    }
    
    DB::connection('pgsql')->commit();
    echo "  ✓ Transaction committed\n\n";
    
} catch (Exception $e) {
    DB::connection('pgsql')->rollBack();
    die("ERROR: Failed to update PostgreSQL: " . $e->getMessage() . "\n");
}

// Step 4: Verify the fix
echo "Step 4: Verifying the fix...\n";

$pgAlert = DB::connection('pgsql')
    ->table($partitionTable)
    ->where('id', $alertId)
    ->first();

if (!$pgAlert) {
    die("ERROR: Alert not found in PostgreSQL after update!\n");
}

echo "  PostgreSQL data after fix:\n";
echo "    createtime: {$pgAlert->createtime}\n";
echo "    receivedtime: {$pgAlert->receivedtime}\n";
echo "    closedtime: " . ($pgAlert->closedtime ?? 'NULL') . "\n";
echo "\n";

// Compare
$allMatch = true;
$mismatches = [];

if ($mysqlAlert->createtime !== $pgAlert->createtime) {
    $allMatch = false;
    $mismatches[] = "createtime: MySQL={$mysqlAlert->createtime}, PG={$pgAlert->createtime}";
}

if ($mysqlAlert->receivedtime !== $pgAlert->receivedtime) {
    $allMatch = false;
    $mismatches[] = "receivedtime: MySQL={$mysqlAlert->receivedtime}, PG={$pgAlert->receivedtime}";
}

if ($mysqlAlert->closedtime !== $pgAlert->closedtime) {
    $allMatch = false;
    $mismatches[] = "closedtime: MySQL=" . ($mysqlAlert->closedtime ?? 'NULL') . ", PG=" . ($pgAlert->closedtime ?? 'NULL');
}

if ($allMatch) {
    echo "✓✓✓ SUCCESS! All timestamps now match perfectly! ✓✓✓\n";
} else {
    echo "✗ STILL MISMATCHED:\n";
    foreach ($mismatches as $mismatch) {
        echo "  - {$mismatch}\n";
    }
}

echo "\n=== FIX COMPLETE ===\n";
