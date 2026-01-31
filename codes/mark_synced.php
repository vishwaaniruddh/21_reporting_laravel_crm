<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Marking 2026-01-08 records as synced in MySQL...\n";

$affected = DB::connection('mysql')
    ->table('alerts')
    ->whereDate('receivedtime', '2026-01-08')
    ->whereNull('synced_at')
    ->update(['synced_at' => now()]);

echo "Marked {$affected} records as synced.\n";

echo "\nNow syncing remaining records...\n";
echo "Run: php artisan sync:partitioned --batch-size=500 --continuous\n";
