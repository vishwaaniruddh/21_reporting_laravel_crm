<?php
/**
 * Check MySQL sites, dvrsite, dvronline table structures
 */

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = ['sites', 'dvrsite', 'dvronline'];

foreach ($tables as $table) {
    echo "=== {$table} ===\n";
    try {
        $columns = DB::connection('mysql')->select("SHOW COLUMNS FROM {$table}");
        foreach ($columns as $col) {
            echo "  - {$col->Field} ({$col->Type}) " . ($col->Key === 'PRI' ? '[PK]' : '') . "\n";
        }
        
        $count = DB::connection('mysql')->table($table)->count();
        echo "  Total records: {$count}\n";
    } catch (Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
