<?php

namespace App\Console\Commands;

use App\Services\UpdateLogMonitor;
use App\Services\AlertSyncService;
use App\Services\SyncLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Continuous synchronization worker that monitors MySQL alert_pg_update_log
 * and propagates changes to PostgreSQL alerts table.
 * 
 * This worker runs indefinitely, polling for pending log entries (status=1),
 * fetching alert data from MySQL (read-only), and updating PostgreSQL.
 * 
 * ⚠️ CRITICAL: MySQL alerts table is READ-ONLY - only SELECT operations allowed
 * ⚠️ CRITICAL: No DELETE, TRUNCATE, or UPDATE operations on MySQL alerts table
 * 
 * Requirements: 1.1, 1.4, 1.5, 6.1, 6.5, 7.5
 */
class UpdateSyncWorker extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'sync:update-worker 
                            {--poll-interval=5 : Seconds between polls}
                            {--batch-size=100 : Max entries per batch}
                            {--max-retries=3 : Max retries for failed operations}';

    /**
     * The console command description.
     */
    protected $description = 'Continuously sync MySQL alert updates to PostgreSQL';

    /**
     * Flag to control the worker loop.
     */
    protected bool $shouldContinue = true;

    /**
     * Service dependencies.
     */
    protected UpdateLogMonitor $monitor;
    protected AlertSyncService $syncService;
    protected SyncLogger $logger;

    /**
     * Configuration values.
     */
    protected int $pollInterval;
    protected int $batchSize;
    protected int $maxRetries;

    /**
     * Execute the console command.
     * 
     * Initializes the worker with configuration from command options,
     * registers signal handlers for graceful shutdown, and enters the
     * main processing loop.
     * 
     * The worker runs indefinitely until:
     * - SIGTERM or SIGINT signal is received
     * - A critical error occurs
     * 
     * @param UpdateLogMonitor $monitor Service for fetching pending log entries
     * @param AlertSyncService $syncService Service for syncing individual alerts
     * @param SyncLogger $logger Service for structured logging
     * @return int Command exit code (SUCCESS or FAILURE)
     */
    public function handle(
        UpdateLogMonitor $monitor,
        AlertSyncService $syncService,
        SyncLogger $logger
    ): int {
        // Inject dependencies
        $this->monitor = $monitor;
        $this->syncService = $syncService;
        $this->logger = $logger;

        // Parse configuration from options
        $this->pollInterval = (int) $this->option('poll-interval');
        $this->batchSize = (int) $this->option('batch-size');
        $this->maxRetries = (int) $this->option('max-retries');

        // Configure services with command options
        $this->monitor->setBatchSize($this->batchSize);
        $this->syncService->setMaxRetries($this->maxRetries);

        // Register signal handlers for graceful shutdown
        $this->registerSignalHandlers();

        // Log worker startup
        $this->logStartup();

        // Main processing loop
        $this->info('Update sync worker started. Press Ctrl+C to stop gracefully.');
        $this->info('Syncing updates to date-partitioned PostgreSQL tables (alerts_YYYY_MM_DD).');
        $this->newLine();

        while ($this->shouldContinue()) {
            try {
                $this->processOneCycle();
            } catch (\Exception $e) {
                // Log error but continue processing
                $this->logger->logError('Worker cycle error', $e);
                $this->error("Error in processing cycle: {$e->getMessage()}");
                
                // Sleep before retrying to avoid tight error loops
                sleep($this->pollInterval);
            }
        }

        // Log worker shutdown
        $this->logShutdown();

        return Command::SUCCESS;
    }

    /**
     * Process one complete sync cycle.
     * 
     * A cycle consists of:
     * 1. Fetching pending entries from MySQL alert_pg_update_log
     * 2. Processing each entry (sync alert from MySQL to PostgreSQL)
     * 3. Logging cycle metrics
     * 4. Sleeping for poll interval if no entries found
     * 
     * Errors during processing are logged but do not stop the cycle.
     * 
     * Requirements: 1.1, 1.3, 1.4, 5.2, 5.5, 7.1
     * 
     * @return void
     */
    protected function processOneCycle(): void
    {
        $cycleStartTime = microtime(true);

        // Fetch pending entries from MySQL alert_pg_update_log
        $entries = $this->monitor->fetchPendingEntries();

        if ($entries->isEmpty()) {
            // No entries to process - sleep and continue
            $this->line('No pending entries. Sleeping...');
            sleep($this->pollInterval);
            return;
        }

        // Log cycle start
        $pendingCount = $entries->count();
        $this->logger->logCycleStart($pendingCount);
        $this->info("Processing {$pendingCount} pending entries...");

        // Process each entry
        $this->processEntries($entries);

        // Calculate cycle metrics
        $cycleDuration = microtime(true) - $cycleStartTime;
        
        // Count successes and failures from the entries
        $processed = $entries->count();
        $failed = 0; // We'll track this in processEntries if needed

        // Log cycle completion
        $this->logger->logCycleComplete($processed, $failed, $cycleDuration);
        $this->info(sprintf(
            'Cycle complete: %d processed in %.2f seconds',
            $processed,
            $cycleDuration
        ));
        $this->newLine();

        // Sleep before next cycle
        sleep($this->pollInterval);
    }

    /**
     * Process a collection of log entries.
     * 
     * Handles errors gracefully - if one entry fails, continue with others.
     * This implements error isolation (Requirement 5.2).
     * 
     * For each entry:
     * 1. Calls AlertSyncService to sync the alert
     * 2. Logs the result
     * 3. Displays status to console
     * 
     * Requirements: 5.2
     * 
     * @param Collection<AlertUpdateLog> $entries Log entries to process
     * @return void
     */
    protected function processEntries($entries): void
    {
        foreach ($entries as $entry) {
            try {
                $alertStartTime = microtime(true);

                // Sync the alert (reads from MySQL alerts, updates PostgreSQL alerts)
                // Updates MySQL alert_pg_update_log to mark as processed
                $result = $this->syncService->syncAlert($entry->id, $entry->alert_id);

                $alertDuration = microtime(true) - $alertStartTime;

                // Log the result
                $this->logger->logAlertSync(
                    $entry->alert_id,
                    $result->success,
                    $alertDuration,
                    $result->errorMessage
                );

                if ($result->success) {
                    $this->line("  ✓ Alert {$entry->alert_id} synced successfully");
                } else {
                    $this->warn("  ✗ Alert {$entry->alert_id} failed: {$result->errorMessage}");
                }
            } catch (\Exception $e) {
                // Log error and continue with next entry (error isolation)
                $this->logger->logError(
                    'Alert sync error',
                    $e,
                    ['log_entry_id' => $entry->id, 'alert_id' => $entry->alert_id]
                );
                $this->error("  ✗ Alert {$entry->alert_id} error: {$e->getMessage()}");
                
                // Continue processing other entries
                continue;
            }
        }
    }

    /**
     * Register signal handlers for graceful shutdown.
     * 
     * Registers handlers for SIGTERM and SIGINT signals to allow
     * the worker to complete the current batch before shutting down.
     * 
     * Requires the PCNTL PHP extension. If not available, a warning
     * is displayed but the worker continues to run.
     * 
     * Requirements: 1.5
     * 
     * @return void
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
     * 
     * Returns false when a shutdown signal (SIGTERM/SIGINT) has been received.
     * 
     * @return bool True if worker should continue, false to shutdown
     */
    protected function shouldContinue(): bool
    {
        return $this->shouldContinue;
    }

    /**
     * Log worker startup with configuration.
     * 
     * Logs configuration to Laravel log and displays a formatted
     * table to the console showing all configuration values.
     * 
     * Requirements: 7.5
     * 
     * @return void
     */
    protected function logStartup(): void
    {
        $config = [
            'poll_interval' => $this->pollInterval,
            'batch_size' => $this->batchSize,
            'max_retries' => $this->maxRetries,
        ];

        $this->logger->logInfo('Update sync worker started', $config);

        $this->info('=== Update Sync Worker Configuration ===');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Poll Interval', "{$this->pollInterval} seconds"],
                ['Batch Size', $this->batchSize],
                ['Max Retries', $this->maxRetries],
            ]
        );
        $this->newLine();
    }

    /**
     * Log worker shutdown.
     * 
     * Logs shutdown event to Laravel log and displays a message
     * to the console.
     * 
     * Requirements: 7.5
     * 
     * @return void
     */
    protected function logShutdown(): void
    {
        $this->logger->logInfo('Update sync worker stopped');
        $this->info('Update sync worker stopped gracefully.');
    }
}
