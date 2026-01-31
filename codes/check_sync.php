<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== PostgreSQL Partition Tables ===\n\n";

$tables = DB::connection('pgsql')->select("
    SELECT tablename 
    FROM pg_tables 
    WHERE schemaname = 'public' 
    AND tablename LIKE 'alerts_%' 
    ORDER BY tablename
");

foreach ($tables as $table) {
    $count = DB::connection('pgsql')->table($table->tablename)->count();
    echo "{$table->tablename}: " . number_format($count) . " records\n";
}

echo "\n=== MySQL Source Data ===\n\n";

$mysqlCounts = DB::connection('mysql')->select("
    SELECT DATE(receivedtime) as date, COUNT(*) as count 
    FROM alerts 
    WHERE DATE(receivedtime) >= '2026-01-08'
    GROUP BY DATE(receivedtime)
    ORDER BY date
");

foreach ($mysqlCounts as $row) {
    echo "{$row->date}: " . number_format($row->count) . " records\n";
}

echo "\n=== Sync Progress Summary ===\n\n";

$totalPostgres = 0;
foreach ($tables as $table) {
    if (strpos($table->tablename, 'alerts_2026') === 0) {
        $count = DB::connection('pgsql')->table($table->tablename)->count();
        $totalPostgres += $count;
    }
}

$totalMysql = 0;
foreach ($mysqlCounts as $row) {
    $totalMysql += $row->count;
}

echo "Total MySQL records (2026-01-08 onwards): " . number_format($totalMysql) . "\n";
echo "Total PostgreSQL records (2026 partitions): " . number_format($totalPostgres) . "\n";
echo "Remaining to sync: " . number_format($totalMysql - $totalPostgres) . "\n";
echo "Sync completion: " . number_format(($totalPostgres / $totalMysql) * 100, 2) . "%\n";
