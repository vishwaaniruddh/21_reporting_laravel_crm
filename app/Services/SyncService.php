<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\SyncBatch;
use App\Models\SyncedAlert;
use App\Models\SyncLog;
use App\Models\FailedSyncRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Exception;
use PDOException;
use Illuminate\Database\QueryException;

/**
 * SyncService handles the synchronization of alerts from MySQL to PostgreSQL.
 * 
 * This service implements batch fetching from MySQL, bulk inserts to PostgreSQL,
 * and handles transaction boundaries per batch to ensure data integrity.
 * 
 * Requirements: 1.1, 1.2, 1.4, 1.5, 7.1, 7.2
 */
class SyncService
{
    /**
     * Default batch size for sync operations
     */
    protected int $batchSize;

    /**
     * Last checkpoint ID for resume capability
     */
    protected ?int $lastCheckpointId = null;

    /**
     * Retry configuration
     */
    protected int $maxRetries;
    protected int $initialDelaySeconds;
    protected int $maxDelaySeconds;

    public function __construct()
    {
        $this->batchSize = (int) config('pipeline.batch_size', 10000);
        $this->maxRetries = (int) config('pipeline.retry.max_attempts', 5);
        $this->initialDelaySeconds = (int) config('pipeline.retry.initial_delay_seconds', 1);
        $this->maxDelaySeconds = (int) config('pipeline.retry.max_delay_seconds', 30);
    }

    /**
     * Get the configured batch size
     */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * Set the batch size for sync operations
     */
    public function setBatchSize(int $size): self
    {
        $this->batchSize = max(1, min($size, 50000)); // Clamp between 1 and 50000
        return $this;
    }

    /**
     * Get the last synced ID from MySQL
     */
    public function getLastSyncedId(): int
    {
        $lastSyncedAlert = Alert::whereNotNull('synced_at')
            ->orderBy('id', 'desc')
            ->first();

        return $lastSyncedAlert ? $lastSyncedAlert->id : 0;
    }

    /**
     * Get the last checkpoint ID for resume capability
     */
    public function getLastCheckpointId(): ?int
    {
        return $this->lastCheckpointId;
    }

    /**
     * Set checkpoint for resume capability
     */
    public function checkpoint(int $lastProcessedId): void
    {
        $this->lastCheckpointId = $lastProcessedId;
    }

    /**
     * Fetch a batch of unsynced records from MySQL
     * 
     * @param int|null $startId Start from this ID (exclusive), null to start from beginning
     * @param int|null $batchSize Override default batch size
     * @return Collection
     */
    public function fetchUnsyncedBatch(?int $startId = null, ?int $batchSize = null): Collection
    {
        $size = $batchSize ?? $this->batchSize;
        
        $query = Alert::unsynced()
            ->orderBy('id', 'asc');

        if ($startId !== null && $startId > 0) {
            $query->where('id', '>', $startId);
        }

        return $query->limit($size)->get();
    }

