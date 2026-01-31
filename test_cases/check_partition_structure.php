<?php

require_once 'vendor/autoload.php';

// Load Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== PostgreSQL Partition Table Structure ===\n";

try {
    // Check if table exists
    $tableExists = DB::connection('pgsql')
        ->select("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'backalerts_2026_01_17')");
    
    $exists = $tableExists[0]->exists ?? false;
    echo "backalerts_2026_01_17 table exists: " . ($exists ? 'YES' : 'NO') . "\n\n";
    
    if ($exists) {
        // Get column information
        echo "Columns in backalerts_2026_01_17:\n";
        $columns = DB::connection('pgsql')
            ->select("SELECT column_name, data_type, is_nullable 
                     FROM information_schema.columns 
                     WHERE table_name = 'backalerts_2026_01_17' 
                     ORDER BY ordinal_position");
        
        foreach ($columns as $column) {
            echo "  {$column->column_name} ({$column->data_type}) - Nullable: {$column->is_nullable}\n";
        }
        
        echo "\nCompare with MySQL backalerts table:\n";
        $mysqlColumns = DB::connection('mysql')
            ->select("SHOW COLUMNS FROM backalerts");
        
        foreach ($mysqlColumns as $column) {
            echo "  {$column->Field} ({$column->Type}) - Null: {$column->Null}\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}