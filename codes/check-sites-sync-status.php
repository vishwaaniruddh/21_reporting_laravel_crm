<?php
/**
 * Check Sites Update Sync Status
 */

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\SitesUpdateSyncService;
use Illuminate\Support\Facades\DB;

echo "=== Sites Update Sync Status ===\n\n";

$service = new SitesUpdateSyncService();

// Get status summary
$summary = $service->getStatusSummary();

echo "Log Entry Status:\n";
echo "  Pending:   " . number_format($summary['pending']) . "\n";
echo "  Completed: " . number_format($summary['completed']) . "\n";
echo "  Failed:    " . number_format($summary['failed']) . "\n";

if ($summary['oldest_pending']) {
    echo "  Oldest Pending: " . $summary['oldest_pending'] . "\n";
}

echo "\n";

// Get breakdown by table
echo "Breakdown by Table:\n";
$breakdown = DB::connection('mysql')
    ->table('sites_pg_update_log')
    ->selectRaw('table_name, status, COUNT(*) as count')
    ->groupBy('table_name', 'status')
    ->orderBy('table_name')
    ->orderBy('status')
    ->get();

$statusNames = [
    1 => 'Pending',
    2 => 'Completed',
    3 => 'Failed',
];

$currentTable = null;
foreach ($breakdown as $row) {
    if ($currentTable !== $row->table_name) {
        if ($currentTable !== null) {
            echo "\n";
        }
        echo "  {$row->table_name}:\n";
        $currentTable = $row->table_name;
    }
    $statusName = $statusNames[$row->status] ?? "Unknown({$row->status})";
    echo "    {$statusName}: " . number_format($row->count) . "\n";
}

echo "\n";

// Recent entries
echo "Recent Log Entries (last 10):\n";
$recent = DB::connection('mysql')
    ->table('sites_pg_update_log')
    ->orderBy('id', 'desc')
    ->limit(10)
    ->get();

if ($recent->isEmpty()) {
    echo "  No entries found\n";
} else {
    foreach ($recent as $entry) {
        $statusName = $statusNames[$entry->status] ?? "Unknown";
        echo sprintf(
            "  ID=%d | %s | %s=%d | %s | %s\n",
            $entry->id,
            $entry->table_name,
            $entry->table_name === 'dvronline' ? 'id' : 'SN',
            $entry->record_id,
            $statusName,
            $entry->created_at
        );
    }
}

echo "\n";

// Failed entries with errors
$failed = DB::connection('mysql')
    ->table('sites_pg_update_log')
    ->where('status', 3)
    ->orderBy('id', 'desc')
    ->limit(5)
    ->get();

if ($failed->isNotEmpty()) {
    echo "Recent Failed Entries:\n";
    foreach ($failed as $entry) {
        echo sprintf(
            "  ID=%d | %s=%d | Retries=%d\n",
            $entry->id,
            $entry->table_name,
            $entry->record_id,
            $entry->retry_count
        );
        if ($entry->error_message) {
            echo "    Error: " . substr($entry->error_message, 0, 100) . "\n";
        }
    }
    echo "\n";
}

// Database sync status
echo "Database Record Counts:\n";
$tables = ['sites', 'dvrsite', 'dvronline'];
foreach ($tables as $table) {
    $mysqlCount = DB::connection('mysql')->table($table)->count();
    $pgCount = DB::connection('pgsql')->table($table)->count();
    $diff = $mysqlCount - $pgCount;
    $status = $diff === 0 ? '✓' : '✗';
    
    echo sprintf(
        "  %s %s: MySQL=%d, PostgreSQL=%d (diff=%+d)\n",
        $status,
        $table,
        $mysqlCount,
        $pgCount,
        $diff
    );
}

echo "\n=== End of Status Report ===\n";
