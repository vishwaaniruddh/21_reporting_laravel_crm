<?php

require_once 'vendor/autoload.php';

// Load Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Alert Update Log Table Structure ===\n";

try {
    $columns = DB::connection('mysql')->select('SHOW COLUMNS FROM alert_pg_update_log');
    
    echo "Columns found:\n";
    foreach ($columns as $column) {
        echo "- {$column->Field} ({$column->Type})\n";
    }
    
    echo "\n=== Sample Records ===\n";
    $samples = DB::connection('mysql')->table('alert_pg_update_log')->limit(3)->get();
    foreach ($samples as $sample) {
        echo "ID: {$sample->id}, Alert ID: {$sample->alert_id}, Operation: {$sample->operation_type}, Status: {$sample->status}\n";
        echo "  Old Data: " . (is_null($sample->old_data) ? 'NULL' : 'HAS_DATA') . "\n";
        echo "  New Data: " . (is_null($sample->new_data) ? 'NULL' : 'HAS_DATA') . "\n";
        echo "---\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}