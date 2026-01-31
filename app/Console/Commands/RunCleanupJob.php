<?php

namespace App\Console\Commands;

use App\Jobs\CleanupJob;
use App\Services\CleanupService;
use Illuminate\Console\Command;

/**
 * Console command to run the cleanup job.
 * 
 * ⚠️ EXTREME CAUTION: This command DELETES records from MySQL alerts table!
 * 
 * This command requires explicit confirmation before proceeding.
 * It will NOT auto-run without the --confirm flag.
 * 
 * Requirements: 3.5, 4.1, 4.6
 */
class RunCleanupJob extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'pipeline:cleanup 
                            {--confirm : REQUIRED - Explicitly confirm you want to delete records}
                            {--batch= : Specific batch ID to clean up}
                            {--retention= : Override retention period in days}
                            {--sync : Run synchronously instead of queuing}
                            {--preview : Preview what would be cleaned without deleting}
                            {--force : Skip the interactive confirmation prompt}';

    /**
     * The console command description.
     */
    protected $description = '⚠️ CAUTION: Clean up verified synced records from MySQL (DELETES DATA)';

    /**
     * Execute the console command.
     */
    public function handle(CleanupService $cleanupService): int
    {
        // Check if cleanup is enabled
        if (!config('pipeline.cleanup_enabled', false)) {
            $this->error('⚠️ Cleanup is DISABLED in configuration.');
            $this->info('Set PIPELINE_CLEANUP_ENABLED=true in your .env file to enable cleanup.');
            return Command::FAILURE;
        }

        $confirm = $this->option('confirm');
        $preview = $this->option('preview');
        $force = $this->option('force');
        $batchId = $this->option('batch') ? (int) $this->option('batch') : null;
        $retentionDays = $this->option('retention') ? (int) $this->option('retention') : null;
        $sync = $this->option('sync');

        // Preview mode - show what would be cleaned
        if ($preview) {
            return $this->showPreview($cleanupService, $retentionDays);
        }

        // Require --confirm flag
        if (!$confirm) {
            $this->error('⚠️ SAFETY CHECK: The --confirm flag is REQUIRED to run cleanup.');
            $this->newLine();
            $this->warn('This command will DELETE records from the MySQL alerts table.');
            $this->info('Use --preview to see what would be cleaned without deleting.');
            $this->info('Use --confirm to proceed with cleanup.');
            return Command::FAILURE;
        }

        // Show preview first
        $this->showPreview($cleanupService, $retentionDays);

        // Interactive confirmation unless --force is used
        if (!$force) {
            $this->newLine();
            $this->error('⚠️⚠️⚠️ WARNING: THIS WILL PERMANENTLY DELETE DATA FROM MySQL ⚠️⚠️⚠️');
            $this->newLine();
            
            if (!$this->confirm('Are you ABSOLUTELY SURE you want to proceed with cleanup?', false)) {
                $this->info('Cleanup cancelled.');
                return Command::SUCCESS;
            }

            // Double confirmation for safety
            $confirmText = $this->ask('Type "DELETE" to confirm you want to delete records');
            if ($confirmText !== 'DELETE') {
                $this->info('Cleanup cancelled - confirmation text did not match.');
                return Command::SUCCESS;
            }
        }

        // Configure retention if provided
        if ($retentionDays !== null) {
            $cleanupService->setRetentionDays($retentionDays);
        }

        // Set admin confirmation
        $cleanupService->setAdminConfirmation(true);

        $this->newLine();
        $this->info('Starting cleanup...');

        if ($sync) {
            // Run synchronously
            return $this->runSynchronously($cleanupService, $batchId);
        } else {
            // Dispatch to queue
            return $this->dispatchToQueue($batchId, $retentionDays);
        }
    }


    /**
     * Show preview of what would be cleaned
     */
    protected function showPreview(CleanupService $cleanupService, ?int $retentionDays): int
    {
        if ($retentionDays !== null) {
            $cleanupService->setRetentionDays($retentionDays);
        }

        $preview = $cleanupService->previewCleanup();

        $this->newLine();
        $this->info('=== Cleanup Preview ===');
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Retention Period', $preview['retention_days'] . ' days'],
                ['Eligible Batches', $preview['eligible_batches']],
                ['Eligible Records', number_format($preview['eligible_records'])],
            ]
        );

        if (!empty($preview['batches'])) {
            $this->newLine();
            $this->info('=== Eligible Batches ===');
            $this->newLine();

            $this->table(
                ['Batch ID', 'Records', 'Verified At', 'Days Since Verified'],
                array_map(function ($batch) {
                    return [
                        $batch['batch_id'],
                        number_format($batch['record_count']),
                        $batch['verified_at'] ?? 'N/A',
                        $batch['days_since_verified'] ?? 'N/A',
                    ];
                }, array_slice($preview['batches'], 0, 20))
            );

            if (count($preview['batches']) > 20) {
                $this->info('... and ' . (count($preview['batches']) - 20) . ' more batches');
            }
        }

        if ($preview['eligible_batches'] === 0) {
            $this->newLine();
            $this->info('No batches are eligible for cleanup.');
            $this->info('Batches must be verified and older than ' . $preview['retention_days'] . ' days.');
        }

        return Command::SUCCESS;
    }

    /**
     * Run cleanup synchronously
     */
    protected function runSynchronously(CleanupService $cleanupService, ?int $batchId): int
    {
        if ($batchId !== null) {
            $this->info("Cleaning up batch {$batchId}...");
            $result = $cleanupService->cleanupBatch($batchId);
        } else {
            $this->info('Cleaning up all eligible batches...');
            $result = $cleanupService->cleanupAllEligible();
        }

        $this->newLine();

        if ($result->isSuccess()) {
            $this->info('✓ Cleanup completed successfully!');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Records Deleted', number_format($result->recordsDeleted)],
                    ['Records Skipped', number_format($result->recordsSkipped)],
                    ['Batches Processed', $result->batchesProcessed],
                ]
            );
            return Command::SUCCESS;
        } else {
            $this->error('✗ Cleanup completed with errors.');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Records Deleted', number_format($result->recordsDeleted)],
                    ['Records Skipped', number_format($result->recordsSkipped)],
                    ['Batches Processed', $result->batchesProcessed],
                    ['Errors', count($result->errors)],
                ]
            );

            if (!empty($result->errors)) {
                $this->newLine();
                $this->error('Errors:');
                foreach (array_slice($result->errors, 0, 5) as $error) {
                    $this->line("  - {$error}");
                }
            }

            return Command::FAILURE;
        }
    }

    /**
     * Dispatch cleanup to queue
     */
    protected function dispatchToQueue(?int $batchId, ?int $retentionDays): int
    {
        $specificBatchIds = $batchId !== null ? [$batchId] : null;

        CleanupJob::dispatchWithAdminConfirmation(
            retentionDays: $retentionDays,
            specificBatchIds: $specificBatchIds
        );

        $this->info('✓ Cleanup job dispatched to queue.');
        $this->info('Use "php artisan queue:work" to process the job.');

        return Command::SUCCESS;
    }
}
