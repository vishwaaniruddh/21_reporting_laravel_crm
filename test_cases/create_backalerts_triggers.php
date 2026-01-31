<?php

require_once 'vendor/autoload.php';

// Load Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Creating BackAlerts Triggers ===\n";

try {
    // Read and execute the SQL file
    $sql = file_get_contents('database/sql/create_backalerts_triggers.sql');
    
    // Split by delimiter and execute each statement
    $statements = explode('$$', $sql);
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement) || strpos($statement, '--') === 0 || strpos($statement, 'DELIMITER') === 0) {
            continue;
        }
        
        if (!empty($statement)) {
            try {
                DB::connection('mysql')->statement($statement);
                echo "✓ Executed: " . substr($statement, 0, 50) . "...\n";
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Trigger') !== false) {
                    echo "ℹ Trigger statement: " . $e->getMessage() . "\n";
                } else {
                    echo "✗ Error: " . $e->getMessage() . "\n";
                }
            }
        }
    }
    
    echo "\n=== Verifying Triggers ===\n";
    $triggers = DB::connection('mysql')->select("SHOW TRIGGERS LIKE 'backalerts'");
    
    if (count($triggers) > 0) {
        echo "✓ Found " . count($triggers) . " triggers:\n";
        foreach ($triggers as $trigger) {
            echo "  - {$trigger->Trigger} ({$trigger->Event})\n";
        }
    } else {
        echo "✗ No triggers found\n";
    }
    
    echo "\n=== Verifying Table Structure ===\n";
    $columns = DB::connection('mysql')->select('DESCRIBE backalert_pg_update_log');
    echo "backalert_pg_update_log columns:\n";
    foreach ($columns as $column) {
        echo "  - {$column->Field} ({$column->Type})\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}