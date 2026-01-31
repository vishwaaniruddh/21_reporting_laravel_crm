<?php

namespace App\Jobs;

use App\Services\SyncService;
use App\Services\BatchResult;
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
 * SyncJob handles the scheduled synchronization of alerts from MySQL to PostgreSQL.
 * 
 * This job fetches unsynced records by ID order, processes them in batches with
 * progress tracking, updates sync markers after successful inserts, and implements
 * checkpoint/resume capability for resilience.
 * 
 * Requirements: 1.1, 1.4, 7.3
 */
class SyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * Cache key for storing checkpoint data
     */
    protected const CHECKPOINT_CACHE_KEY = 'sync_job_checkpoint';

    /**
     * Cache key for storing job status
     */
    protected const STATUS_CACHE_KEY = 'sync_job_status';

    /**
     * Optional starting ID for manual triggering
     */
    protected ?int $startFromId;

    /**
     * Optional batch size override
     */
    protected ?int $batchSize;

    /**
     * Create a new job instance.
     */
    public function __construct(?int $startFromId = null, ?int $batchSize = null)
    {
        $this->startFromId = $startFromId;
        $this->batchSize = $batchSize;
        $this->timeout = (int) config('pipeline.job_timeout', 3600);
    }

    /**
     * Execute the job.
     */
    public function handle(SyncService $syncService): void
    {
        $this->setStatus('running');
        $startTime = microtime(true);
        $totalProcessed = 0;
        $batchesProcessed = 0;
        $lastProcessedId = $this->getStartingId($syncService);

        Log::info('SyncJob started', [
            'starting_id' => $lastProcessedId,
            'batch_size' => $this->getBatchSize($syncService),
        ]);

        try {
            // Configure batch size if provided
            if ($this->batchSize !== null) {
                $syncService->setBatchSize($this->batchSize);
            }

            // Process batches until no more records or timeout approaching
            while ($this->shouldContinueProcessing($startTime)) {
                // Fetch next batch of unsynced records
                $alerts = $syncService->fetchUnsyncedBatch($lastProcessedId);

                if ($alerts->isEmpty()) {
                    Log::info('SyncJob completed - no more records to sync', [
                        'total_processed' => $totalProcessed,
                        'batches_processed' => $batchesProcessed,
                    ]);
                    break;
                }

                // Process the batch
                $result = $syncService->processBatch($alerts);

                if ($result->isSuccess()) {
                    $totalProcessed += $result->recordsProcessed;
                    $batchesProcessed++;
                    $lastProcessedId = $result->lastProcessedId;

                    // Save checkpoint after each successful batch
                    $this->saveCheckpoint($lastProcessedId);

                    Log::info('Batch processed successfully', [
                        'batch_id' => $result->batchId,
                        'records_processed' => $result->recordsProcessed,
                        'last_processed_id' => $lastProcessedId,
                        'total_processed' => $totalProcessed,
                    ]);
                } else {
                    // Log failure but continue with next batch if possible
                    Log::error('Batch processing failed', [
                        'batch_id' => $result->batchId,
                        'error' => $result->errorMessage,
                    ]);

                    // If this is a critical failure, stop processing
                    if ($this->isCriticalFailure($result)) {
                        throw new Exception('Critical sync failure: ' . $result->errorMessage);
                    }

                    // Skip to next batch by moving past the failed range
                    $lastProcessedId = $alerts->last()->id;
                    $this->saveCheckpoint($lastProcessedId);
                }

                // Update progress status
                $this->updateProgress($totalProcessed, $batchesProcessed, $lastProcessedId);
            }

            // Check if we stopped due to timeout
            if (!$this->shouldContinueProcessing($startTime) && $syncService->hasRecordsToSync()) {
                Log::info('SyncJob checkpointed due to timeout', [
                    'checkpoint_id' => $lastProcessedId,
                    'total_processed' => $totalProcessed,
                    'remaining_records' => $syncService->getUnsyncedCount(),
                ]);
            }

            $this->setStatus('completed', [
                'total_processed' => $totalProcessed,
                'batches_processed' => $batchesProcessed,
                'last_processed_id' => $lastProcessedId,
                'duration_seconds' => round(microtime(true) - $startTime, 2),
            ]);

        } catch (Exception $e) {
            Log::error('SyncJob failed', [
                'error' => $e->getMessage(),
                'checkpoint_id' => $lastProcessedId,
                'total_processed' => $totalProcessed,
            ]);

            $this->setStatus('failed', [
                'error' => $e->getMessage(),
                'checkpoint_id' => $lastProcessedId,
            ]);

            throw $e;
        }
    }

    /**
     * Get the starting ID for this sync run
     */
    protected function getStartingId(SyncService $syncService): int
    {
        // Use provided start ID if available
        if ($this->startFromId !== null) {
            return $this->startFromId;
        }

        // Check for saved checkpoint
        $checkpoint = $this->getCheckpoint();
        if ($checkpoint !== null) {
            return $checkpoint;
        }

        // Fall back to last synced ID
        return $syncService->getLastSyncedId();
    }

    /**
     * Get the batch size for this sync run
     */
    protected function getBatchSize(SyncService $syncService): int
    {
        return $this->batchSize ?? $syncService->getBatchSize();
    }

    /**
     * Check if we should continue processing batches
     */
    protected function shouldContinueProcessing(float $startTime): bool
    {
        // Leave 5 minutes buffer before timeout
        $maxRuntime = $this->timeout - 300;
        $elapsed = microtime(true) - $startTime;

        return $elapsed < $maxRuntime;
    }

    /**
     * Determine if a failure is critical and should stop processing
     */
    protected function isCriticalFailure(BatchResult $result): bool
    {
        // Connection failures are critical
        if (str_contains($result->errorMessage ?? '', 'Connection')) {
            return true;
        }

        // Memory issues are critical
        if (str_contains($result->errorMessage ?? '', 'memory')) {
            return true;
        }

        return false;
    }

    /**
     * Save checkpoint to cache
     */
    protected function saveCheckpoint(int $lastProcessedId): void
    {
        Cache::put(self::CHECKPOINT_CACHE_KEY, [
            'last_processed_id' => $lastProcessedId,
            'saved_at' => now()->toIso8601String(),
        ], now()->addDays(7));
    }

    /**
     * Get checkpoint from cache
     */
    protected function getCheckpoint(): ?int
    {
        $checkpoint = Cache::get(self::CHECKPOINT_CACHE_KEY);
        return $checkpoint['last_processed_id'] ?? null;
    }

    /**
     * Clear the checkpoint
     */
    public static function clearCheckpoint(): void
    {
        Cache::forget(self::CHECKPOINT_CACHE_KEY);
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
     * Update progress in cache
     */
    protected function updateProgress(int $totalProcessed, int $batchesProcessed, int $lastProcessedId): void
    {
        Cache::put(self::STATUS_CACHE_KEY, [
            'status' => 'running',
            'total_processed' => $totalProcessed,
            'batches_processed' => $batchesProcessed,
            'last_processed_id' => $lastProcessedId,
            'updated_at' => now()->toIso8601String(),
        ], now()->addHours(24));
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
        Log::error('SyncJob failed permanently', [
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
        return ['sync', 'pipeline', 'alerts'];
    }
}
