<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Timezone Configuration ===\n";
echo "PHP Timezone: " . date_default_timezone_get() . "\n";
echo "Laravel Timezone: " . config('app.timezone') . "\n\n";

echo "=== Database Timezone Settings ===\n";

// MySQL timezone
$mysqlTz = DB::connection('mysql')->selectOne('SELECT @@session.time_zone as tz, @@global.time_zone as global_tz');
echo "MySQL Session TZ: " . $mysqlTz->tz . "\n";
echo "MySQL Global TZ: " . $mysqlTz->global_tz . "\n\n";

// PostgreSQL timezone
$pgTz = DB::connection('pgsql')->select('SHOW timezone');
echo "PostgreSQL TZ: " . print_r($pgTz, true) . "\n\n";

echo "=== Testing Alert ID 40614003 ===\n";

// Get from MySQL - use string for large ID
$mysqlAlert = DB::connection('mysql')
    ->table('alerts')
    ->whereRaw('id = ?', ['40614003'])
    ->first();

if ($mysqlAlert) {
    echo "MySQL Data:\n";
    echo "  createtime: " . ($mysqlAlert->createtime ?? 'NULL') . "\n";
    echo "  receivedtime: " . ($mysqlAlert->receivedtime ?? 'NULL') . "\n";
    echo "  closedtime: " . ($mysqlAlert->closedtime ?? 'NULL') . "\n";
    echo "  inserttime: " . ($mysqlAlert->inserttime ?? 'NULL') . "\n\n";
} else {
    echo "Alert not found in MySQL\n\n";
}

// Get from PostgreSQL - use string for large ID
$pgAlert = DB::connection('pgsql')
    ->table('alerts_2026_03_04')
    ->whereRaw('id = ?', ['40614003'])
    ->first();

if ($pgAlert) {
    echo "PostgreSQL Data:\n";
    echo "  createtime: " . ($pgAlert->createtime ?? 'NULL') . "\n";
    echo "  receivedtime: " . ($pgAlert->receivedtime ?? 'NULL') . "\n";
    echo "  closedtime: " . ($pgAlert->closedtime ?? 'NULL') . "\n";
    echo "  inserttime: " . ($pgAlert->inserttime ?? 'NULL') . "\n\n";
} else {
    echo "Alert not found in PostgreSQL\n\n";
}

echo "=== Time Difference Analysis ===\n";
if ($mysqlAlert && $pgAlert) {
    $mysqlTime = strtotime($mysqlAlert->receivedtime);
    $pgTime = strtotime($pgAlert->receivedtime);
    $diff = ($mysqlTime - $pgTime) / 3600;
    echo "Time difference: " . $diff . " hours\n";
    echo "This suggests a timezone conversion issue.\n";
}
