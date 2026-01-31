<?php

require_once 'vendor/autoload.php';

// Load Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Fixing BackAlert Partition Table ===\n";

try {
    // Drop the existing table with wrong column names
    echo "Dropping existing backalerts_2026_01_17 table...\n";
    DB::connection('pgsql')->statement("DROP TABLE IF EXISTS backalerts_2026_01_17");
    echo "Table dropped successfully\n";
    
    echo "\nThe table will be recreated automatically with correct column names when the sync service runs\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}