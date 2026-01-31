<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Schema Comparison: Alerts vs BackAlerts ===\n\n";

try {
    // Get alerts table schema
    $alertsColumns = DB::connection('pgsql')->select(
        "SELECT column_name, data_type, is_nullable, column_default 
         FROM information_schema.columns 
         WHERE table_name = 'alerts_2026_01_27' 
         ORDER BY ordinal_position"
    );
    
    // Get backalerts table schema
    $backAlertsColumns = DB::connection('pgsql')->select(
        "SELECT column_name, data_type, is_nullable, column_default 
         FROM information_schema.columns 
         WHERE table_name = 'backalerts_2026_01_27' 
         ORDER BY ordinal_position"
    );
    
    echo "Alerts table columns (" . count($alertsColumns) . "):\n";
    foreach ($alertsColumns as $i => $col) {
        echo sprintf("%2d. %-20s %s\n", $i + 1, $col->column_name, $col->data_type);
    }
    
    echo "\nBackAlerts table columns (" . count($backAlertsColumns) . "):\n";
    foreach ($backAlertsColumns as $i => $col) {
        echo sprintf("%2d. %-20s %s\n", $i + 1, $col->column_name, $col->data_type);
    }
    
    // Find differences
    $alertsColumnNames = array_column($alertsColumns, 'column_name');
    $backAlertsColumnNames = array_column($backAlertsColumns, 'column_name');
    
    $onlyInAlerts = array_diff($alertsColumnNames, $backAlertsColumnNames);
    $onlyInBackAlerts = array_diff($backAlertsColumnNames, $alertsColumnNames);
    
    echo "\n=== Differences ===\n";
    
    if (!empty($onlyInAlerts)) {
        echo "Columns only in alerts table:\n";
        foreach ($onlyInAlerts as $col) {
            echo "- {$col}\n";
        }
    }
    
    if (!empty($onlyInBackAlerts)) {
        echo "Columns only in backalerts table:\n";
        foreach ($onlyInBackAlerts as $col) {
            echo "- {$col}\n";
        }
    }
    
    if (empty($onlyInAlerts) && empty($onlyInBackAlerts)) {
        echo "✅ Both tables have the same columns!\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}