<?php

namespace App\Console\Commands;

use App\Jobs\TableSyncJob;
use App\Services\GenericSyncService;
use App\Services\GenericSyncServiceV2;
use App\Models\TableSyncConfiguration;
use Illuminate\Console\Command;

/**
 * Artisan command to manually trigger table sync jobs.
 * 
 * This command allows administrators to manually trigger the sync process
 * for specific tables or all configured tables.
 * 
 * ⚠️ NO DELETION FROM MYSQL: Command syncs data, never deletes from MySQL
 * 
 * Requirements: 5.1, 5.6
 */
class RunTableSyncJob extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'table-sync:run 
                            {table? : Table name or configuration ID to sync}
                            {--all : Sync all enabled tables}
                            {--sync : Run synchronously instead of dispatching to queue}
                            {--status : Show current sync status for all tables}';

    /**
     * The console command description.
     */
    protected $description = 'Trigger table sync job(s) from MySQL to PostgreSQL';

    /**
     * Execute the console command.
     * 
     * @param GenericSyncService|GenericSyncServiceV2 $syncService
     */
    public function handle(GenericSyncService $syncService): int
    {
        // Handle status check
        if ($this->option('status')) {
            return $this->showStatus($syncService);
        }

        // Handle --all flag
        if ($this->option('all')) {
            return $this->syncAllTables($syncService);
        }

        // Handle specific table
        $table = $this->argument('table');
        if ($table) {
            return $this->syncSpecificTable($syncService, $table);
        }

        // No arguments provided - show help
        $this->info('Usage:');
        $this->line('  table-sync:run <table>     Sync a specific table by name or config ID');
        $this->line('  table-sync:run --all       Sync all enabled tables');
        $this->line('  table-sync:run --status    Show sync status for all tables');
        $this->newLine();
        $this->info('Options:');
        $this->line('  --sync                     Run synchronously instead of dispatching to queue');
        $this->newLine();

        // List available configurations
        return $this->listConfigurations();
    }

    /**
     * Sync a specific table by name or configuration ID.
     */
    protected function syncSpecificTable(GenericSyncService $syncService, string $table): int
    {
        // Find configuration
        $config = $this->findConfiguration($table);

        if (!$config) {
            $this->error("Configuration not found for: {$table}");
            return Command::FAILURE;
        }

        if (!$config->is_enabled) {
            $this->warn("Sync is disabled for table: {$config->source_table}");
            if (!$this->confirm('Do you want to sync anyway?')) {
                return Command::SUCCESS;
            }
        }

        // Show current state
        $unsyncedCount = $syncService->getUnsyncedCount($config->id);
        $this->info("Configuration: {$config->name}");
        $this->info("Source table: {$config->source_table}");
        $this->info("Target table: {$config->getEffectiveTargetTable()}");
        $this->info("Unsynced records: {$unsyncedCount}");
        $this->newLine();

        if ($unsyncedCount === 0) {
            $this->info('No records to sync.');
            return Command::SUCCESS;
        }

        if ($this->option('sync')) {
            // Run synchronously
            $this->info('Running sync synchronously...');
            $this->newLine();

            $result = $syncService->syncTable($config->id);

            $this->displayResult($config, $result);
        } else {
            // Dispatch to queue
            TableSyncJob::dispatch($config->id);
            $this->info("Sync job dispatched to queue for table: {$config->source_table}");
        }

        return Command::SUCCESS;
    }

    /**
     * Sync all enabled tables.
     */
    protected function syncAllTables(GenericSyncService $syncService): int
    {
        $configs = TableSyncConfiguration::enabled()->get();

        if ($configs->isEmpty()) {
            $this->warn('No enabled table sync configurations found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$configs->count()} enabled configuration(s)");
        $this->newLine();

        if ($this->option('sync')) {
            // Run synchronously
            $this->info('Running all syncs synchronously...');
            $this->newLine();

            $results = $syncService->syncAllTables();

            foreach ($configs as $config) {
                if (isset($results[$config->id])) {
                    $this->displayResult($config, $results[$config->id]);
                    $this->newLine();
                }
            }
        } else {
            // Dispatch to queue
            foreach ($configs as $config) {
                $unsyncedCount = $syncService->getUnsyncedCount($config->id);
                TableSyncJob::dispatch($config->id);
                $this->info("Dispatched sync job for: {$config->source_table} ({$unsyncedCount} unsynced records)");
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Show current sync status for all tables.
     */
    protected function showStatus(GenericSyncService $syncService): int
    {
        $configs = TableSyncConfiguration::all();

        if ($configs->isEmpty()) {
            $this->warn('No table sync configurations found.');
            return Command::SUCCESS;
        }

        $this->info('=== Table Sync Status ===');
        $this->newLine();

        $tableData = [];
        foreach ($configs as $config) {
            $unsyncedCount = $syncService->getUnsyncedCount($config->id);
            $status = $syncService->getSyncStatus($config->id);
            $jobStatus = TableSyncJob::getStatus($config->id);

            $tableData[] = [
                $config->id,
                $config->name,
                $config->source_table,
                $config->is_enabled ? '✓' : '✗',
                number_format($unsyncedCount),
                $status,
                $jobStatus['status'] ?? 'idle',
                $config->last_sync_at?->format('Y-m-d H:i') ?? 'Never',
            ];
        }

        $this->table(
            ['ID', 'Name', 'Source Table', 'Enabled', 'Unsynced', 'Sync Status', 'Job Status', 'Last Sync'],
            $tableData
        );

        return Command::SUCCESS;
    }

    /**
     * List available configurations.
     */
    protected function listConfigurations(): int
    {
        $configs = TableSyncConfiguration::all();

        if ($configs->isEmpty()) {
            $this->warn('No table sync configurations found.');
            $this->info('Create a configuration using the API or dashboard first.');
            return Command::SUCCESS;
        }

        $this->info('Available configurations:');
        $this->newLine();

        $tableData = [];
        foreach ($configs as $config) {
            $tableData[] = [
                $config->id,
                $config->name,
                $config->source_table,
                $config->is_enabled ? 'Yes' : 'No',
                $config->schedule ?? 'Manual',
            ];
        }

        $this->table(
            ['ID', 'Name', 'Source Table', 'Enabled', 'Schedule'],
            $tableData
        );

        return Command::SUCCESS;
    }

    /**
     * Find configuration by ID or source table name.
     */
    protected function findConfiguration(string $identifier): ?TableSyncConfiguration
    {
        // Try by ID first
        if (is_numeric($identifier)) {
            $config = TableSyncConfiguration::find((int) $identifier);
            if ($config) {
                return $config;
            }
        }

        // Try by source table name
        return TableSyncConfiguration::where('source_table', $identifier)->first();
    }

    /**
     * Display sync result.
     */
    protected function displayResult(TableSyncConfiguration $config, $result): void
    {
        $this->info("=== Sync Result: {$config->source_table} ===");

        if ($result->success) {
            $this->info('Status: SUCCESS');
        } else {
            $this->error('Status: FAILED');
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Records Synced', number_format($result->recordsSynced)],
                ['Records Failed', number_format($result->recordsFailed)],
                ['Start ID', $result->startId ?? 'N/A'],
                ['End ID', $result->endId ?? 'N/A'],
            ]
        );

        if ($result->errorMessage) {
            $this->error("Error: {$result->errorMessage}");
        }
    }
}
