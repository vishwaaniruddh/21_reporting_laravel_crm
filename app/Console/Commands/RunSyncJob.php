<?php

namespace App\Console\Commands;

use App\Jobs\SyncJob;
use App\Services\SyncService;
use Illuminate\Console\Command;

/**
 * Artisan command to manually trigger the sync job.
 * 
 * This command allows administrators to manually trigger the sync process,
 * optionally specifying a starting ID and batch size.
 */
class RunSyncJob extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'pipeline:sync 
                            {--start-id= : Start syncing from this ID (exclusive)}
                            {--batch-size= : Override the default batch size}
                            {--sync : Run synchronously instead of dispatching to queue}
                            {--status : Show current sync status}
                            {--clear-checkpoint : Clear the saved checkpoint}';

    /**
     * The console command description.
     */
    protected $description = 'Trigger the alerts sync job from MySQL to PostgreSQL';

    /**
     * Execute the console command.
     */
    public function handle(SyncService $syncService): int
    {
        // Handle status check
        if ($this->option('status')) {
            return $this->showStatus($syncService);
        }

        // Handle checkpoint clearing
        if ($this->option('clear-checkpoint')) {
            SyncJob::clearCheckpoint();
            $this->info('Checkpoint cleared successfully.');
            return Command::SUCCESS;
        }

        $startId = $this->option('start-id') ? (int) $this->option('start-id') : null;
        $batchSize = $this->option('batch-size') ? (int) $this->option('batch-size') : null;

        // Show current state
        $unsyncedCount = $syncService->getUnsyncedCount();
        $this->info("Unsynced records: {$unsyncedCount}");

        if ($unsyncedCount === 0) {
            $this->info('No records to sync.');
            return Command::SUCCESS;
        }

        // Create the job
        $job = new SyncJob($startId, $batchSize);

        if ($this->option('sync')) {
            // Run synchronously
            $this->info('Running sync job synchronously...');
            $job->handle($syncService);
            $this->info('Sync job completed.');
        } else {
            // Dispatch to queue
            SyncJob::dispatch($startId, $batchSize);
            $this->info('Sync job dispatched to queue.');
        }

        return Command::SUCCESS;
    }

    /**
     * Show current sync status
     */
    protected function showStatus(SyncService $syncService): int
    {
        $status = SyncJob::getStatus();
        $unsyncedCount = $syncService->getUnsyncedCount();
        $syncedCount = $syncService->getSyncedCount();
        $lastSyncedId = $syncService->getLastSyncedId();

        $this->info('=== Sync Pipeline Status ===');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Job Status', $status['status'] ?? 'idle'],
                ['Last Updated', $status['updated_at'] ?? 'N/A'],
                ['Unsynced Records', number_format($unsyncedCount)],
                ['Synced Records', number_format($syncedCount)],
                ['Last Synced ID', $lastSyncedId],
                ['Total Processed (current run)', $status['total_processed'] ?? 'N/A'],
                ['Batches Processed (current run)', $status['batches_processed'] ?? 'N/A'],
            ]
        );

        if (isset($status['error'])) {
            $this->error('Last Error: ' . $status['error']);
        }

        return Command::SUCCESS;
    }
}
