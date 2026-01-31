<?php
/**
 * Setup Sites Table Sync Configuration
 * 
 * Creates a sync configuration to keep PostgreSQL sites table
 * in sync with MySQL sites table for ongoing changes.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\TableSyncConfiguration;

echo "=== Setting up Sites Table Sync ===\n\n";

// Check if configuration already exists
$existing = TableSyncConfiguration::where('source_table', 'sites')->first();

if ($existing) {
    echo "⚠️ Sites sync configuration already exists (ID: {$existing->id})\n";
    echo "Name: {$existing->name}\n";
    echo "Enabled: " . ($existing->is_enabled ? 'Yes' : 'No') . "\n";
    echo "Last sync: " . ($existing->last_sync_at ? $existing->last_sync_at->format('Y-m-d H:i:s') : 'Never') . "\n\n";
    
    echo "Do you want to update it? (yes/no): ";
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    fclose($handle);
    
    if (strtolower($line) !== 'yes') {
        echo "Aborted.\n";
        exit(0);
    }
    
    $config = $existing;
} else {
    $config = new TableSyncConfiguration();
}

// Configure sites sync
$config->fill([
    'name' => 'Sites Table Sync',
    'source_table' => 'sites',
    'target_table' => 'sites', // Same name in PostgreSQL
    'primary_key_column' => 'SN', // Adjust if different
    'sync_marker_column' => 'synced_at', // Will be added to MySQL table
    'column_mappings' => [], // No column name changes needed
    'excluded_columns' => [], // Sync all columns
    'batch_size' => 1000, // Sites table is smaller than alerts
    'schedule' => '*/15 * * * *', // Every 15 minutes
    'is_enabled' => true,
    'last_sync_status' => 'idle',
]);

$config->save();

echo "✅ Sites sync configuration created/updated successfully!\n\n";
echo "Configuration Details:\n";
echo "- ID: {$config->id}\n";
echo "- Name: {$config->name}\n";
echo "- Source: MySQL sites table\n";
echo "- Target: PostgreSQL sites table\n";
echo "- Batch Size: {$config->batch_size}\n";
echo "- Schedule: {$config->schedule} (every 15 minutes)\n";
echo "- Enabled: " . ($config->is_enabled ? 'Yes' : 'No') . "\n\n";

echo "=== Next Steps ===\n";
echo "1. Add 'synced_at' column to MySQL sites table:\n";
echo "   ALTER TABLE sites ADD COLUMN synced_at TIMESTAMP NULL;\n\n";
echo "2. Test the sync manually:\n";
echo "   php artisan table-sync:run sites --sync\n\n";
echo "3. Check sync status:\n";
echo "   php artisan table-sync:run --status\n\n";
echo "4. The sync will run automatically every 15 minutes via scheduler\n";
echo "   Make sure Laravel scheduler is running:\n";
echo "   php artisan schedule:work\n\n";