    /**
     * Process a batch of alerts: create batch record, sync to PostgreSQL, update markers
     * 
     * This method wraps PostgreSQL inserts in transactions and ensures proper rollback
     * on any failure. No partial batches are allowed - either all records sync or none.
     * 
     * Requirements: 1.4, 7.2
     * 
     * @param Collection $alerts Collection of Alert models to sync
     * @return BatchResult
     */
    public function processBatch(Collection $alerts): BatchResult
    {
        if ($alerts->isEmpty()) {
            return new BatchResult(
                recordsProcessed: 0,
                lastProcessedId: $this->getLastSyncedId(),
                success: true,
                batchId: null,
                errorMessage: null
            );
        }

        $startTime = microtime(true);
        $startId = $alerts->first()->id;
        $endId = $alerts->last()->id;
        $recordsCount = $alerts->count();

        // Create sync batch record in MySQL
        $syncBatch = SyncBatch::create([
            'start_id' => $startId,
            'end_id' => $endId,
            'records_count' => $recordsCount,
            'status' => SyncBatch::STATUS_PENDING,
        ]);

        $syncBatch->markProcessing();

        try {
            // Perform bulk insert to PostgreSQL within a transaction with retry logic
            $this->bulkInsertToPostgresWithRetry($alerts, $syncBatch->id);

            // Update sync markers in MySQL (only after successful PostgreSQL insert)
            $this->updateSyncMarkers($alerts, $syncBatch->id);

            // Mark batch as completed
            $syncBatch->markCompleted();

            // Calculate duration
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            // Log successful sync
            SyncLog::logSync(
                $syncBatch->id,
                $recordsCount,
                SyncLog::STATUS_SUCCESS,
                $durationMs
            );

            // Update checkpoint
            $this->checkpoint($endId);

            return new BatchResult(
                recordsProcessed: $recordsCount,
                lastProcessedId: $endId,
                success: true,
                batchId: $syncBatch->id,
                errorMessage: null
            );

        } catch (Exception $e) {
            // Calculate duration even for failures
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            // Ensure complete rollback - remove any partial data from PostgreSQL
            $this->ensureCleanRollback($syncBatch->id);

            // Mark batch as failed with detailed error message
            $errorMessage = $this->formatErrorMessage($e);
            $syncBatch->markFailed($errorMessage);

            // Log failed sync
            SyncLog::logSync(
                $syncBatch->id,
                0,
                SyncLog::STATUS_FAILED,
                $durationMs,
                $errorMessage
            );

            Log::error('Batch sync failed', [
                'batch_id' => $syncBatch->id,
                'start_id' => $startId,
                'end_id' => $endId,
                'error' => $errorMessage,
            ]);

            return new BatchResult(
                recordsProcessed: 0,
                lastProcessedId: $startId - 1,
                success: false,
                batchId: $syncBatch->id,
                errorMessage: $errorMessage
            );
        }
    }

