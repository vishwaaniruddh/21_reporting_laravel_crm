<?php

namespace App\Console\Commands;

use App\Jobs\VerifyBatchJob;
use App\Services\VerificationService;
use Illuminate\Console\Command;

/**
 * Console command to run the verification job.
 * 
 * This command can verify a specific batch or all completed batches.
 * 
 * Requirements: 3.1, 3.4
 */
class RunVerifyJob extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'pipeline:verify 
                            {--batch= : Specific batch ID to verify}
                            {--sync : Run synchronously instead of queuing}
                            {--report : Generate a verification report for the last 30 days}';

    /**
     * The console command description.
     */
    protected $description = 'Run verification on completed sync batches';

    /**
     * Execute the console command.
     */
    public function handle(VerificationService $verificationService): int
    {
        $batchId = $this->option('batch') ? (int) $this->option('batch') : null;
        $sync = $this->option('sync');
        $report = $this->option('report');

        if ($report) {
            return $this->generateReport($verificationService);
        }

        if ($batchId) {
            $this->info("Verifying batch {$batchId}...");
        } else {
            $this->info('Verifying all completed batches...');
        }

        if ($sync) {
            // Run synchronously
            $job = new VerifyBatchJob($batchId);
            $job->handle($verificationService);
            $this->info('Verification completed.');
        } else {
            // Dispatch to queue
            VerifyBatchJob::dispatch($batchId);
            $this->info('Verification job dispatched to queue.');
        }

        return Command::SUCCESS;
    }

    /**
     * Generate a verification report
     */
    protected function generateReport(VerificationService $verificationService): int
    {
        $this->info('Generating verification report for the last 30 days...');

        $report = $verificationService->generateVerificationReport(
            now()->subDays(30),
            now()
        );

        $summary = $report->getSummary();

        $this->newLine();
        $this->info('=== Verification Report ===');
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Date Range', $summary['date_range']['start'] . ' to ' . $summary['date_range']['end']],
                ['Total Batches', $summary['batches']['total']],
                ['Verified Batches', $summary['batches']['verified']],
                ['Failed Batches', $summary['batches']['failed']],
                ['Pending Batches', $summary['batches']['pending']],
                ['Success Rate', $summary['batches']['success_rate'] . '%'],
                ['Source Records', number_format($summary['records']['source_total'])],
                ['Target Records', number_format($summary['records']['target_total'])],
                ['Missing Records', number_format($summary['records']['missing'])],
                ['Match Percentage', $summary['records']['match_percentage'] . '%'],
                ['Generated At', $summary['generated_at']],
                ['Duration', $summary['duration_ms'] . 'ms'],
            ]
        );

        if (!empty($report->batchDetails)) {
            $this->newLine();
            $this->info('=== Batch Details ===');
            $this->newLine();

            $this->table(
                ['Batch ID', 'Status', 'Source', 'Target', 'Missing', 'Created At'],
                array_map(function ($batch) {
                    return [
                        $batch['batch_id'],
                        $batch['status'],
                        number_format($batch['source_count']),
                        number_format($batch['target_count']),
                        number_format($batch['missing_count']),
                        $batch['created_at'] ?? 'N/A',
                    ];
                }, array_slice($report->batchDetails, 0, 20)) // Show first 20 batches
            );

            if (count($report->batchDetails) > 20) {
                $this->info('... and ' . (count($report->batchDetails) - 20) . ' more batches');
            }
        }

        return Command::SUCCESS;
    }
}
