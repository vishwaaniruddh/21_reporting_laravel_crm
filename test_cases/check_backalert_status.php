<?php

require_once 'vendor/autoload.php';

// Load Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\BackAlertUpdateLog;
use Illuminate\Support\Facades\DB;

echo "=== BackAlert Update Log Status ===\n";

try {
    $pending = BackAlertUpdateLog::pending()->count();
    $completed = BackAlertUpdateLog::completed()->count();
    $failed = BackAlertUpdateLog::failed()->count();
    $total = BackAlertUpdateLog::count();
    
    echo "Pending: $pending\n";
    echo "Completed: $completed\n";
    echo "Failed: $failed\n";
    echo "Total: $total\n\n";
    
    // Check if backalerts_2026_01_17 table exists in PostgreSQL
    echo "=== PostgreSQL Partition Table Check ===\n";
    $tableExists = DB::connection('pgsql')
        ->select("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'backalerts_2026_01_17')");
    
    $exists = $tableExists[0]->exists ?? false;
    echo "backalerts_2026_01_17 table exists: " . ($exists ? 'YES' : 'NO') . "\n";
    
    if ($exists) {
        $count = DB::connection('pgsql')->table('backalerts_2026_01_17')->count();
        echo "Records in backalerts_2026_01_17: $count\n";
    }
    
    // Check recent update logs
    echo "\n=== Recent Update Logs (last 5) ===\n";
    $recentLogs = BackAlertUpdateLog::orderBy('id', 'desc')->limit(5)->get();
    
    foreach ($recentLogs as $log) {
        echo "ID: {$log->id}, BackAlert: {$log->backalert_id}, Status: {$log->getStatusName()}, Created: {$log->created_at}\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}