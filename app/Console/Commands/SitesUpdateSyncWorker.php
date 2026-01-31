<?php

namespace App\Console\Commands;

use App\Services\SitesUpdateSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Continuous synchronization worker for sites/dvrsite/dvronline updates.
 * 
 * Monitors sites_pg_update_log and propagates changes to PostgreSQL.
 * 
 * ⚠️ NO DELETION FROM MYSQL: Only SELECT operations on MySQL source tables
 */
class SitesUpdateSyncWorker extends Command
{
    protected $signature = 'sites:update-worker 
                            {--poll-interval=5 : Seconds between polls}
                            {--batch-size=100 : Max entries per batch}
                            {--max-retries=3 : Max retries for failed operations}';

    protected $description = 'Continuously sync sites/dvrsite/dvronline updates to PostgreSQL';

    protected bool $shouldContinue = true;
    protected SitesUpdateSyncService $syncService;
    protected int $pollInterval;
    protected int $batchSize;
    protected int $maxRetries;

    public function handle(SitesUpdateSyncService $syncService): int
    {
        $this->syncService = $syncService;
        
        // Parse configuration
        $this->pollInterval = (int) $this->option('poll-interval');
        $this->batchSize = (int) $this->option('batch-size');
        $this->maxRetries = (int) $this->option('max-retries');
        
        $this->syncService->setMaxRetries($this->maxRetries);

        // Register signal handlers
        $this->registerSignalHandlers();

        // Log startup
        $this->logStartup();

        $this->info('Sites update sync worker started. Press Ctrl+C to stop gracefully.');
        $this->newLine();

        // Main processing loop
        while ($this->shouldContinue()) {
            try {
                $this->processOneCycle();
            } catch (\Exception $e) {
                $this->error("Error in processing cycle: {$e->getMessage()}");
                Log::error('Sites update worker cycle error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                sleep($this->pollInterval);
            }
        }

        $this->info('Sites update sync worker stopped gracefully.');
        return Command::SUCCESS;
    }

    protected function processOneCycle(): void
    {
        $cycleStartTime = microtime(true);

        // Fetch pending entries
        $entries = $this->syncService->fetchPendingEntries($this->batchSize);

        if ($entries->isEmpty()) {
            $this->line('No pending entries. Sleeping...');
            sleep($this->pollInterval);
            return;
        }

        $pendingCount = $entries->count();
        $this->info("Processing {$pendingCount} pending entries...");

        // Process batch
        $result = $this->syncService->processBatch($entries);

        $cycleDuration = microtime(true) - $cycleStartTime;

        $this->info(sprintf(
            'Cycle complete: %d inserted, %d updated, %d failed in %.2f seconds',
            $result['inserted'],
            $result['updated'],
            $result['failed'],
            $cycleDuration
        ));
        $this->newLine();

        // Sleep before next cycle
        sleep($this->pollInterval);
    }

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

    protected function shouldContinue(): bool
    {
        return $this->shouldContinue;
    }

    protected function logStartup(): void
    {
        $this->info('=== Sites Update Sync Worker Configuration ===');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Poll Interval', "{$this->pollInterval} seconds"],
                ['Batch Size', $this->batchSize],
                ['Max Retries', $this->maxRetries],
            ]
        );
        $this->newLine();

        Log::info('Sites update sync worker started', [
            'poll_interval' => $this->pollInterval,
            'batch_size' => $this->batchSize,
            'max_retries' => $this->maxRetries,
        ]);
    }
}
