<?php

require_once 'vendor/autoload.php';

// Load Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Alerts Table Triggers ===\n";

try {
    $triggers = DB::connection('mysql')->select("SHOW TRIGGERS LIKE 'alerts'");
    
    if (count($triggers) > 0) {
        echo "Found " . count($triggers) . " triggers on alerts table:\n";
        foreach ($triggers as $trigger) {
            echo "- {$trigger->Trigger} ({$trigger->Event})\n";
        }
    } else {
        echo "No triggers found on alerts table\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}