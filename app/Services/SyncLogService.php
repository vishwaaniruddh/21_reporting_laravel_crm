<?php

namespace App\Services;

use App\Models\SyncLog;
use App\Models\SyncBatch;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;

/**
 * SyncLogService handles logging and querying of sync operations.
 * 
 * All logs are stored in PostgreSQL sync_logs table.
 * This service provides methods to log operations with timestamps,
 * track records processed, duration, status, and store error details.
 * 
 * Requirements: 2.1, 2.4
 */
class SyncLogService
{
    /**
     * Log a sync operation
     * 
     * @param int $batchId The batch ID
     * @param int $recordsAffected Number of records processed
     * @param string $status Operation status (success, failed, partial)
     * @param int|null $durationMs Duration in milliseconds
     * @param string|null $errorMessage Error message if failed
     * @return SyncLog
     */
    public function logSync(
        int $batchId,
        int $recordsAffected,
        string $status,
        ?int $durationMs = null,
        ?string $errorMessage = null
    ): SyncLog {
        return SyncLog::create([
            'batch_id' => $batchId,
            'operation' => SyncLog::OPERATION_SYNC,
            'records_affected' => $recordsAffected,
            'status' => $status,
            'duration_ms' => $durationMs,
            'error_message' => $this->truncateErrorMessage($errorMessage),
        ]);
    }

    /**
     * Log a verify operation
     * 
     * @param int $batchId The batch ID
     * @param int $recordsAffected Number of records verified
     * @param string $status Operation status
     * @param int|null $durationMs Duration in milliseconds
     * @param string|null $errorMessage Error message if failed
     * @return SyncLog
     */
    public function logVerify(
        int $batchId,
        int $recordsAffected,
        string $status,
        ?int $durationMs = null,
        ?string $errorMessage = null
    ): SyncLog {
        return SyncLog::create([
            'batch_id' => $batchId,
            'operation' => SyncLog::OPERATION_VERIFY,
            'records_affected' => $recordsAffected,
            'status' => $status,
            'duration_ms' => $durationMs,
            'error_message' => $this->truncateErrorMessage($errorMessage),
        ]);
    }

    /**
     * Log a cleanup operation
     * 
     * @param int $batchId The batch ID
     * @param int $recordsAffected Number of records cleaned up
     * @param string $status Operation status
     * @param int|null $durationMs Duration in milliseconds
     * @param string|null $errorMessage Error message if failed
     * @return SyncLog
     */
    public function logCleanup(
        int $batchId,
        int $recordsAffected,
        string $status,
        ?int $durationMs = null,
        ?string $errorMessage = null
    ): SyncLog {
        return SyncLog::create([
            'batch_id' => $batchId,
            'operation' => SyncLog::OPERATION_CLEANUP,
            'records_affected' => $recordsAffected,
            'status' => $status,
            'duration_ms' => $durationMs,
            'error_message' => $this->truncateErrorMessage($errorMessage),
        ]);
    }

    /**
     * Log an operation with timing
     * 
     * @param string $operation Operation type (sync, verify, cleanup)
     * @param int $batchId The batch ID
     * @param callable $callback The operation to execute
     * @return array ['result' => mixed, 'log' => SyncLog]
     */
    public function logWithTiming(string $operation, int $batchId, callable $callback): array
    {
        $startTime = microtime(true);
        $recordsAffected = 0;
        $status = SyncLog::STATUS_SUCCESS;
        $errorMessage = null;
        $result = null;

        try {
            $result = $callback();
            
            // Extract records affected from result if available
            if (is_array($result) && isset($result['records_affected'])) {
                $recordsAffected = $result['records_affected'];
            } elseif (is_int($result)) {
                $recordsAffected = $result;
            }
        } catch (\Exception $e) {
            $status = SyncLog::STATUS_FAILED;
            $errorMessage = $e->getMessage();
            throw $e;
        } finally {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            
            $log = SyncLog::create([
                'batch_id' => $batchId,
                'operation' => $operation,
                'records_affected' => $recordsAffected,
                'status' => $status,
                'duration_ms' => $durationMs,
                'error_message' => $this->truncateErrorMessage($errorMessage),
            ]);
        }

        return ['result' => $result, 'log' => $log];
    }

