<?php

namespace App\Console\Commands;

use App\Services\SitesSyncService;
use Illuminate\Console\Command;

/**
 * Artisan command to sync sites, dvrsite, dvronline tables from MySQL to PostgreSQL.
 * 
 * ⚠️ NO DELETION FROM MYSQL: Only SELECT operations on MySQL
 */
class SitesSyncCommand extends Command
{
    protected $signature = 'sites:sync 
                            {table? : Table to sync (sites, dvrsite, dvronline). Omit for all tables}
                            {--incremental : Only sync new/updated records}
                            {--status : Show sync status only}
                            {--reset : Reset sync markers to force full re-sync}';

    protected $description = 'Sync sites, dvrsite, dvronline tables from MySQL to PostgreSQL';

    public function handle(SitesSyncService $syncService): int
    {
        // Handle status check
        if ($this->option('status')) {
            return $this->showStatus($syncService);
        }

        // Handle reset
        if ($this->option('reset')) {
            return $this->resetMarkers($syncService);
        }

        $table = $this->argument('table');
        $incremental = $this->option('incremental');

        if ($table) {
            // Sync specific table
            return $this->syncTable($syncService, $table, $incremental);
        }

        // Sync all tables
        return $this->syncAllTables($syncService, $incremental);
    }

    protected function syncTable(SitesSyncService $syncService, string $table, bool $incremental): int
    {
        $validTables = ['sites', 'dvrsite', 'dvronline'];
        
        if (!in_array($table, $validTables)) {
            $this->error("Invalid table: {$table}");
            $this->info("Valid tables: " . implode(', ', $validTables));
            return Command::FAILURE;
        }

        $this->info("Syncing {$table}...");
        $this->newLine();

        if ($incremental) {
            $result = $syncService->syncIncremental($table);
        } else {
            $result = $syncService->syncTable($table);
        }

        $this->displayResult($table, $result);

        return $result->success ? Command::SUCCESS : Command::FAILURE;
    }

    protected function syncAllTables(SitesSyncService $syncService, bool $incremental): int
    {
        $this->info("Syncing all tables...");
        $this->newLine();

        $tables = ['sites', 'dvrsite', 'dvronline'];
        $allSuccess = true;

        foreach ($tables as $table) {
            $this->info("=== {$table} ===");
            
            if ($incremental) {
                $result = $syncService->syncIncremental($table);
            } else {
                $result = $syncService->syncTable($table);
            }

            $this->displayResult($table, $result);
            $this->newLine();

            if (!$result->success) {
                $allSuccess = false;
            }
        }

        return $allSuccess ? Command::SUCCESS : Command::FAILURE;
    }

    protected function showStatus(SitesSyncService $syncService): int
    {
        $this->info("=== Sites Sync Status ===");
        $this->newLine();

        $status = $syncService->getStatus();

        $tableData = [];
        foreach ($status as $table => $data) {
            $tableData[] = [
                $table,
                number_format($data['mysql_count']),
                number_format($data['postgres_count']),
                number_format($data['unsynced_count']),
                $data['in_sync'] ? '✓' : '✗',
            ];
        }

        $this->table(
            ['Table', 'MySQL', 'PostgreSQL', 'Unsynced', 'In Sync'],
            $tableData
        );

        return Command::SUCCESS;
    }

    protected function resetMarkers(SitesSyncService $syncService): int
    {
        $table = $this->argument('table');

        if (!$table) {
            $this->error("Please specify a table to reset: sites, dvrsite, or dvronline");
            return Command::FAILURE;
        }

        if (!$this->confirm("This will reset sync markers for {$table}. Continue?")) {
            return Command::SUCCESS;
        }

        if ($syncService->resetSyncMarkers($table)) {
            $this->info("Sync markers reset for {$table}");
            return Command::SUCCESS;
        }

        $this->error("Failed to reset sync markers for {$table}");
        return Command::FAILURE;
    }

    protected function displayResult(string $table, $result): void
    {
        if ($result->success) {
            $this->info("✓ {$table} sync completed");
        } else {
            $this->error("✗ {$table} sync failed");
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Inserted', number_format($result->inserted)],
                ['Updated', number_format($result->updated)],
                ['Failed', number_format($result->failed)],
            ]
        );

        if ($result->errorMessage) {
            $this->error("Error: {$result->errorMessage}");
        }

        if ($result->message) {
            $this->info($result->message);
        }
    }
}
