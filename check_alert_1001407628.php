<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$alertId = 1001407628;

echo "=== CHECKING ALERT {$alertId} ===\n\n";

// PostgreSQL
$pgAlert = DB::connection('pgsql')
    ->table('alerts_2026_03_06')
    ->where('id', $alertId)
    ->first();

echo "PostgreSQL:\n";
echo "  createtime: {$pgAlert->createtime}\n";
echo "  receivedtime: {$pgAlert->receivedtime}\n";
echo "  closedtime: " . ($pgAlert->closedtime ?? 'NULL') . "\n";
echo "  Date from receivedtime: " . substr($pgAlert->receivedtime, 0, 10) . "\n";
echo "  Matches partition date? " . (substr($pgAlert->receivedtime, 0, 10) === '2026-03-06' ? 'YES' : 'NO') . "\n\n";

// MySQL
$mysqlAlert = DB::connection('mysql')
    ->table('alerts')
    ->where('id', $alertId)
    ->first();

echo "MySQL:\n";
echo "  createtime: {$mysqlAlert->createtime}\n";
echo "  receivedtime: {$mysqlAlert->receivedtime}\n";
echo "  closedtime: " . ($mysqlAlert->closedtime ?? 'NULL') . "\n\n";

// Compare
echo "Comparison:\n";
if ($pgAlert->createtime === $mysqlAlert->createtime) {
    echo "  ✓ createtime matches\n";
} else {
    echo "  ✗ createtime MISMATCH: PG={$pgAlert->createtime}, MySQL={$mysqlAlert->createtime}\n";
}

if ($pgAlert->receivedtime === $mysqlAlert->receivedtime) {
    echo "  ✓ receivedtime matches\n";
} else {
    echo "  ✗ receivedtime MISMATCH: PG={$pgAlert->receivedtime}, MySQL={$mysqlAlert->receivedtime}\n";
}

if ($pgAlert->closedtime === $mysqlAlert->closedtime) {
    echo "  ✓ closedtime matches\n";
} else {
    echo "  ✗ closedtime MISMATCH: PG=" . ($pgAlert->closedtime ?? 'NULL') . ", MySQL=" . ($mysqlAlert->closedtime ?? 'NULL') . "\n";
}

echo "\nThis alert " . (substr($pgAlert->receivedtime, 0, 10) === '2026-03-06' ? 'WILL NOT' : 'WILL') . " be caught by the date-based fix script.\n";
echo "It needs the timestamp comparison fix instead.\n";
