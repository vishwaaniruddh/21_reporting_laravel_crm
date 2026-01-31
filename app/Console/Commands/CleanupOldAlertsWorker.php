<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Cleanup Old Alerts Worker
 * 
 * Continuously monitors and deletes old records from MySQL alerts table.
 * This worker runs indefinitely, checking periodically for records older
 * than the configured retention period.
 * 
 * ⚠️ CRITICAL: This DELETES records from MySQL - use with caution!
 * 
 * CONFIGURATION (Edit these values):
 * - Table name: Change $tableName property
 * - Retention days: Change $retentionDays property or use --retention-days option
 * - Check interval: Use --check-interval option (default: 3600 seconds = 1 hour)
 */
class CleanupOldAlertsWorker extends Command
{
    /**
     * ============================================
     * CONFIGURATION - EDIT THESE VALUES
     * ============================================
     */
    
    /**
     * TABLE NAME - Change this to target different table
     * 
     * Examples:
     * - 'alerts_2' (default)
     * - 'alerts'
     * - 'alerts_backup'
     */
    protected string $tableName = 'alerts';
    
    /**
     * RETENTION HOURS - Records older than this will be deleted
     * 
     * Examples:
     * - 48 (delete records older than 48 hours = 2 days) [DEFAULT]
     * - 24 (delete records older than 24 hours = 1 day)
     * - 168 (delete records older than 168 hours = 7 days)
     * - 720 (delete records older than 720 hours = 30 days)
     */
    protected int $retentionHours = 48;
    
    /**
     * ============================================
     * END CONFIGURATION
     * ============================================
     */

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cleanup:old-alerts-worker 
                            {--check-interval=3600 : Seconds between cleanup checks (default: 1 hour)}
                            {--retention-hours= : Override retention hours from config}
                            {--table= : Override table name from config}
                            {--batch-size=100 : Number of records to delete per batch}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Continuously cleanup old records from MySQL alerts table';

    /**
     * Flag to control the worker loop.
     */
    protected bool $shouldContinue = true;

    /**
     * Configuration values.
     */
    protected int $checkInterval;
    protected int $batchSize;
    protected bool $dryRun;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Parse configuration from options (command line overrides class properties)
        $this->checkInterval = (int) $this->option('check-interval');
        $this->batchSize = (int) $this->option('batch-size');
        $this->dryRun = $this->option('dry-run');
        
        // Override table name if provided
        if ($this->option('table')) {
            $this->tableName = $this->option('table');
        }
        
        // Override retention hours if provided
        if ($this->option('retention-hours')) {
            $this->retentionHours = (int) $this->option('retention-hours');
        }

        // Register signal handlers for graceful shutdown
        $this->registerSignalHandlers();

        // Log worker startup
        $this->logStartup();

        // Main processing loop
        $this->info('Cleanup worker started. Press Ctrl+C to stop gracefully.');
        $this->newLine();

        while ($this->shouldContinue()) {
            try {
                $this->processOneCleanupCycle();
            } catch (\Exception $e) {
                $this->error("Error in cleanup cycle: {$e->getMessage()}");
                Log::error('Cleanup worker cycle error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                // Sleep before retrying to avoid tight error loops
                sleep($this->checkInterval);
            }
        }

        // Log worker shutdown
        $this->logShutdown();

        return Command::SUCCESS;
    }

