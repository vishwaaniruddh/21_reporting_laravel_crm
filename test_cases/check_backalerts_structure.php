<?php

require_once 'vendor/autoload.php';

// Load Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== BackAlerts Table Structure ===\n";

try {
    $columns = DB::connection('mysql')->select('SHOW COLUMNS FROM backalerts');
    
    echo "Columns found:\n";
    foreach ($columns as $column) {
        echo "- {$column->Field} ({$column->Type})\n";
    }
    
    echo "\n=== Sample Record ===\n";
    $sample = DB::connection('mysql')->table('backalerts')->first();
    if ($sample) {
        echo "Sample record found with ID: {$sample->id}\n";
        foreach ($sample as $key => $value) {
            echo "- {$key}: " . (is_null($value) ? 'NULL' : $value) . "\n";
        }
    } else {
        echo "No records found in backalerts table\n";
    }
    
    echo "\n=== Record Count ===\n";
    $count = DB::connection('mysql')->table('backalerts')->count();
    echo "Total records: " . number_format($count) . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}