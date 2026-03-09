<?php

/**
 * Test script to verify closedtime updates correctly
 * 
 * This script tests the scenario where:
 * 1. Alert is first synced with status='O' and closedtime=NULL
 * 2. Alert is later closed in MySQL with status='C' and closedtime set
 * 3. Verify PostgreSQL gets the closedtime updated
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Closedtime Update Test ===\n\n";

// Find an alert that has closedtime in MySQL
$closedAlert = DB::connection('mysql')
    ->table('alerts')
    ->whereNotNull('closedtime')
    ->whereNotNull('receivedtime')
    ->where('status', 'C')
    ->orderBy('id', 'desc')
    ->first();

if (!$closedAlert) {
    echo "❌ No closed alerts found in MySQL\n";
    echo "Looking for any alert with closedtime...\n\n";
    
    $closedAlert = DB::connection('mysql')
        ->table('alerts')
        ->whereNotNull('closedtime')
        ->orderBy('id', 'desc')
        ->first();
    
    if (!$closedAlert) {
        echo "❌ No alerts with closedtime found at all\n";
        exit(1);
    }
}

echo "Testing with Alert ID: {$closedAlert->id}\n";
echo "Status: {$closedAlert->status}\n\n";

// Show MySQL data
echo "--- MySQL Data ---\n";
echo "createtime:   {$closedAlert->createtime}\n";
echo "receivedtime: {$closedAlert->receivedtime}\n";
echo "closedtime:   " . ($closedAlert->closedtime ?? 'NULL') . "\n";
echo "closedBy:     " . ($closedAlert->closedBy ?? 'NULL') . "\n\n";

// Determine partition table
$receivedDate = date('Y_m_d', strtotime($closedAlert->receivedtime));
$partitionTable = "alerts_{$receivedDate}";

echo "Partition table: {$partitionTable}\n\n";

// Check if partition table exists
$tableExists = DB::connection('pgsql')
    ->select("SELECT EXISTS (
        SELECT FROM information_schema.tables 
        WHERE table_schema = 'public' 
        AND table_name = ?
    )", [$partitionTable]);

if (!$tableExists[0]->exists) {
    echo "❌ Partition table {$partitionTable} does not exist in PostgreSQL\n";
    echo "This alert hasn't been synced yet.\n";
    exit(1);
}

// Check PostgreSQL data
$pgAlert = DB::connection('pgsql')
    ->table($partitionTable)
    ->where('id', $closedAlert->id)
    ->first();

if (!$pgAlert) {
    echo "❌ Alert ID {$closedAlert->id} not found in PostgreSQL partition {$partitionTable}\n";
    echo "This alert hasn't been synced yet.\n";
    exit(1);
}

echo "--- PostgreSQL Data (Current) ---\n";
echo "createtime:   {$pgAlert->createtime}\n";
echo "receivedtime: {$pgAlert->receivedtime}\n";
echo "closedtime:   " . ($pgAlert->closedtime ?? 'NULL') . "\n";
echo "closedby:     " . ($pgAlert->closedby ?? 'NULL') . "\n\n";

// Compare
echo "--- Comparison ---\n";

$issues = [];

// Check createtime
if ($closedAlert->createtime !== $pgAlert->createtime) {
    $issues[] = "createtime mismatch";
    echo "❌ createtime: MySQL={$closedAlert->createtime}, PostgreSQL={$pgAlert->createtime}\n";
} else {
    echo "✓ createtime matches\n";
}

// Check receivedtime
if ($closedAlert->receivedtime !== $pgAlert->receivedtime) {
    $issues[] = "receivedtime mismatch";
    echo "❌ receivedtime: MySQL={$closedAlert->receivedtime}, PostgreSQL={$pgAlert->receivedtime}\n";
} else {
    echo "✓ receivedtime matches\n";
}

// Check closedtime - THIS IS THE CRITICAL ONE
if ($closedAlert->closedtime !== $pgAlert->closedtime) {
    $issues[] = "closedtime mismatch";
    echo "❌ closedtime: MySQL={$closedAlert->closedtime}, PostgreSQL=" . ($pgAlert->closedtime ?? 'NULL') . "\n";
    
    if ($closedAlert->closedtime !== null && $pgAlert->closedtime === null) {
        echo "\n";
        echo "⚠️  CRITICAL ISSUE DETECTED:\n";
        echo "   MySQL has closedtime but PostgreSQL has NULL\n";
        echo "   This means the alert was closed in MySQL but the update didn't sync to PostgreSQL\n";
        echo "\n";
        echo "   This is the bug we just fixed!\n";
        echo "   After restarting services, this should sync correctly.\n";
    }
} else {
    echo "✓ closedtime matches\n";
}

echo "\n";

if (empty($issues)) {
    echo "=== Result: ✓ ALL TIMESTAMPS MATCH ===\n";
    echo "This alert is synced correctly.\n";
} else {
    echo "=== Result: ❌ MISMATCHES FOUND ===\n";
    echo "Issues: " . implode(', ', $issues) . "\n\n";
    
    if (in_array('closedtime mismatch', $issues)) {
        echo "The closedtime mismatch is the issue we just fixed.\n";
        echo "After restarting services, new updates will sync closedtime correctly.\n\n";
        
        echo "To fix this specific alert:\n";
        echo "1. Restart services: .\\codes\\restart-services-for-timestamp-fix.ps1\n";
        echo "2. Trigger a re-sync by updating the alert in MySQL:\n";
        echo "   UPDATE alerts SET comment=CONCAT(comment, ' ') WHERE id={$closedAlert->id};\n";
        echo "3. Wait for sync to process\n";
        echo "4. Run this test again to verify\n";
    }
}

echo "\n";
echo "=== Understanding the Fix ===\n";
echo "\n";
echo "OLD Behavior (WRONG):\n";
echo "  - When alert exists in PostgreSQL, ALL timestamps were preserved\n";
echo "  - closedtime could never change from NULL to a value\n";
echo "  - Result: Closed alerts in MySQL stayed open in PostgreSQL\n";
echo "\n";
echo "NEW Behavior (CORRECT):\n";
echo "  - createtime: IMMUTABLE (never changes)\n";
echo "  - receivedtime: IMMUTABLE (never changes)\n";
echo "  - closedtime: CAN change from NULL to a value when alert is closed\n";
echo "  - Result: When alert is closed in MySQL, closedtime syncs to PostgreSQL\n";
echo "\n";

// Find statistics
echo "=== Statistics ===\n\n";

$mysqlClosed = DB::connection('mysql')
    ->table('alerts')
    ->whereNotNull('closedtime')
    ->count();

echo "MySQL alerts with closedtime: " . number_format($mysqlClosed) . "\n";

// Count PostgreSQL alerts with closedtime across all partitions
$partitions = DB::connection('pgsql')
    ->select("SELECT tablename FROM pg_tables WHERE tablename LIKE 'alerts_%' ORDER BY tablename");

$pgClosedTotal = 0;
$pgNullTotal = 0;

foreach ($partitions as $partition) {
    $closed = DB::connection('pgsql')
        ->table($partition->tablename)
        ->whereNotNull('closedtime')
        ->count();
    
    $nullClosed = DB::connection('pgsql')
        ->table($partition->tablename)
        ->whereNull('closedtime')
        ->count();
    
    $pgClosedTotal += $closed;
    $pgNullTotal += $nullClosed;
}

echo "PostgreSQL alerts with closedtime: " . number_format($pgClosedTotal) . "\n";
echo "PostgreSQL alerts with NULL closedtime: " . number_format($pgNullTotal) . "\n\n";

if ($mysqlClosed > $pgClosedTotal) {
    $missing = $mysqlClosed - $pgClosedTotal;
    echo "⚠️  PostgreSQL is missing closedtime for approximately " . number_format($missing) . " alerts\n";
    echo "   These will be fixed as they get updated in MySQL after service restart.\n";
} else {
    echo "✓ PostgreSQL closedtime count looks reasonable\n";
}

echo "\n";
