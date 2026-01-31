<?php
/**
 * Clear all PostgreSQL sync data (logs, errors, tracking, target tables)
 * ⚠️ DOES NOT TOUCH MYSQL - Only clears PostgreSQL
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "=== Clearing PostgreSQL Sync Data ===\n";
echo "⚠️  This will NOT touch MySQL data\n\n";

try {
    // 1. Clear sync_tracking table
    echo "1. Clearing sync_tracking table...\n";
    if (Schema::connection('pgsql')->hasTable('sync_tracking')) {
        DB::connection('pgsql')->table('sync_tracking')->truncate();
        echo "   ✓ sync_tracking truncated\n";
    } else {
        echo "   - sync_tracking table doesn't exist\n";
    }

    // 2. Clear table_sync_errors table
    echo "2. Clearing table_sync_errors table...\n";
    if (Schema::connection('pgsql')->hasTable('table_sync_errors')) {
        DB::connection('pgsql')->table('table_sync_errors')->truncate();
        echo "   ✓ table_sync_errors truncated\n";
    }

    // 3. Clear table_sync_logs table
    echo "3. Clearing table_sync_logs table...\n";
    if (Schema::connection('pgsql')->hasTable('table_sync_logs')) {
        DB::connection('pgsql')->table('table_sync_logs')->truncate();
        echo "   ✓ table_sync_logs truncated\n";
    }

    // 4. Clear target tables (alerts, alerts_acup, sites)
    echo "4. Clearing target data tables...\n";
    echo "   ⚠️  WARNING: This will DELETE ALL DATA from target tables!\n";
    echo "   ⚠️  For update sync, you typically DON'T want to do this.\n";
    echo "   ⚠️  Update sync should UPDATE existing records, not require full re-sync.\n";
    echo "   Skip this step? (y/n): ";
    
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    if (trim(strtolower($line)) !== 'y') {
        $targetTables = ['alerts', 'alerts_acup', 'sites'];
        foreach ($targetTables as $table) {
            if (Schema::connection('pgsql')->hasTable($table)) {
                DB::connection('pgsql')->table($table)->truncate();
                echo "   ✓ {$table} truncated\n";
            } else {
                echo "   - {$table} doesn't exist\n";
            }
        }
    } else {
        echo "   ⊘ Skipped truncating target tables (data preserved)\n";
    }

    // 5. Reset configuration status
    echo "5. Resetting configuration status...\n";
    if (Schema::connection('pgsql')->hasTable('table_sync_configurations')) {
        DB::connection('pgsql')->table('table_sync_configurations')->update([
            'last_sync_status' => 'idle',
            'last_sync_at' => null,
        ]);
        echo "   ✓ Configuration status reset to idle\n";
    }

    // 6. Clear cache
    echo "6. Clearing application cache...\n";
    \Illuminate\Support\Facades\Cache::flush();
    echo "   ✓ Cache cleared\n";

    echo "\n=== PostgreSQL Cleanup Complete ===\n";
    echo "MySQL data remains untouched.\n";
    echo "You can now run a fresh sync.\n";

} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
