<?php

require_once 'vendor/autoload.php';

// Load Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\BackAlertUpdateLog;

echo "=== Latest BackAlert Update Log Error ===\n";

try {
    // Get the most recent failed log
    $latestFailed = BackAlertUpdateLog::failed()
        ->orderBy('updated_at', 'desc')
        ->first();
    
    if ($latestFailed) {
        echo "Latest failed log:\n";
        echo "ID: {$latestFailed->id}\n";
        echo "BackAlert ID: {$latestFailed->backalert_id}\n";
        echo "Updated: {$latestFailed->updated_at}\n";
        echo "Error: " . ($latestFailed->error_message ?? 'No error message') . "\n";
    } else {
        echo "No failed logs found\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}