<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\PartitionSyncError;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * PartitionErrorQueueService
 * 
 * Manages the error queue for failed partition sync operations.
 * This service provides:
 * - Adding failed alerts to the error queue
 * - Retrying failed operations
 * - Managing error lifecycle (pending -> retrying -> failed/resolved)
 * - Error statistics and reporting
 * 
 * Requirements: 8.3
 */
class PartitionErrorQueueService
{
    /**
     * DateGroupedSyncService for retry operations
     */
    private DateGroupedSyncService $syncService;
    
    /**
     * Create a new PartitionErrorQueueService instance
     * 
     * @param DateGroupedSyncService|null $syncService Optional sync service instance
     */
    public function __construct(?DateGroupedSyncService $syncService = null)
    {
        $this->syncService = $syncService ?? new DateGroupedSyncService();
    }
    
    /**
     * Add a failed alert to the error queue
     * 
     * Creates an error record for an alert that failed to sync to a partition table.
     * Stores the alert data snapshot for retry without MySQL lookup.
     * 
     * Requirements: 8.3
     * 
     * @param Alert $alert The alert that failed to sync
     * @param Carbon $partitionDate The partition date
     * @param string $partitionTable The partition table name
     * @param string $errorType The error type
     * @param string $errorMessage The error message
     * @param int|null $syncBatchId Optional sync batch ID
     * @param Exception|null $exception Optional exception for trace and code
     * @return PartitionSyncError The created error record
     */
    public function addToErrorQueue(
        Alert $alert,
        Carbon $partitionDate,
        string $partitionTable,
        string $errorType,
        string $errorMessage,
        ?int $syncBatchId = null,
        ?Exception $exception = null
    ): PartitionSyncError {
        // Capture alert data snapshot
        $alertData = $alert->toArray();
        
        // Extract error trace and code from exception if provided
        $errorTrace = $exception ? $exception->getTraceAsString() : null;
        $errorCode = $exception ? $exception->getCode() : null;
        
        $error = PartitionSyncError::createError(
            alertId: $alert->id,
            partitionDate: $partitionDate,
            partitionTable: $partitionTable,
            errorType: $errorType,
            errorMessage: $errorMessage,
            alertData: $alertData,
            syncBatchId: $syncBatchId,
            errorTrace: $errorTrace,
            errorCode: $errorCode
        );
        
        Log::warning('Alert added to partition error queue', [
            'alert_id' => $alert->id,
            'partition_date' => $partitionDate->toDateString(),
            'partition_table' => $partitionTable,
            'error_type' => $errorType,
            'error_id' => $error->id
        ]);
        
        return $error;
    }
    
