<?php
/**
 * Check PostgreSQL sites, dvrsite, dvronline tables
 */

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$tables = ['sites', 'dvrsite', 'dvronline'];

foreach ($tables as $table) {
    echo "=== PostgreSQL: {$table} ===\n";
    
    if (Schema::connection('pgsql')->hasTable($table)) {
        $columns = DB::connection('pgsql')->select("
            SELECT column_name, data_type 
            FROM information_schema.columns 
            WHERE table_name = '{$table}' 
            ORDER BY ordinal_position
        ");
        
        foreach ($columns as $col) {
            echo "  - {$col->column_name} ({$col->data_type})\n";
        }
        
        $count = DB::connection('pgsql')->table($table)->count();
        echo "  Total records: {$count}\n";
    } else {
        echo "  Table does not exist\n";
    }
    echo "\n";
}
