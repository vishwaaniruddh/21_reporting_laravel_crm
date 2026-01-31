<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "=== BackAlerts Partitioned Tables ===\n";
    $backAlertTables = DB::connection('pgsql')->select(
        "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'backalerts_%' ORDER BY tablename"
    );
    
    if (empty($backAlertTables)) {
        echo "No backalerts partitioned tables found.\n";
    } else {
        foreach($backAlertTables as $table) {
            echo "- {$table->tablename}\n";
        }
    }
    
    echo "\n=== Alerts Partitioned Tables ===\n";
    $alertTables = DB::connection('pgsql')->select(
        "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'alerts_%' ORDER BY tablename"
    );
    
    if (empty($alertTables)) {
        echo "No alerts partitioned tables found.\n";
    } else {
        foreach($alertTables as $table) {
            echo "- {$table->tablename}\n";
        }
    }
    
    echo "\n=== Sample Schema Comparison ===\n";
    if (!empty($alertTables)) {
        $alertTable = $alertTables[0]->tablename;
        echo "Checking schema for: {$alertTable}\n";
        $alertColumns = DB::connection('pgsql')->select(
            "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position",
            [$alertTable]
        );
        echo "Alert columns: " . count($alertColumns) . "\n";
    }
    
    if (!empty($backAlertTables)) {
        $backAlertTable = $backAlertTables[0]->tablename;
        echo "Checking schema for: {$backAlertTable}\n";
        $backAlertColumns = DB::connection('pgsql')->select(
            "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position",
            [$backAlertTable]
        );
        echo "BackAlert columns: " . count($backAlertColumns) . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}