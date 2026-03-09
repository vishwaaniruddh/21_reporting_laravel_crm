<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$columns = DB::connection('pgsql')->select(
    'SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position',
    ['alerts_2026_03_06']
);

echo "Columns in alerts_2026_03_06:\n";
foreach($columns as $col) {
    echo "  - {$col->column_name}\n";
}
