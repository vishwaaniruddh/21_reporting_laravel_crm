<?php

/**
 * Check timezone configuration and conversion issue
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

echo "=== Timezone Configuration Check ===\n\n";

// Check PHP timezone
echo "PHP timezone: " . date_default_timezone_get() . "\n";
echo "Laravel APP_TIMEZONE: " . config('app.timezone') . "\n\n";

// Check MySQL timezone
echo "--- MySQL Timezone ---\n";
$mysqlTz = DB::connection('mysql')->select('SELECT @@session.time_zone as tz, NOW() as now');
echo "Session timezone: {$mysqlTz[0]->tz}\n";
echo "Current time: {$mysqlTz[0]->now}\n\n";

// Check PostgreSQL timezone
echo "--- PostgreSQL Timezone ---\n";
$pgTz = DB::connection('pgsql')->select('SHOW timezone');
echo "Timezone: {$pgTz[0]->timezone}\n";
$pgNow = DB::connection('pgsql')->select('SELECT NOW() as now');
echo "Current time: {$pgNow[0]->now}\n\n";

// Check database config
echo "--- Database Configuration ---\n";
$mysqlConfig = config('database.connections.mysql');
echo "MySQL timezone setting: " . ($mysqlConfig['timezone'] ?? 'not set') . "\n";

$pgsqlConfig = config('database.connections.pgsql');
echo "PostgreSQL timezone setting: " . ($pgsqlConfig['timezone'] ?? 'not set') . "\n\n";

// Test the specific alert
echo "=== Testing Alert ID 1001392991 ===\n\n";

// Get from MySQL using raw query
$mysqlAlert = DB::connection('mysql')
    ->select('SELECT id, createtime, receivedtime, closedtime FROM alerts WHERE id = ?', [1001392991]);

if (empty($mysqlAlert)) {
    echo "Alert not found in MySQL\n";
    exit(1);
}

$mysqlAlert = $mysqlAlert[0];

echo "--- MySQL (Raw Query) ---\n";
echo "createtime:   {$mysqlAlert->createtime}\n";
echo "receivedtime: {$mysqlAlert->receivedtime}\n";
echo "closedtime:   {$mysqlAlert->closedtime}\n\n";

// Get from MySQL using Eloquent (OLD way)
$alertModel = \App\Models\Alert::find(1001392991);
if ($alertModel) {
    echo "--- MySQL (Eloquent Model - OLD WAY) ---\n";
    echo "createtime:   {$alertModel->createtime}\n";
    echo "receivedtime: {$alertModel->receivedtime}\n";
    echo "closedtime:   {$alertModel->closedtime}\n\n";
    
    // Check if conversion happened
    if ($mysqlAlert->createtime != $alertModel->createtime) {
        echo "⚠️  CONVERSION DETECTED in Eloquent!\n";
        echo "   Raw:   {$mysqlAlert->createtime}\n";
        echo "   Model: {$alertModel->createtime}\n\n";
    }
}

// Get from PostgreSQL
$pgAlert = DB::connection('pgsql')
    ->select('SELECT id, createtime, receivedtime, closedtime FROM alerts_2026_03_06 WHERE id = ?', [1001392991]);

if (empty($pgAlert)) {
    echo "Alert not found in PostgreSQL\n";
} else {
    $pgAlert = $pgAlert[0];
    
    echo "--- PostgreSQL (Current) ---\n";
    echo "createtime:   {$pgAlert->createtime}\n";
    echo "receivedtime: {$pgAlert->receivedtime}\n";
    echo "closedtime:   " . ($pgAlert->closedtime ?? 'NULL') . "\n\n";
    
    // Calculate difference
    $mysqlTime = new DateTime($mysqlAlert->createtime);
    $pgTime = new DateTime($pgAlert->createtime);
    $diff = $mysqlTime->diff($pgTime);
    
    echo "--- Time Difference ---\n";
    echo "Hours: {$diff->h}\n";
    echo "Days: {$diff->d}\n";
    echo "Total hours: " . ($diff->days * 24 + $diff->h) . "\n\n";
    
    if ($diff->days > 0 || $diff->h > 0) {
        echo "⚠️  TIMEZONE CONVERSION DETECTED!\n";
        echo "   This is the bug we're fixing.\n";
        echo "   Services need to be restarted with the new code.\n\n";
    }
}

// Test Carbon conversion
echo "=== Carbon Conversion Test ===\n\n";
$testTime = '2026-03-06 11:51:31';
echo "Original string: {$testTime}\n";

$carbon = Carbon::parse($testTime);
echo "Carbon parsed: {$carbon}\n";
echo "Carbon timezone: {$carbon->timezone}\n";
echo "Carbon formatted: {$carbon->format('Y-m-d H:i:s')}\n\n";

// Test with timezone
$carbonUtc = Carbon::parse($testTime, 'UTC');
echo "Carbon UTC: {$carbonUtc}\n";

$carbonIst = Carbon::parse($testTime, 'Asia/Kolkata');
echo "Carbon IST: {$carbonIst}\n\n";

echo "=== Summary ===\n\n";
echo "The issue is caused by:\n";
echo "1. Eloquent model datetime casting applies timezone conversion\n";
echo "2. Services are still running OLD code\n\n";
echo "Solution:\n";
echo "1. Restart services: .\\codes\\restart-services-for-timestamp-fix.ps1\n";
echo "2. Force re-sync: UPDATE alerts SET comment=CONCAT(comment, ' ') WHERE id=1001392991;\n";
echo "3. Verify: Run this script again\n";
