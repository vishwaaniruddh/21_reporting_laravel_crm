<?php
/**
 * Check Update Sync Status
 * Shows pending and completed update sync entries
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Update Sync Status ===\n\n";

// Get counts from alert_pg_update_log
$pending = DB::connection('mysql')
    ->table('alert_pg_update_log')
    ->where('status', 1)
    ->count();

$completed = DB::connection('mysql')
    ->table('alert_pg_update_log')
    ->where('status', 2)
    ->count();

$failed = DB::connection('mysql')
    ->table('alert_pg_update_log')
    ->where('status', 3)
    ->count();

$total = $pending + $completed + $failed;

echo "Pending:   {$pending}\n";
echo "Completed: {$completed}\n";
echo "Failed:    {$failed}\n";
echo "Total:     {$total}\n\n";

if ($pending > 0) {
    echo "✓ Update sync is processing {$pending} pending entries\n";
} else {
    echo "✓ No pending updates - system is up to date\n";
}

if ($completed > 0) {
    echo "✓ Successfully completed {$completed} updates\n";
}

if ($failed > 0) {
    echo "⚠ {$failed} updates failed - check logs\n";
}

echo "\n";

// Show recent completed updates
echo "Recent Completed Updates (last 5):\n";
$recent = DB::connection('mysql')
    ->table('alert_pg_update_log')
    ->where('status', 2)
    ->orderBy('updated_at', 'desc')
    ->limit(5)
    ->get(['alert_id', 'updated_at']);

if ($recent->count() > 0) {
    foreach ($recent as $entry) {
        echo "  Alert {$entry->alert_id} - {$entry->updated_at}\n";
    }
} else {
    echo "  No completed updates yet\n";
}

echo "\n=== Check Complete ===\n";
