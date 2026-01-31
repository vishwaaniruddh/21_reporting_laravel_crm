<?php

namespace App\Services;

use App\Models\TableSyncConfiguration;
use App\Models\TableSyncLog;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * TableSyncLogService handles logging and querying of table sync operations.
 * 
 * All logs are stored in PostgreSQL table_sync_logs table.
 * This service provides methods to log sync operations with timestamps,
 * track records processed, duration, status, and store error details.
 * 
 * ⚠️ NO DELETION FROM MYSQL: All logs stored in PostgreSQL
 * 
 * Requirements: 6.1, 6.4, 6.6
 */
class TableSyncLogService
{
    /**
     * Start a new sync log entry.
     * 
     * @param int $configurationId The configuration ID
     * @param string $sourceTable The source table name
     * @return TableSyncLog
     */
    public function logSyncStart(int $configurationId, string $sourceTable): TableSyncLog
    {
        $log = TableSyncLog::startSync($configurationId, $sourceTable);

        Log::info('Table sync started', [
            'log_id' => $log->id,
            'configuration_id' => $configurationId,
            'source_table' => $sourceTable,
        ]);

        return $log;
    }

    /**
     * Complete a sync log with success.
     * 
     * @param TableSyncLog $log The sync log to complete
     * @param int $recordsSynced Number of records synced
     * @param int|null $startId First record ID in range
     * @param int|null $endId Last record ID in range
     * @return bool
     */
    public function logSyncComplete(
        TableSyncLog $log,
        int $recordsSynced,
        ?int $startId = null,
        ?int $endId = null
    ): bool {
        $result = $log->completeSuccess($recordsSynced, $startId, $endId);

        Log::info('Table sync completed successfully', [
            'log_id' => $log->id,
            'configuration_id' => $log->configuration_id,
            'source_table' => $log->source_table,
            'records_synced' => $recordsSynced,
            'duration_ms' => $log->duration_ms,
        ]);

        return $result;
    }

    /**
     * Complete a sync log with partial success.
     * 
     * @param TableSyncLog $log The sync log to complete
     * @param int $recordsSynced Number of records synced
     * @param int $recordsFailed Number of records failed
     * @param string|null $errorMessage Error message
     * @param int|null $startId First record ID in range
     * @param int|null $endId Last record ID in range
     * @return bool
     */
    public function logSyncPartial(
        TableSyncLog $log,
        int $recordsSynced,
        int $recordsFailed,
        ?string $errorMessage = null,
        ?int $startId = null,
        ?int $endId = null
    ): bool {
        $result = $log->completePartial($recordsSynced, $recordsFailed, $errorMessage, $startId, $endId);

        Log::warning('Table sync completed with partial success', [
            'log_id' => $log->id,
            'configuration_id' => $log->configuration_id,
            'source_table' => $log->source_table,
            'records_synced' => $recordsSynced,
            'records_failed' => $recordsFailed,
            'duration_ms' => $log->duration_ms,
        ]);

        return $result;
    }

    /**
     * Complete a sync log with failure.
     * 
     * @param TableSyncLog $log The sync log to complete
     * @param string $errorMessage The error message
     * @param int $recordsSynced Number of records synced before failure
     * @param int $recordsFailed Number of records failed
     * @param int|null $startId First record ID in range
     * @param int|null $endId Last record ID in range
     * @return bool
     */
    public function logSyncError(
        TableSyncLog $log,
        string $errorMessage,
        int $recordsSynced = 0,
        int $recordsFailed = 0,
        ?int $startId = null,
        ?int $endId = null
    ): bool {
        $result = $log->completeFailed(
            $this->truncateErrorMessage($errorMessage),
            $recordsSynced,
            $recordsFailed,
            $startId,
            $endId
        );

        Log::error('Table sync failed', [
            'log_id' => $log->id,
            'configuration_id' => $log->configuration_id,
            'source_table' => $log->source_table,
            'error' => $errorMessage,
            'records_synced' => $recordsSynced,
            'records_failed' => $recordsFailed,
            'start_id' => $startId,
            'end_id' => $endId,
        ]);

        return $result;
    }