    /**
     * Get sync logs with pagination
     * 
     * @param int $perPage Number of records per page
     * @param array $filters Optional filters (operation, status, date_from, date_to)
     * @return LengthAwarePaginator
     */
    public function getLogs(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = SyncLog::query()->orderBy('created_at', 'desc');

        if (!empty($filters['operation'])) {
            $query->where('operation', $filters['operation']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        if (!empty($filters['batch_id'])) {
            $query->where('batch_id', $filters['batch_id']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Get recent logs (last N days)
     * 
     * @param int $days Number of days to look back
     * @param string|null $operation Filter by operation type
     * @return Collection
     */
    public function getRecentLogs(int $days = 30, ?string $operation = null): Collection
    {
        $query = SyncLog::recent($days)->orderBy('created_at', 'desc');

        if ($operation) {
            $query->ofOperation($operation);
        }

        return $query->get();
    }

    /**
     * Get logs for a specific batch
     * 
     * @param int $batchId The batch ID
     * @return Collection
     */
    public function getLogsForBatch(int $batchId): Collection
    {
        return SyncLog::where('batch_id', $batchId)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Get failed operations
     * 
     * @param int $days Number of days to look back
     * @return Collection
     */
    public function getFailedOperations(int $days = 7): Collection
    {
        return SyncLog::where('status', SyncLog::STATUS_FAILED)
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get sync statistics for a date range
     * 
     * @param Carbon|null $from Start date
     * @param Carbon|null $to End date
     * @return array
     */
    public function getStatistics(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? now()->subDays(30);
        $to = $to ?? now();

        $query = SyncLog::whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()]);

        $totalOperations = (clone $query)->count();
        $successfulOperations = (clone $query)->where('status', SyncLog::STATUS_SUCCESS)->count();
        $failedOperations = (clone $query)->where('status', SyncLog::STATUS_FAILED)->count();
        $totalRecordsProcessed = (clone $query)->where('status', SyncLog::STATUS_SUCCESS)->sum('records_affected');
        $avgDuration = (clone $query)->whereNotNull('duration_ms')->avg('duration_ms');

        // Get counts by operation type
        $byOperation = (clone $query)
            ->selectRaw('operation, COUNT(*) as count, SUM(records_affected) as total_records')
            ->groupBy('operation')
            ->get()
            ->keyBy('operation')
            ->toArray();

        return [
            'period' => [
                'from' => $from->toISOString(),
                'to' => $to->toISOString(),
            ],
            'total_operations' => $totalOperations,
            'successful_operations' => $successfulOperations,
            'failed_operations' => $failedOperations,
            'success_rate' => $totalOperations > 0 
                ? round(($successfulOperations / $totalOperations) * 100, 2) 
                : 0,
            'total_records_processed' => (int) $totalRecordsProcessed,
            'average_duration_ms' => $avgDuration ? round($avgDuration, 2) : null,
            'by_operation' => $byOperation,
        ];
    }

    /**
     * Get the last sync log entry
     * 
     * @return SyncLog|null
     */
    public function getLastSyncLog(): ?SyncLog
    {
        return SyncLog::ofOperation(SyncLog::OPERATION_SYNC)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Get the last successful sync log entry
     * 
     * @return SyncLog|null
     */
    public function getLastSuccessfulSync(): ?SyncLog
    {
        return SyncLog::ofOperation(SyncLog::OPERATION_SYNC)
            ->where('status', SyncLog::STATUS_SUCCESS)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Check if there are recent failures (within threshold)
     * 
     * @param int $threshold Number of consecutive failures to trigger alert
     * @return bool
     */
    public function hasRecentFailures(int $threshold = 3): bool
    {
        $recentLogs = SyncLog::ofOperation(SyncLog::OPERATION_SYNC)
            ->orderBy('created_at', 'desc')
            ->limit($threshold)
            ->get();

        if ($recentLogs->count() < $threshold) {
            return false;
        }

        return $recentLogs->every(fn($log) => $log->status === SyncLog::STATUS_FAILED);
    }

    /**
     * Get consecutive failure count
     * 
     * @return int
     */
    public function getConsecutiveFailureCount(): int
    {
        $count = 0;
        $logs = SyncLog::ofOperation(SyncLog::OPERATION_SYNC)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        foreach ($logs as $log) {
            if ($log->status === SyncLog::STATUS_FAILED) {
                $count++;
            } else {
                break;
            }
        }

        return $count;
    }

    /**
     * Truncate error message to prevent storage issues
     * 
     * @param string|null $message The error message
     * @return string|null
     */
    protected function truncateErrorMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        // Truncate to 2000 characters
        if (strlen($message) > 2000) {
            return substr($message, 0, 2000) . '... [truncated]';
        }

        return $message;
    }
}