    /**
     * Add multiple failed alerts to the error queue
     * 
     * Bulk operation for adding multiple alerts that failed together.
     * 
     * @param Collection $alerts Collection of Alert models
     * @param Carbon $partitionDate The partition date
     * @param string $partitionTable The partition table name
     * @param string $errorType The error type
     * @param string $errorMessage The error message
     * @param int|null $syncBatchId Optional sync batch ID
     * @param Exception|null $exception Optional exception
     * @return Collection Collection of created PartitionSyncError records
     */
    public function addBatchToErrorQueue(
        Collection $alerts,
        Carbon $partitionDate,
        string $partitionTable,
        string $errorType,
        string $errorMessage,
        ?int $syncBatchId = null,
        ?Exception $exception = null
    ): Collection {
        $errors = collect();
        
        foreach ($alerts as $alert) {
            try {
                $error = $this->addToErrorQueue(
                    $alert,
                    $partitionDate,
                    $partitionTable,
                    $errorType,
                    $errorMessage,
                    $syncBatchId,
                    $exception
                );
                $errors->push($error);
            } catch (Exception $e) {
                Log::error('Failed to add alert to error queue', [
                    'alert_id' => $alert->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        Log::info('Batch added to partition error queue', [
            'alert_count' => $alerts->count(),
            'errors_created' => $errors->count(),
            'partition_date' => $partitionDate->toDateString(),
            'partition_table' => $partitionTable
        ]);
        
        return $errors;
    }
    
    /**
     * Retry all errors that are ready for retry
     * 
     * Processes all errors that have reached their next_retry_at time
     * and haven't exceeded max retries.
     * 
     * Requirements: 8.3
     * 
     * @return array Statistics about the retry operation
     */
    public function retryReadyErrors(): array
    {
        $errors = PartitionSyncError::getReadyForRetry();
        
        if ($errors->isEmpty()) {
            return [
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'max_retries_exceeded' => 0,
            ];
        }
        
        $stats = [
            'total' => $errors->count(),
            'success' => 0,
            'failed' => 0,
            'max_retries_exceeded' => 0,
        ];
        
        foreach ($errors as $error) {
            $result = $this->retryError($error);
            
            if ($result['success']) {
                $stats['success']++;
            } elseif ($result['max_retries_exceeded']) {
                $stats['max_retries_exceeded']++;
            } else {
                $stats['failed']++;
            }
        }
        
        Log::info('Completed retry of ready errors', $stats);
        
        return $stats;
    }
    
    /**
     * Retry a single error
     * 
     * Attempts to sync the alert from the error record.
     * Updates retry count and status based on result.
     * 
     * @param PartitionSyncError $error The error to retry
     * @return array Result of the retry operation
     */
    public function retryError(PartitionSyncError $error): array
    {
        // Check if error can be retried
        if (!$error->canRetry()) {
            Log::warning('Error cannot be retried', [
                'error_id' => $error->id,
                'retry_count' => $error->retry_count,
                'max_retries' => $error->max_retries,
                'status' => $error->status
            ]);
            
            return [
                'success' => false,
                'max_retries_exceeded' => true,
                'error_id' => $error->id,
            ];
        }
        
        // Mark as retrying
        $error->markRetrying();
        
        Log::info('Retrying partition sync error', [
            'error_id' => $error->id,
            'alert_id' => $error->alert_id,
            'partition_table' => $error->partition_table,
            'retry_count' => $error->retry_count,
            'max_retries' => $error->max_retries
        ]);
        
        try {
            // Reconstruct alert from stored data
            $alert = new Alert($error->alert_data);
            $alert->id = $error->alert_id;
            $alert->exists = true;
            
            // Attempt to sync the single alert
            $result = $this->syncService->syncDateGroup(
                $error->partition_date,
                collect([$alert])
            );
            
            if ($result->success) {
                // Success - mark as resolved
                $error->markResolved('Successfully synced on retry attempt ' . $error->retry_count);
                
                Log::info('Error resolved successfully', [
                    'error_id' => $error->id,
                    'alert_id' => $error->alert_id,
                    'retry_count' => $error->retry_count
                ]);
                
                return [
                    'success' => true,
                    'max_retries_exceeded' => false,
                    'error_id' => $error->id,
                ];
            } else {
                // Failed - check if max retries exceeded
                if ($error->retry_count >= $error->max_retries) {
                    $error->markFailed();
                    
                    Log::error('Error failed after max retries', [
                        'error_id' => $error->id,
                        'alert_id' => $error->alert_id,
                        'retry_count' => $error->retry_count,
                        'error_message' => $result->errorMessage
                    ]);
                    
                    return [
                        'success' => false,
                        'max_retries_exceeded' => true,
                        'error_id' => $error->id,
                    ];
                } else {
                    // Will retry again later
                    Log::warning('Retry failed, will try again', [
                        'error_id' => $error->id,
                        'alert_id' => $error->alert_id,
                        'retry_count' => $error->retry_count,
                        'next_retry_at' => $error->next_retry_at,
                        'error_message' => $result->errorMessage
                    ]);
                    
                    return [
                        'success' => false,
                        'max_retries_exceeded' => false,
                        'error_id' => $error->id,
                    ];
                }
            }
            
        } catch (Exception $e) {
            Log::error('Exception during error retry', [
                'error_id' => $error->id,
                'alert_id' => $error->alert_id,
                'exception' => $e->getMessage()
            ]);
            
            // Check if max retries exceeded
            if ($error->retry_count >= $error->max_retries) {
                $error->markFailed();
                
                return [
                    'success' => false,
                    'max_retries_exceeded' => true,
                    'error_id' => $error->id,
                ];
            }
            
            return [
                'success' => false,
                'max_retries_exceeded' => false,
                'error_id' => $error->id,
            ];
        }
    }
    
    /**
     * Get error queue statistics
     * 
     * @return array Statistics about the error queue
     */
    public function getStatistics(): array
    {
        return PartitionSyncError::getStatistics();
    }
    
    /**
     * Get errors grouped by error type
     * 
     * @return Collection
     */
    public function getErrorsByType(): Collection
    {
        return PartitionSyncError::getGroupedByErrorType();
    }
    
    /**
     * Get errors grouped by partition date
     * 
     * @return Collection
     */
    public function getErrorsByPartitionDate(): Collection
    {
        return PartitionSyncError::getGroupedByPartitionDate();
    }
    
    /**
     * Get recent errors
     * 
     * @param int $hours Number of hours to look back
     * @return Collection
     */
    public function getRecentErrors(int $hours = 24): Collection
    {
        return PartitionSyncError::recent($hours)->get();
    }
    
    /**
     * Clean up old resolved errors
     * 
     * Deletes resolved errors older than the specified number of days.
     * 
     * @param int $days Number of days to keep resolved errors
     * @return int Number of records deleted
     */
    public function cleanupResolvedErrors(int $days = 30): int
    {
        $count = PartitionSyncError::where('status', PartitionSyncError::STATUS_RESOLVED)
            ->where('resolved_at', '<', now()->subDays($days))
            ->delete();
        
        Log::info('Cleaned up old resolved errors', [
            'days' => $days,
            'deleted_count' => $count
        ]);
        
        return $count;
    }
}