    /**
     * Get logs for a specific configuration with filtering.
     * 
     * @param int $configurationId The configuration ID
     * @param array $filters Optional filters (status, date_from, date_to)
     * @param int $perPage Number of records per page
     * @return LengthAwarePaginator
     */
    public function getLogsForConfiguration(
        int $configurationId,
        array $filters = [],
        int $perPage = 20
    ): LengthAwarePaginator {
        $query = TableSyncLog::forConfiguration($configurationId)
            ->orderBy('started_at', 'desc');

        $this->applyFilters($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * Get logs for a specific source table with filtering.
     * 
     * @param string $sourceTable The source table name
     * @param array $filters Optional filters (status, date_from, date_to)
     * @param int $perPage Number of records per page
     * @return LengthAwarePaginator
     */
    public function getLogsForTable(
        string $sourceTable,
        array $filters = [],
        int $perPage = 20
    ): LengthAwarePaginator {
        $query = TableSyncLog::forTable($sourceTable)
            ->orderBy('started_at', 'desc');

        $this->applyFilters($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * Get all logs with filtering and pagination.
     * 
     * @param array $filters Optional filters (configuration_id, source_table, status, date_from, date_to)
     * @param int $perPage Number of records per page
     * @return LengthAwarePaginator
     */
    public function getLogs(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = TableSyncLog::query()->orderBy('started_at', 'desc');

        if (!empty($filters['configuration_id'])) {
            $query->forConfiguration($filters['configuration_id']);
        }

        if (!empty($filters['source_table'])) {
            $query->forTable($filters['source_table']);
        }

        $this->applyFilters($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * Get recent logs (last N days).
     * 
     * @param int $days Number of days to look back
     * @param int|null $configurationId Optional configuration ID filter
     * @return Collection
     */
    public function getRecentLogs(int $days = 30, ?int $configurationId = null): Collection
    {
        $query = TableSyncLog::recent($days)->orderBy('started_at', 'desc');

        if ($configurationId !== null) {
            $query->forConfiguration($configurationId);
        }

        return $query->get();
    }

    /**
     * Get failed sync logs.
     * 
     * @param int $days Number of days to look back
     * @param int|null $configurationId Optional configuration ID filter
     * @return Collection
     */
    public function getFailedLogs(int $days = 7, ?int $configurationId = null): Collection
    {
        $query = TableSyncLog::ofStatus(TableSyncLog::STATUS_FAILED)
            ->where('started_at', '>=', now()->subDays($days))
            ->orderBy('started_at', 'desc');

        if ($configurationId !== null) {
            $query->forConfiguration($configurationId);
        }

        return $query->get();
    }

    /**
     * Get the last sync log for a configuration.
     * 
     * @param int $configurationId The configuration ID
     * @return TableSyncLog|null
     */
    public function getLastLog(int $configurationId): ?TableSyncLog
    {
        return TableSyncLog::forConfiguration($configurationId)
            ->orderBy('started_at', 'desc')
            ->first();
    }

    /**
     * Get the last successful sync log for a configuration.
     * 
     * @param int $configurationId The configuration ID
     * @return TableSyncLog|null
     */
    public function getLastSuccessfulLog(int $configurationId): ?TableSyncLog
    {
        return TableSyncLog::forConfiguration($configurationId)
            ->ofStatus(TableSyncLog::STATUS_COMPLETED)
            ->orderBy('started_at', 'desc')
            ->first();
    }

    /**
     * Get sync statistics for a configuration.
     * 
     * @param int $configurationId The configuration ID
     * @param Carbon|null $from Start date
     * @param Carbon|null $to End date
     * @return array
     */
    public function getStatisticsForConfiguration(
        int $configurationId,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): array {
        $from = $from ?? now()->subDays(30);
        $to = $to ?? now();

        $query = TableSyncLog::forConfiguration($configurationId)
            ->whereBetween('started_at', [$from->startOfDay(), $to->endOfDay()]);

        return $this->calculateStatistics($query, $from, $to);
    }

    /**
     * Get overall sync statistics.
     * 
     * @param Carbon|null $from Start date
     * @param Carbon|null $to End date
     * @return array
     */
    public function getStatistics(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? now()->subDays(30);
        $to = $to ?? now();

        $query = TableSyncLog::whereBetween('started_at', [$from->startOfDay(), $to->endOfDay()]);

        return $this->calculateStatistics($query, $from, $to);
    }

    /**
     * Check if there are recent consecutive failures for a configuration.
     * 
     * @param int $configurationId The configuration ID
     * @param int $threshold Number of consecutive failures to trigger alert
     * @return bool
     */
    public function hasConsecutiveFailures(int $configurationId, int $threshold = 3): bool
    {
        $recentLogs = TableSyncLog::forConfiguration($configurationId)
            ->orderBy('started_at', 'desc')
            ->limit($threshold)
            ->get();

        if ($recentLogs->count() < $threshold) {
            return false;
        }

        return $recentLogs->every(fn($log) => $log->isFailed());
    }

    /**
     * Get consecutive failure count for a configuration.
     * 
     * @param int $configurationId The configuration ID
     * @return int
     */
    public function getConsecutiveFailureCount(int $configurationId): int
    {
        $count = 0;
        $logs = TableSyncLog::forConfiguration($configurationId)
            ->orderBy('started_at', 'desc')
            ->limit(10)
            ->get();

        foreach ($logs as $log) {
            if ($log->isFailed()) {
                $count++;
            } else {
                break;
            }
        }

        return $count;
    }

    /**
     * Check if a sync is currently running for a configuration.
     * 
     * @param int $configurationId The configuration ID
     * @return bool
     */
    public function isSyncRunning(int $configurationId): bool
    {
        return TableSyncLog::forConfiguration($configurationId)
            ->ofStatus(TableSyncLog::STATUS_RUNNING)
            ->exists();
    }

    /**
     * Get running sync log for a configuration.
     * 
     * @param int $configurationId The configuration ID
     * @return TableSyncLog|null
     */
    public function getRunningLog(int $configurationId): ?TableSyncLog
    {
        return TableSyncLog::forConfiguration($configurationId)
            ->ofStatus(TableSyncLog::STATUS_RUNNING)
            ->first();
    }

    /**
     * Apply filters to a query.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     */
    protected function applyFilters($query, array $filters): void
    {
        if (!empty($filters['status'])) {
            $query->ofStatus($filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('started_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (!empty($filters['date_to'])) {
            $query->where('started_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }
    }

    /**
     * Calculate statistics from a query.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param Carbon $from
     * @param Carbon $to
     * @return array
     */
    protected function calculateStatistics($query, Carbon $from, Carbon $to): array
    {
        $totalOperations = (clone $query)->count();
        $successfulOperations = (clone $query)->ofStatus(TableSyncLog::STATUS_COMPLETED)->count();
        $failedOperations = (clone $query)->ofStatus(TableSyncLog::STATUS_FAILED)->count();
        $partialOperations = (clone $query)->ofStatus(TableSyncLog::STATUS_PARTIAL)->count();
        $totalRecordsSynced = (clone $query)->sum('records_synced');
        $totalRecordsFailed = (clone $query)->sum('records_failed');
        $avgDuration = (clone $query)->whereNotNull('duration_ms')->avg('duration_ms');

        // Get counts by source table
        $byTable = (clone $query)
            ->selectRaw('source_table, COUNT(*) as count, SUM(records_synced) as total_synced, SUM(records_failed) as total_failed')
            ->groupBy('source_table')
            ->get()
            ->keyBy('source_table')
            ->toArray();

        return [
            'period' => [
                'from' => $from->toISOString(),
                'to' => $to->toISOString(),
            ],
            'total_operations' => $totalOperations,
            'successful_operations' => $successfulOperations,
            'failed_operations' => $failedOperations,
            'partial_operations' => $partialOperations,
            'success_rate' => $totalOperations > 0
                ? round(($successfulOperations / $totalOperations) * 100, 2)
                : 0,
            'total_records_synced' => (int) $totalRecordsSynced,
            'total_records_failed' => (int) $totalRecordsFailed,
            'average_duration_ms' => $avgDuration ? round($avgDuration, 2) : null,
            'by_table' => $byTable,
        ];
    }

    /**
     * Truncate error message to prevent storage issues.
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
