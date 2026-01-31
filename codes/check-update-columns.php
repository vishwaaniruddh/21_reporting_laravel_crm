<?php
/**
 * Check which columns can be used for update detection
 */

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = [
    'sites' => ['last_modified', 'current_dt', 'synced_at'],
    'dvrsite' => ['last_modified', 'current_dt'],
    'dvronline' => [],
];

foreach ($tables as $table => $checkColumns) {
    echo "=== {$table} ===\n";
    
    foreach ($checkColumns as $col) {
        try {
            $exists = DB::connection('mysql')->select("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
            if (!empty($exists)) {
                // Get sample values
                $sample = DB::connection('mysql')
                    ->table($table)
                    ->whereNotNull($col)
                    ->orderByDesc($col)
                    ->limit(3)
                    ->pluck($col);
                
                $nullCount = DB::connection('mysql')->table($table)->whereNull($col)->count();
                $notNullCount = DB::connection('mysql')->table($table)->whereNotNull($col)->count();
                
                echo "  {$col}: EXISTS (null={$nullCount}, not_null={$notNullCount})\n";
                echo "    Recent values: " . $sample->implode(', ') . "\n";
            } else {
                echo "  {$col}: NOT EXISTS\n";
            }
        } catch (Exception $e) {
            echo "  {$col}: ERROR - " . $e->getMessage() . "\n";
        }
    }
    echo "\n";
}