    /**
     * Process one complete cleanup cycle.
     */
    protected function processOneCleanupCycle(): void
    {
        $cycleStartTime = microtime(true);

        // Calculate cutoff date (subtract hours from now)
        $cutoffDate = Carbon::now()->subHours($this->retentionHours);
        
        $this->info("Checking for records older than {$cutoffDate->toDateTimeString()} ({$this->retentionHours} hours ago)...");

        // Count records to delete
        $totalToDelete = DB::connection('mysql')
            ->table($this->tableName)
            ->where('receivedtime', '<', $cutoffDate)
            ->count();

        if ($totalToDelete === 0) {
            $this->line("No records to delete. Next check in {$this->checkInterval} seconds...");
            sleep($this->checkInterval);
            return;
        }

        $this->warn("Found {$totalToDelete} records to delete from table '{$this->tableName}'");

        if ($this->dryRun) {
            $this->info("DRY RUN MODE - No records will be deleted");
            $this->newLine();
            sleep($this->checkInterval);
            return;
        }

        // Confirm deletion
        $this->newLine();
        $this->warn("⚠️  WARNING: This will DELETE {$totalToDelete} records from MySQL table '{$this->tableName}'!");
        $this->warn("⚠️  Records older than {$this->retentionHours} hours (before {$cutoffDate->toDateTimeString()}) will be permanently deleted.");
        $this->newLine();

        // Delete in batches
        $totalDeleted = 0;
        $batchNumber = 0;

        while (true) {
            $batchNumber++;
            
            // Get IDs of oldest records to delete (ORDER BY id ASC - oldest first)
            $idsToDelete = DB::connection('mysql')
                ->table($this->tableName)
                ->where('receivedtime', '<', $cutoffDate)
                ->orderBy('id', 'asc')  // Delete oldest records first
                ->limit($this->batchSize)
                ->pluck('id');

            if ($idsToDelete->isEmpty()) {
                break;
            }

            // Delete the batch by IDs
            $deleted = DB::connection('mysql')
                ->table($this->tableName)
                ->whereIn('id', $idsToDelete)
                ->delete();

            $totalDeleted += $deleted;
            
            $this->line("  Batch {$batchNumber}: Deleted {$deleted} records (Total: {$totalDeleted}/{$totalToDelete})");
            
            // Log::info('Cleanup batch completed', [
            //     'table' => $this->tableName,
            //     'batch_number' => $batchNumber,
            //     'deleted' => $deleted,
            //     'total_deleted' => $totalDeleted,
            //     'cutoff_date' => $cutoffDate->toDateTimeString(),
            // ]);

            // IMPORTANT: Sleep between batches to prevent MySQL from crashing
            // This gives MySQL time to recover and prevents table lock issues
            sleep(2); // 2 seconds delay between batches
        }

        $cycleDuration = microtime(true) - $cycleStartTime;

        $this->info("Cleanup complete: {$totalDeleted} records deleted in " . round($cycleDuration, 2) . " seconds");
        $this->newLine();

        Log::info('Cleanup cycle completed', [
            'table' => $this->tableName,
            'total_deleted' => $totalDeleted,
            'duration' => $cycleDuration,
            'retention_hours' => $this->retentionHours,
            'cutoff_date' => $cutoffDate->toDateTimeString(),
        ]);

        // Sleep before next cycle
        $this->line("Next cleanup check in {$this->checkInterval} seconds...");
        sleep($this->checkInterval);
    }

    /**
     * Register signal handlers for graceful shutdown.
     */
    protected function registerSignalHandlers(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);

            pcntl_signal(SIGTERM, function () {
                $this->info('Received SIGTERM. Shutting down gracefully...');
                $this->shouldContinue = false;
            });

            pcntl_signal(SIGINT, function () {
                $this->info('Received SIGINT. Shutting down gracefully...');
                $this->shouldContinue = false;
            });
        } else {
            $this->warn('PCNTL extension not loaded. Graceful shutdown signals not available.');
        }
    }

    /**
     * Check if the worker should continue running.
     */
    protected function shouldContinue(): bool
    {
        return $this->shouldContinue;
    }

    /**
     * Log worker startup with configuration.
     */
    protected function logStartup(): void
    {
        $config = [
            'table_name' => $this->tableName,
            'retention_hours' => $this->retentionHours,
            'check_interval' => $this->checkInterval,
            'batch_size' => $this->batchSize,
            'dry_run' => $this->dryRun,
        ];

        Log::info('Cleanup worker started', $config);

        $this->info('=== Cleanup Worker Configuration ===');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Table Name', $this->tableName],
                ['Retention Hours', $this->retentionHours . ' hours (' . round($this->retentionHours / 24, 1) . ' days)'],
                ['Check Interval', "{$this->checkInterval} seconds"],
                ['Batch Size', $this->batchSize],
                ['Dry Run', $this->dryRun ? 'Yes' : 'No'],
            ]
        );
        $this->newLine();
        
        $cutoffDate = Carbon::now()->subHours($this->retentionHours);
        $this->warn("⚠️  Records older than {$cutoffDate->toDateTimeString()} ({$this->retentionHours} hours ago) will be deleted from '{$this->tableName}'");
        $this->newLine();
    }

    /**
     * Log worker shutdown.
     */
    protected function logShutdown(): void
    {
        Log::info('Cleanup worker stopped');
        $this->info('Cleanup worker stopped gracefully.');
    }
}

