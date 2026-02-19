<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Checking Column Names ===\n\n";

// Check alerts partition
echo "Columns in alerts_2026_02_15:\n";
$alertsColumns = DB::connection('pgsql')->select("
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 'alerts_2026_02_15'
    ORDER BY ordinal_position
");

foreach ($alertsColumns as $col) {
    if (stripos($col->column_name, 'closed') !== false) {
        echo "  - {$col->column_name}\n";
    }
}

echo "\n";

// Check backalerts partition
echo "Columns in backalerts_2026_02_15:\n";
$backalertsColumns = DB::connection('pgsql')->select("
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 'backalerts_2026_02_15'
    ORDER BY ordinal_position
");

foreach ($backalertsColumns as $col) {
    if (stripos($col->column_name, 'closed') !== false) {
        echo "  - {$col->column_name}\n";
    }
}

echo "\n=== Done ===\n";
