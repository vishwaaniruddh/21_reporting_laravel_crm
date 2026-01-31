<?php
/**
 * Test Sites Triggers
 * Makes a test update to verify triggers are working
 */

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Testing Sites Triggers ===\n\n";

// Test sites table
echo "Test 1: Updating sites table...\n";
$site = DB::connection('mysql')->table('sites')->first();
if ($site) {
    $beforeCount = DB::connection('mysql')->table('sites_pg_update_log')->count();
    
    DB::connection('mysql')
        ->table('sites')
        ->where('SN', $site->SN)
        ->update(['editby' => 'trigger_test_' . time()]);
    
    $afterCount = DB::connection('mysql')->table('sites_pg_update_log')->count();
    
    if ($afterCount > $beforeCount) {
        echo "  ✓ Trigger working! Log entry created.\n";
        $entry = DB::connection('mysql')
            ->table('sites_pg_update_log')
            ->orderBy('id', 'desc')
            ->first();
        echo "    Log ID: {$entry->id}, Table: {$entry->table_name}, Record ID: {$entry->record_id}\n";
    } else {
        echo "  ✗ Trigger NOT working! No log entry created.\n";
    }
} else {
    echo "  ✗ No records found in sites table\n";
}

echo "\n";

// Test dvrsite table
echo "Test 2: Updating dvrsite table...\n";
$dvrsite = DB::connection('mysql')->table('dvrsite')->first();
if ($dvrsite) {
    $beforeCount = DB::connection('mysql')->table('sites_pg_update_log')->count();
    
    DB::connection('mysql')
        ->table('dvrsite')
        ->where('SN', $dvrsite->SN)
        ->update(['editby' => 'trigger_test_' . time()]);
    
    $afterCount = DB::connection('mysql')->table('sites_pg_update_log')->count();
    
    if ($afterCount > $beforeCount) {
        echo "  ✓ Trigger working! Log entry created.\n";
        $entry = DB::connection('mysql')
            ->table('sites_pg_update_log')
            ->orderBy('id', 'desc')
            ->first();
        echo "    Log ID: {$entry->id}, Table: {$entry->table_name}, Record ID: {$entry->record_id}\n";
    } else {
        echo "  ✗ Trigger NOT working! No log entry created.\n";
    }
} else {
    echo "  ✗ No records found in dvrsite table\n";
}

echo "\n";

// Test dvronline table
echo "Test 3: Updating dvronline table...\n";
$dvronline = DB::connection('mysql')->table('dvronline')->first();
if ($dvronline) {
    $beforeCount = DB::connection('mysql')->table('sites_pg_update_log')->count();
    
    DB::connection('mysql')
        ->table('dvronline')
        ->where('id', $dvronline->id)
        ->update(['remark' => 'trigger_test_' . time()]);
    
    $afterCount = DB::connection('mysql')->table('sites_pg_update_log')->count();
    
    if ($afterCount > $beforeCount) {
        echo "  ✓ Trigger working! Log entry created.\n";
        $entry = DB::connection('mysql')
            ->table('sites_pg_update_log')
            ->orderBy('id', 'desc')
            ->first();
        echo "    Log ID: {$entry->id}, Table: {$entry->table_name}, Record ID: {$entry->record_id}\n";
    } else {
        echo "  ✗ Trigger NOT working! No log entry created.\n";
    }
} else {
    echo "  ✗ No records found in dvronline table\n";
}

echo "\n";

// Show recent log entries
echo "Recent log entries:\n";
$entries = DB::connection('mysql')
    ->table('sites_pg_update_log')
    ->orderBy('id', 'desc')
    ->limit(5)
    ->get();

foreach ($entries as $entry) {
    echo sprintf(
        "  ID=%d | %s | Record=%d | Status=%d | %s\n",
        $entry->id,
        $entry->table_name,
        $entry->record_id,
        $entry->status,
        $entry->created_at
    );
}

echo "\n=== Test Complete ===\n";
