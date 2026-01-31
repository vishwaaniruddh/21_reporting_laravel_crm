<?php

namespace App\Console\Commands;

use App\Services\DateGroupedSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to manually trigger the date-partitioned sync job.
 * 
 * This command allows administrators to manually trigger the date-partitioned sync process,
 * which syncs alerts from MySQL to date-partitioned PostgreSQL tables.
 * 
 * Requirements: 5.1
 */
class RunPartitionedSyncJob extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'sync:partitioned 
                            {--start-id= : Start syncing from this ID (exclusive)}
                            {--batch-size= : Override the default batch size}
                            {--status : Show current sync status}
                            {--continuous : Run continuously until all records are synced}
                            {--max-batches= : Maximum number of batches to process (default: 1)}';

    /**
     * The console command description.
     */
    protected $description = 'Trigger the date-partitioned alerts sync from MySQL to PostgreSQL partition tables';

    /**
     * Execute the console command.
     */
    public function handle(DateGroupedSyncService $syncService): int
    {
        // Increase memory limit for large sync operations
        ini_set('memory_limit', '512M');
        
        // Handle status check
        if ($this->option('status')) {
            return $this->showStatus($syncService);
        }

        $startId = $this->option('start-id') ? (int) $this->option('start-id') : null;
        $batchSize = $this->option('batch-size') ? (int) $this->option('batch-size') : null;
        $continuous = $this->option('continuous');
        $maxBatches = $this->option('max-batches') ? (int) $this->option('max-batches') : 1;

        // Override batch size if provided
        if ($batchSize !== null) {
            $syncService->setBatchSize($batchSize);
        }

        // Show current state
        $unsyncedCount = $syncService->getUnsyncedCount();
        $this->info("Unsynced records: " . number_format($unsyncedCount));

        if ($unsyncedCount === 0) {
            $this->info('No records to sync.');
            return Command::SUCCESS;
        }

        // Determine how many batches to process
        $batchesToProcess = $continuous ? PHP_INT_MAX : $maxBatches;
        
        $this->info("Starting date-partitioned sync...");
        $this->info("Batch size: " . number_format($syncService->getBatchSize()));
        
        if ($continuous) {
            $this->info("Mode: Continuous (will process all unsynced records)");
        } else {
            $this->info("Mode: Limited (will process {$maxBatches} batch(es))");
        }
        
        $this->newLine();

        // Process batches
        $batchesProcessed = 0;
        $totalRecordsProcessed = 0;
        $totalDateGroups = 0;
        $totalDuration = 0;
        $lastProcessedId = $startId;
        $hasErrors = false;

        $progressBar = $this->output->createProgressBar($continuous ? $unsyncedCount : min($unsyncedCount, $maxBatches * $syncService->getBatchSize()));

        while ($batchesProcessed < $batchesToProcess && $syncService->hasRecordsToSync()) {
            try {
                // Sync one batch
                $result = $syncService->syncBatch(null, $lastProcessedId);
                
                $batchesProcessed++;
                $totalRecordsProcessed += $result->totalRecordsProcessed;
                $totalDateGroups += count($result->dateGroupResults);
                $totalDuration += $result->duration;
                $lastProcessedId = $result->lastProcessedId;

                // Update progress bar
                $progressBar->advance($result->totalRecordsProcessed);

                // Check for errors
                if (!$result->success) {
                    $hasErrors = true;
                }

                // Log batch completion
                Log::info('Partitioned sync batch completed', [
                    'batch_number' => $batchesProcessed,
                    'records_processed' => $result->totalRecordsProcessed,
                    'date_groups' => count($result->dateGroupResults),
                    'duration' => round($result->duration, 2) . 's',
                    'success' => $result->success,
                ]);

            } catch (\Exception $e) {
                $progressBar->finish();
                $this->newLine(2);
                $this->error('Sync batch failed: ' . $e->getMessage());
                Log::error('Partitioned sync batch failed', [
                    'batch_number' => $batchesProcessed + 1,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $hasErrors = true;
                break;
            }

            // Check if we should continue
            if (!$continuous && $batchesProcessed >= $maxBatches) {
                break;
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        // Display summary
        $this->displaySummary(
            $batchesProcessed,
            $totalRecordsProcessed,
            $totalDateGroups,
            $totalDuration,
            $hasErrors
        );

        return $hasErrors ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Show current sync status
     */
    protected function showStatus(DateGroupedSyncService $syncService): int
    {
        $unsyncedCount = $syncService->getUnsyncedCount();
        $batchSize = $syncService->getBatchSize();

        $this->info('=== Date-Partitioned Sync Status ===');
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Unsynced Records', number_format($unsyncedCount)],
                ['Configured Batch Size', number_format($batchSize)],
                ['Estimated Batches', $unsyncedCount > 0 ? ceil($unsyncedCount / $batchSize) : 0],
            ]
        );

        // Get partition statistics
        try {
            $partitions = \App\Models\PartitionRegistry::orderBy('partition_date', 'desc')
                ->limit(10)
                ->get();

            if ($partitions->isNotEmpty()) {
                $this->newLine();
                $this->info('Recent Partitions (last 10):');
                $this->table(
                    ['Table Name', 'Date', 'Record Count', 'Last Synced'],
                    $partitions->map(fn($p) => [
                        $p->table_name,
                        $p->partition_date->format('Y-m-d'),
                        number_format($p->record_count),
                        $p->last_synced_at?->format('Y-m-d H:i:s') ?? 'Never',
                    ])->toArray()
                );
            }
        } catch (\Exception $e) {
            $this->warn('Could not retrieve partition statistics: ' . $e->getMessage());
        }

        return Command::SUCCESS;
    }

    /**
     * Display sync summary
     */
    protected function displaySummary(
        int $batchesProcessed,
        int $totalRecordsProcessed,
        int $totalDateGroups,
        float $totalDuration,
        bool $hasErrors
    ): void {
        $this->info('=== Sync Summary ===');
        
        $this->table(
            ['Metric', 'Value'],
            [
                ['Batches Processed', number_format($batchesProcessed)],
                ['Total Records Synced', number_format($totalRecordsProcessed)],
                ['Date Groups Processed', number_format($totalDateGroups)],
                ['Total Duration', round($totalDuration, 2) . 's'],
                ['Average Records/Second', $totalDuration > 0 ? number_format($totalRecordsProcessed / $totalDuration, 2) : 'N/A'],
                ['Status', $hasErrors ? '⚠️ Completed with errors' : '✓ Success'],
            ]
        );

        if ($hasErrors) {
            $this->newLine();
            $this->warn('Some date groups failed to sync. Check logs for details.');
            $this->info('Failed records have been moved to the error queue for retry.');
        }
    }
}
