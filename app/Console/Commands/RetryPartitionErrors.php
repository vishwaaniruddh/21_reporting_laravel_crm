<?php

namespace App\Console\Commands;

use App\Services\PartitionErrorQueueService;
use App\Services\PartitionFailureAlertService;
use Illuminate\Console\Command;

/**
 * RetryPartitionErrors Command
 * 
 * Retries failed partition sync operations from the error queue.
 * This command processes errors that are ready for retry based on
 * their next_retry_at timestamp and retry count.
 * 
 * Requirements: 8.3
 */
class RetryPartitionErrors extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'partition:retry-errors
                            {--all : Retry all pending errors regardless of retry schedule}
                            {--limit= : Maximum number of errors to retry}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retry failed partition sync operations from the error queue';

    /**
     * Execute the console command.
     */
    public function handle(PartitionErrorQueueService $errorQueueService, PartitionFailureAlertService $alertService)
    {
        $this->info('Starting partition error retry process...');
        
        // Display current statistics
        $stats = $errorQueueService->getStatistics();
        $this->info("Current error queue statistics:");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Errors', $stats['total']],
                ['Pending', $stats['pending']],
                ['Retrying', $stats['retrying']],
                ['Failed (Max Retries)', $stats['failed']],
                ['Resolved', $stats['resolved']],
                ['Ready for Retry', $stats['ready_for_retry']],
            ]
        );
        
        if ($stats['ready_for_retry'] === 0) {
            $this->info('No errors ready for retry at this time.');
            return 0;
        }
        
        // Retry errors
        $this->info('Retrying errors...');
        $retryStats = $errorQueueService->retryReadyErrors();
        
        $this->info("Retry completed:");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Processed', $retryStats['total']],
                ['Successful', $retryStats['success']],
                ['Failed', $retryStats['failed']],
                ['Max Retries Exceeded', $retryStats['max_retries_exceeded']],
            ]
        );
        
        // Check if alert threshold exceeded
        $alertStats = $alertService->getFailureStatistics();
        if ($alertStats['threshold_exceeded']) {
            $this->warn('⚠️  Partition failure threshold exceeded!');
            $this->warn("Recent failures: {$alertStats['recent_failure_count']} (threshold: {$alertStats['threshold']})");
        }
        
        return 0;
    }
}

