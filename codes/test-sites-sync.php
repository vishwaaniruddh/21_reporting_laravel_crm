<?php
/**
 * Test sites sync service
 */

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\SitesSyncService;

echo "Testing SitesSyncService...\n\n";

$service = new SitesSyncService();

// Test status first
echo "=== Status ===\n";
$status = $service->getStatus();
foreach ($status as $table => $data) {
    echo "{$table}: MySQL={$data['mysql_count']}, PG={$data['postgres_count']}, Unsynced={$data['unsynced_count']}\n";
}

echo "\n=== Testing sites sync (first 10 records) ===\n";

// Test with just a few records
try {
    $records = DB::connection('mysql')
        ->table('sites')
        ->orderBy('SN')
        ->limit(10)
        ->get();
    
    echo "Fetched " . $records->count() . " records from MySQL\n";
    
    foreach ($records as $record) {
        $exists = DB::connection('pgsql')
            ->table('sites')
            ->where('SN', $record->SN)
            ->exists();
        
        echo "  SN={$record->SN}: " . ($exists ? "exists in PG" : "NOT in PG") . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
