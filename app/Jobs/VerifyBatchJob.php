<?php

namespace App\Jobs;

use App\Services\VerificationService;
use App\Models\SyncBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * VerifyBatchJob handles verification of synced batches.
 * 
 * This job runs verification on completed batches, updates batch status to
 * 'verified' or 'failed', and logs verification results.
 * 
 * IMPORTANT: This job only updates sync_batches status - NEVER touches MySQL alerts.
 * 
 * Requirements: 3.1, 3.4
 */
class VerifyBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 1800; // 30 minutes

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * Cache key for storing job status
     */
    protected const STATUS_CACHE_KEY = 'verify_batch_job_status';

    /**
     * Optional specific batch ID to verify
     */
    protected ?int $batchId;

    /**
     * Create a new job instance.
     * 
     * @param int|null $batchId Optional specific batch ID to verify. If null, verifies all completed batches.
     */
    public function __construct(?int $batchId = null)
    {
        $this->batchId = $batchId;
    }

    /**
     * Execute the job.
     */
    public function handle(VerificationService $verificationService): void
    {
        $this->setStatus('running');
        $startTime = microtime(true);

        Log::info('VerifyBatchJob started', [
            'batch_id' => $this->batchId ?? 'all completed',
        ]);

        try {
            if ($this->batchId !== null) {
                // Verify specific batch
                $result = $this->verifySingleBatch($verificationService, $this->batchId);
            } else {
                // Verify all completed batches
                $result = $this->verifyAllCompletedBatches($verificationService);
            }

            $duration = round(microtime(true) - $startTime, 2);

            Log::info('VerifyBatchJob completed', array_merge($result, [
                'duration_seconds' => $duration,
            ]));

            $this->setStatus('completed', array_merge($result, [
                'duration_seconds' => $duration,
            ]));

        } catch (Exception $e) {
            Log::error('VerifyBatchJob failed', [
                'batch_id' => $this->batchId,
                'error' => $e->getMessage(),
            ]);

            $this->setStatus('failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Verify a single batch
     */
    protected function verifySingleBatch(VerificationService $verificationService, int $batchId): array
    {
        $batch = SyncBatch::find($batchId);

        if (!$batch) {
            return [
                'status' => 'error',
                'message' => "Batch {$batchId} not found",
                'verified' => 0,
                'failed' => 0,
            ];
        }

        // Only verify completed batches
        if ($batch->status !== SyncBatch::STATUS_COMPLETED) {
            return [
                'status' => 'skipped',
                'message' => "Batch {$batchId} is not in completed status (current: {$batch->status})",
                'verified' => 0,
                'failed' => 0,
            ];
        }

        $result = $verificationService->verifyBatch($batchId);

        if ($result->isVerified()) {
            // Update batch status to verified - only updates sync_batches, not alerts
            $batch->markVerified();

            Log::info('Batch verified successfully', [
                'batch_id' => $batchId,
                'source_count' => $result->sourceCount,
                'target_count' => $result->targetCount,
            ]);

            return [
                'status' => 'success',
                'batch_id' => $batchId,
                'verified' => 1,
                'failed' => 0,
                'source_count' => $result->sourceCount,
                'target_count' => $result->targetCount,
            ];
        } else {
            // Update batch status to failed
            $batch->markFailed($result->errorMessage ?? 'Verification failed');

            Log::warning('Batch verification failed', [
                'batch_id' => $batchId,
                'source_count' => $result->sourceCount,
                'target_count' => $result->targetCount,
                'missing_count' => $result->getMissingCount(),
                'error' => $result->errorMessage,
            ]);

            return [
                'status' => 'failed',
                'batch_id' => $batchId,
                'verified' => 0,
                'failed' => 1,
                'source_count' => $result->sourceCount,
                'target_count' => $result->targetCount,
                'missing_count' => $result->getMissingCount(),
                'error' => $result->errorMessage,
            ];
        }
    }

    /**
     * Verify all completed batches
     */
    protected function verifyAllCompletedBatches(VerificationService $verificationService): array
    {
        $batches = $verificationService->getCompletedBatchesForVerification();

        if ($batches->isEmpty()) {
            return [
                'status' => 'success',
                'message' => 'No completed batches to verify',
                'total' => 0,
                'verified' => 0,
                'failed' => 0,
            ];
        }

        $verified = 0;
        $failed = 0;
        $errors = [];

        foreach ($batches as $batch) {
            $result = $verificationService->verifyBatch($batch->id);

            if ($result->isVerified()) {
                // Update batch status to verified - only updates sync_batches, not alerts
                $batch->markVerified();
                $verified++;

                Log::info('Batch verified', [
                    'batch_id' => $batch->id,
                    'source_count' => $result->sourceCount,
                    'target_count' => $result->targetCount,
                ]);
            } else {
                // Update batch status to failed
                $batch->markFailed($result->errorMessage ?? 'Verification failed');
                $failed++;

                $errors[] = [
                    'batch_id' => $batch->id,
                    'error' => $result->errorMessage,
                    'missing_count' => $result->getMissingCount(),
                ];

                Log::warning('Batch verification failed', [
                    'batch_id' => $batch->id,
                    'error' => $result->errorMessage,
                ]);
            }
        }

        return [
            'status' => $failed === 0 ? 'success' : 'partial',
            'total' => $batches->count(),
            'verified' => $verified,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Set job status in cache
     */
    protected function setStatus(string $status, array $data = []): void
    {
        Cache::put(self::STATUS_CACHE_KEY, array_merge([
            'status' => $status,
            'updated_at' => now()->toIso8601String(),
        ], $data), now()->addHours(24));
    }

    /**
     * Get current job status
     */
    public static function getStatus(): array
    {
        return Cache::get(self::STATUS_CACHE_KEY, [
            'status' => 'idle',
            'updated_at' => null,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Exception $exception): void
    {
        Log::error('VerifyBatchJob failed permanently', [
            'batch_id' => $this->batchId,
            'error' => $exception?->getMessage(),
        ]);

        $this->setStatus('failed', [
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        $tags = ['verify', 'pipeline', 'alerts'];
        
        if ($this->batchId !== null) {
            $tags[] = "batch:{$this->batchId}";
        }
        
        return $tags;
    }
}
