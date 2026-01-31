<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\FailedSyncRecord;
use App\Models\SyncedAlert;
use App\Models\SyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Exception;

/**
 * ErrorQueueService manages the error queue for failed sync records.
 * 
 * This service handles adding records to the error queue, retrying failed records,
 * and providing admin interface functionality for review and management.
 * 
 * Requirements: 7.5
 */
class ErrorQueueService
{
    protected SyncService $syncService;

    public function __construct(SyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    /**
     * Add a failed record to the error queue
     * 
     * @param Alert $alert The alert that failed to sync
     * @param int|null $batchId The batch ID (if applicable)
     * @param string $errorMessage The error message
     * @return FailedSyncRecord
     */
    public function addToQueue(Alert $alert, ?int $batchId, string $errorMessage): FailedSyncRecord
    {
        $alertData = $alert->toArray();
        
        // Check if record already exists in queue
        $existing = FailedSyncRecord::where('alert_id', $alert->id)
            ->where('batch_id', $batchId)
            ->first();

        if ($existing) {
            $existing->incrementRetry($errorMessage);
            Log::info('Incremented retry count for failed record', [
                'alert_id' => $alert->id,
                'batch_id' => $batchId,
                'retry_count' => $existing->retry_count,
            ]);
            return $existing;
        }

        $record = FailedSyncRecord::addToQueue(
            $alert->id,
            $batchId,
            $alertData,
            $errorMessage
        );

        Log::info('Added record to error queue', [
            'alert_id' => $alert->id,
            'batch_id' => $batchId,
            'error' => $errorMessage,
        ]);

        return $record;
    }

    /**
     * Add multiple failed records to the error queue
     * 
     * @param Collection $alerts Collection of Alert models
     * @param int|null $batchId The batch ID
     * @param string $errorMessage The error message
     * @return int Number of records added
     */
    public function addBatchToQueue(Collection $alerts, ?int $batchId, string $errorMessage): int
    {
        $count = 0;
        foreach ($alerts as $alert) {
            $this->addToQueue($alert, $batchId, $errorMessage);
            $count++;
        }
        return $count;
    }

    /**
     * Retry a single failed record
     * 
     * @param FailedSyncRecord $record The failed record to retry
     * @return bool True if retry was successful
     */
    public function retryRecord(FailedSyncRecord $record): bool
    {
        $record->markRetrying();

        try {
            // Get the original alert from MySQL
            $alert = Alert::find($record->alert_id);
            
            if (!$alert) {
                $record->markResolved(null, 'Original alert no longer exists in MySQL');
                Log::info('Failed record resolved - original alert not found', [
                    'alert_id' => $record->alert_id,
                ]);
                return true;
            }

            // Check if already synced
            if ($alert->synced_at !== null) {
                $record->markResolved(null, 'Alert was already synced');
                Log::info('Failed record resolved - already synced', [
                    'alert_id' => $record->alert_id,
                ]);
                return true;
            }

            // Try to sync the single record
            $result = $this->syncService->processBatch(collect([$alert]));

            if ($result->isSuccess()) {
                $record->markResolved(null, 'Successfully synced on retry');
                Log::info('Failed record successfully retried', [
                    'alert_id' => $record->alert_id,
                    'batch_id' => $result->batchId,
                ]);
                return true;
            }

            // Retry failed
            $record->incrementRetry($result->errorMessage ?? 'Unknown error');
            Log::warning('Failed record retry unsuccessful', [
                'alert_id' => $record->alert_id,
                'error' => $result->errorMessage,
                'retry_count' => $record->retry_count,
            ]);
            return false;

        } catch (Exception $e) {
            $record->incrementRetry($e->getMessage());
            Log::error('Exception during failed record retry', [
                'alert_id' => $record->alert_id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Retry all eligible records in the error queue
     * 
     * @return array Summary of retry results
     */
    public function retryEligibleRecords(): array
    {
        $records = FailedSyncRecord::eligibleForRetry()->get();
        
        $results = [
            'total' => $records->count(),
            'successful' => 0,
            'failed' => 0,
        ];

        foreach ($records as $record) {
            if ($this->retryRecord($record)) {
                $results['successful']++;
            } else {
                $results['failed']++;
            }
        }

        Log::info('Completed retry of eligible records', $results);

        return $results;
    }

    /**
     * Get records requiring manual review
     * 
     * @return Collection
     */
    public function getRecordsRequiringReview(): Collection
    {
        return FailedSyncRecord::requiresManualReview()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get all pending records
     * 
     * @return Collection
     */
    public function getPendingRecords(): Collection
    {
        return FailedSyncRecord::pending()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get error queue statistics
     * 
     * @return array
     */
    public function getStatistics(): array
    {
        return [
            'total_pending' => FailedSyncRecord::pending()->count(),
            'eligible_for_retry' => FailedSyncRecord::eligibleForRetry()->count(),
            'requires_manual_review' => FailedSyncRecord::requiresManualReview()->count(),
            'resolved' => FailedSyncRecord::where('status', FailedSyncRecord::STATUS_RESOLVED)->count(),
            'ignored' => FailedSyncRecord::where('status', FailedSyncRecord::STATUS_IGNORED)->count(),
        ];
    }

    /**
     * Mark a record as resolved by admin
     * 
     * @param int $recordId The failed record ID
     * @param int|null $userId The admin user ID
     * @param string|null $notes Admin notes
     * @return bool
     */
    public function resolveRecord(int $recordId, ?int $userId = null, ?string $notes = null): bool
    {
        $record = FailedSyncRecord::find($recordId);
        
        if (!$record) {
            return false;
        }

        $record->markResolved($userId, $notes);
        
        Log::info('Failed record marked as resolved by admin', [
            'record_id' => $recordId,
            'alert_id' => $record->alert_id,
            'user_id' => $userId,
        ]);

        return true;
    }

    /**
     * Mark a record as ignored by admin
     * 
     * @param int $recordId The failed record ID
     * @param int|null $userId The admin user ID
     * @param string|null $notes Admin notes
     * @return bool
     */
    public function ignoreRecord(int $recordId, ?int $userId = null, ?string $notes = null): bool
    {
        $record = FailedSyncRecord::find($recordId);
        
        if (!$record) {
            return false;
        }

        $record->markIgnored($userId, $notes);
        
        Log::info('Failed record marked as ignored by admin', [
            'record_id' => $recordId,
            'alert_id' => $record->alert_id,
            'user_id' => $userId,
        ]);

        return true;
    }

    /**
     * Force retry a record (even if max retries exceeded)
     * 
     * @param int $recordId The failed record ID
     * @return bool
     */
    public function forceRetry(int $recordId): bool
    {
        $record = FailedSyncRecord::find($recordId);
        
        if (!$record) {
            return false;
        }

        // Reset status to allow retry
        $record->resetForRetry();
        
        return $this->retryRecord($record);
    }

    /**
     * Clean up old resolved/ignored records
     * 
     * @param int $daysOld Records older than this will be deleted
     * @return int Number of records deleted
     */
    public function cleanupOldRecords(int $daysOld = 30): int
    {
        $deleted = FailedSyncRecord::whereIn('status', [
                FailedSyncRecord::STATUS_RESOLVED,
                FailedSyncRecord::STATUS_IGNORED,
            ])
            ->where('resolved_at', '<', now()->subDays($daysOld))
            ->delete();

        Log::info('Cleaned up old error queue records', [
            'deleted' => $deleted,
            'days_old' => $daysOld,
        ]);

        return $deleted;
    }
}