    /**
     * Bulk insert alerts to PostgreSQL with retry logic for connection failures
     * 
     * Implements exponential backoff: 1s, 2s, 4s, 8s, max 30s
     * Max 5 retries before marking batch failed
     * 
     * Requirements: 7.1
     * 
     * @param Collection $alerts Collection of Alert models
     * @param int $batchId The sync batch ID
     * @throws Exception If insert fails after all retries
     */
    protected function bulkInsertToPostgresWithRetry(Collection $alerts, int $batchId): void
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                $this->bulkInsertToPostgres($alerts, $batchId);
                return; // Success - exit retry loop
            } catch (PDOException | QueryException $e) {
                $lastException = $e;
                $attempt++;

                // Check if this is a connection-related error that's worth retrying
                if (!$this->isRetryableError($e)) {
                    Log::warning('Non-retryable error encountered', [
                        'batch_id' => $batchId,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }

                if ($attempt < $this->maxRetries) {
                    $delay = $this->calculateBackoffDelay($attempt);
                    Log::warning('PostgreSQL insert failed, retrying', [
                        'batch_id' => $batchId,
                        'attempt' => $attempt,
                        'max_retries' => $this->maxRetries,
                        'delay_seconds' => $delay,
                        'error' => $e->getMessage(),
                    ]);
                    sleep($delay);
                }
            } catch (Exception $e) {
                // Non-connection errors should not be retried
                throw $e;
            }
        }

        // All retries exhausted
        Log::error('PostgreSQL insert failed after all retries', [
            'batch_id' => $batchId,
            'attempts' => $attempt,
            'error' => $lastException?->getMessage(),
        ]);

        throw new Exception(
            "PostgreSQL insert failed after {$attempt} attempts: " . ($lastException?->getMessage() ?? 'Unknown error')
        );
    }

    /**
     * Calculate exponential backoff delay
     * 
     * @param int $attempt Current attempt number (1-based)
     * @return int Delay in seconds
     */
    protected function calculateBackoffDelay(int $attempt): int
    {
        // Exponential backoff: 1s, 2s, 4s, 8s, 16s, etc.
        $delay = $this->initialDelaySeconds * pow(2, $attempt - 1);
        return min($delay, $this->maxDelaySeconds);
    }

    /**
     * Check if an exception is a retryable connection error
     * 
     * @param Exception $e The exception to check
     * @return bool True if the error is retryable
     */
    protected function isRetryableError(Exception $e): bool
    {
        $message = strtolower($e->getMessage());
        
        $retryablePatterns = [
            'connection',
            'timeout',
            'gone away',
            'lost connection',
            'server has gone away',
            'connection refused',
            'connection reset',
            'broken pipe',
            'network',
            'socket',
            'deadlock',
            'lock wait timeout',
        ];

        foreach ($retryablePatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bulk insert alerts to PostgreSQL within a transaction
     * 
     * @param Collection $alerts Collection of Alert models
     * @param int $batchId The sync batch ID
     * @throws Exception If insert fails
     */
    protected function bulkInsertToPostgres(Collection $alerts, int $batchId): void
    {
        DB::connection('pgsql')->transaction(function () use ($alerts, $batchId) {
            $now = now();
            
            // Prepare data for bulk insert - mirror MySQL alerts structure
            $insertData = $alerts->map(function ($alert) use ($batchId, $now) {
                return [
                    'id' => $alert->id,
                    'panelid' => $alert->panelid,
                    'seqno' => $alert->seqno,
                    'zone' => $alert->zone,
                    'alarm' => $alert->alarm,
                    'createtime' => $alert->createtime,
                    'receivedtime' => $alert->receivedtime,
                    'comment' => $alert->comment,
                    'status' => $alert->status,
                    'sendtoclient' => $alert->sendtoclient,
                    'closedBy' => $alert->closedBy,
                    'closedtime' => $alert->closedtime,
                    'sendip' => $alert->sendip,
                    'alerttype' => $alert->alerttype,
                    'location' => $alert->location,
                    'priority' => $alert->priority,
                    'AlertUserStatus' => $alert->AlertUserStatus,
                    'level' => $alert->level,
                    'sip2' => $alert->sip2,
                    'c_status' => $alert->c_status,
                    'auto_alert' => $alert->auto_alert,
                    'critical_alerts' => $alert->critical_alerts,
                    'Readstatus' => $alert->Readstatus,
                    'synced_at' => $now,
                    'sync_batch_id' => $batchId,
                ];
            })->toArray();

            // Use chunked insert for very large batches to prevent memory issues
            $chunks = array_chunk($insertData, 1000);
            foreach ($chunks as $chunk) {
                SyncedAlert::insert($chunk);
            }
        });
    }

    /**
     * Ensure complete rollback - remove any partial data from PostgreSQL
     * 
     * This method is called on failure to ensure no partial batches exist.
     * It only affects PostgreSQL - MySQL alerts remain unchanged.
     * 
     * Requirements: 1.4, 7.2
     * 
     * @param int $batchId The batch ID to clean up
     */
    protected function ensureCleanRollback(int $batchId): void
    {
        try {
            DB::connection('pgsql')->transaction(function () use ($batchId) {
                $deletedCount = SyncedAlert::where('sync_batch_id', $batchId)->delete();
                if ($deletedCount > 0) {
                    Log::info('Rolled back partial batch from PostgreSQL', [
                        'batch_id' => $batchId,
                        'records_deleted' => $deletedCount,
                    ]);
                }
            });
        } catch (Exception $e) {
            // Log but don't throw - we're already in error handling
            Log::error('Failed to rollback partial batch from PostgreSQL', [
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Format error message for logging and storage
     * 
     * @param Exception $e The exception
     * @return string Formatted error message
     */
    protected function formatErrorMessage(Exception $e): string
    {
        $message = $e->getMessage();
        
        // Truncate very long messages
        if (strlen($message) > 1000) {
            $message = substr($message, 0, 1000) . '... [truncated]';
        }

        return $message;
    }

    /**
     * Update sync markers in MySQL for successfully synced alerts
     * 
     * @param Collection $alerts Collection of Alert models
     * @param int $batchId The sync batch ID
     */
    protected function updateSyncMarkers(Collection $alerts, int $batchId): void
    {
        $now = now();
        $alertIds = $alerts->pluck('id')->toArray();

        // Update in chunks to prevent long-running queries
        $chunks = array_chunk($alertIds, 1000);
        foreach ($chunks as $chunk) {
            Alert::whereIn('id', $chunk)->update([
                'synced_at' => $now,
                'sync_batch_id' => $batchId,
            ]);
        }
    }

    /**
     * Rollback a failed batch - remove any partial data from PostgreSQL
     * 
     * This method ensures complete rollback with no partial batches.
     * Only affects PostgreSQL - MySQL alerts remain unchanged (except sync markers).
     * 
     * Requirements: 1.4, 7.2
     * 
     * @param int $batchId The batch ID to rollback
     */
    public function rollbackBatch(int $batchId): void
    {
        $startTime = microtime(true);
        
        try {
            // Remove records from PostgreSQL within a transaction
            $deletedCount = 0;
            DB::connection('pgsql')->transaction(function () use ($batchId, &$deletedCount) {
                $deletedCount = SyncedAlert::where('sync_batch_id', $batchId)->delete();
            });

            // Clear sync markers in MySQL (only synced_at and sync_batch_id columns)
            Alert::where('sync_batch_id', $batchId)->update([
                'synced_at' => null,
                'sync_batch_id' => null,
            ]);

            // Update batch status
            $batch = SyncBatch::find($batchId);
            if ($batch) {
                $batch->markFailed('Batch rolled back manually');
            }

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            Log::info('Batch rolled back successfully', [
                'batch_id' => $batchId,
                'records_deleted_from_postgres' => $deletedCount,
                'duration_ms' => $durationMs,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to rollback batch', [
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Retry a failed batch
     * 
     * @param int $batchId The batch ID to retry
     * @return BatchResult
     */
    public function retryFailedBatch(int $batchId): BatchResult
    {
        $batch = SyncBatch::find($batchId);
        
        if (!$batch || $batch->status !== SyncBatch::STATUS_FAILED) {
            return new BatchResult(
                recordsProcessed: 0,
                lastProcessedId: 0,
                success: false,
                batchId: $batchId,
                errorMessage: 'Batch not found or not in failed status'
            );
        }

        // First rollback any partial data
        $this->rollbackBatch($batchId);

        // Fetch the alerts for this batch range
        $alerts = Alert::whereBetween('id', [$batch->start_id, $batch->end_id])
            ->whereNull('synced_at')
            ->orderBy('id', 'asc')
            ->get();

        if ($alerts->isEmpty()) {
            return new BatchResult(
                recordsProcessed: 0,
                lastProcessedId: $batch->end_id,
                success: true,
                batchId: $batchId,
                errorMessage: 'No unsynced records found in batch range'
            );
        }

        // Process the batch again
        return $this->processBatch($alerts);
    }

    /**
     * Get count of unsynced records
     */
    public function getUnsyncedCount(): int
    {
        return Alert::unsynced()->count();
    }

    /**
     * Get count of synced records
     */
    public function getSyncedCount(): int
    {
        return Alert::whereNotNull('synced_at')->count();
    }

    /**
     * Check if there are records to sync
     */
    public function hasRecordsToSync(): bool
    {
        return Alert::unsynced()->exists();
    }

    /**
     * Add failed records to the error queue
     * 
     * This method is called when records repeatedly fail to sync.
     * It stores the alert data in PostgreSQL for admin review.
     * 
     * Requirements: 7.5
     * 
     * @param Collection $alerts Collection of Alert models that failed
     * @param int|null $batchId The batch ID (if applicable)
     * @param string $errorMessage The error message
     * @return int Number of records added to queue
     */
    public function addToErrorQueue(Collection $alerts, ?int $batchId, string $errorMessage): int
    {
        $count = 0;
        
        foreach ($alerts as $alert) {
            try {
                FailedSyncRecord::addToQueue(
                    $alert->id,
                    $batchId,
                    $alert->toArray(),
                    $errorMessage
                );
                $count++;
            } catch (Exception $e) {
                Log::error('Failed to add record to error queue', [
                    'alert_id' => $alert->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($count > 0) {
            Log::info('Added records to error queue', [
                'count' => $count,
                'batch_id' => $batchId,
            ]);
        }

        return $count;
    }

    /**
     * Get retry configuration
     * 
     * @return array
     */
    public function getRetryConfig(): array
    {
        return [
            'max_retries' => $this->maxRetries,
            'initial_delay_seconds' => $this->initialDelaySeconds,
            'max_delay_seconds' => $this->maxDelaySeconds,
        ];
    }
}
