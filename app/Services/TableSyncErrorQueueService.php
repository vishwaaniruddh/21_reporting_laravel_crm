<?php

namespace App\Services;

use App\Models\TableSyncConfiguration;
use App\Models\TableSyncError;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * TableSyncErrorQueueService manages the error queue for failed table sync records.
 * 
 * This service handles adding records to the error queue, retrying failed records,
 * and providing admin interface functionality for review and management.
 * 
 * ⚠️ NO DELETION FROM MYSQL: Error queue is in PostgreSQL, retry re-reads from MySQL
 * 
 * Requirements: 7.4, 7.5
 */
class TableSyncErrorQueueService
{
    protected GenericSyncService $syncService;
    protected ColumnMapperService $columnMapper;
    protected SchemaDetectorService $schemaDetector;

    public function __construct(
        GenericSyncService $syncService,
        ColumnMapperService $columnMapper,
        SchemaDetectorService $schemaDetector
    ) {
        $this->syncService = $syncService;
        $this->columnMapper = $columnMapper;
        $this->schemaDetector = $schemaDetector;
    }

    /**
     * Add a failed record to the error queue.
     * 
     * @param int $configurationId The configuration ID
     * @param string $sourceTable The source table name
     * @param int $recordId The record ID that failed
     * @param array $recordData The record data
     * @param string $errorMessage The error message
     * @return TableSyncError
     */
    public function addToQueue(
        int $configurationId,
        string $sourceTable,
        int $recordId,
        array $recordData,
        string $errorMessage
    ): TableSyncError {
        $error = TableSyncError::addToQueue(
            $configurationId,
            $sourceTable,
            $recordId,
            $recordData,
            $this->truncateErrorMessage($errorMessage)
        );

        Log::info('Added record to table sync error queue', [
            'error_id' => $error->id,
            'configuration_id' => $configurationId,
            'source_table' => $sourceTable,
            'record_id' => $recordId,
            'retry_count' => $error->retry_count,
        ]);

        return $error;
    }

    /**
     * Add multiple failed records to the error queue.
     * 
     * @param int $configurationId The configuration ID
     * @param string $sourceTable The source table name
     * @param Collection $records Collection of failed records
     * @param string $errorMessage The error message
     * @param string $primaryKey The primary key column name
     * @return int Number of records added
     */
    public function addBatchToQueue(
        int $configurationId,
        string $sourceTable,
        Collection $records,
        string $errorMessage,
        string $primaryKey = 'id'
    ): int {
        $count = 0;

        foreach ($records as $record) {
            $recordArray = (array) $record;
            $recordId = $recordArray[$primaryKey] ?? null;

            if ($recordId !== null) {
                $this->addToQueue(
                    $configurationId,
                    $sourceTable,
                    $recordId,
                    $recordArray,
                    $errorMessage
                );
                $count++;
            }
        }

        return $count;
    }


    /**
     * Retry a single failed record from the error queue.
     * 
     * ⚠️ NO DELETION FROM MYSQL: Retry re-reads from MySQL, writes to PostgreSQL
     * 
     * @param TableSyncError $error The error record to retry
     * @return bool True if retry was successful
     */
    public function retryFromQueue(TableSyncError $error): bool
    {
        if (!$error->canRetry()) {
            Log::warning('Cannot retry error - max retries exceeded or already resolved', [
                'error_id' => $error->id,
                'retry_count' => $error->retry_count,
                'resolved_at' => $error->resolved_at,
            ]);
            return false;
        }

        try {
            // Get the configuration
            $config = TableSyncConfiguration::find($error->configuration_id);
            
            if (!$config) {
                $error->updateError('Configuration no longer exists');
                Log::warning('Cannot retry error - configuration not found', [
                    'error_id' => $error->id,
                    'configuration_id' => $error->configuration_id,
                ]);
                return false;
            }

            // Re-read the record from MySQL (READ-ONLY)
            $primaryKey = $config->primary_key_column ?? 'id';
            $syncMarkerColumn = $config->sync_marker_column ?? 'synced_at';

            $record = DB::connection('mysql')
                ->table($config->source_table)
                ->where($primaryKey, $error->record_id)
                ->first();

            if (!$record) {
                // Record no longer exists in MySQL - mark as resolved
                $error->markResolved();
                Log::info('Error resolved - original record no longer exists in MySQL', [
                    'error_id' => $error->id,
                    'record_id' => $error->record_id,
                ]);
                return true;
            }

            $recordArray = (array) $record;

            // Check if already synced
            if (!empty($recordArray[$syncMarkerColumn])) {
                $error->markResolved();
                Log::info('Error resolved - record was already synced', [
                    'error_id' => $error->id,
                    'record_id' => $error->record_id,
                ]);
                return true;
            }

            // Try to sync the single record
            $result = $this->syncSingleRecord($config, $recordArray);

            if ($result) {
                $error->markResolved();
                Log::info('Error resolved - record successfully synced on retry', [
                    'error_id' => $error->id,
                    'record_id' => $error->record_id,
                ]);
                return true;
            }

            // Retry failed
            $error->incrementRetry();
            Log::warning('Error retry unsuccessful', [
                'error_id' => $error->id,
                'record_id' => $error->record_id,
                'retry_count' => $error->retry_count,
            ]);
            return false;

        } catch (Exception $e) {
            $error->updateError($e->getMessage());
            Log::error('Exception during error retry', [
                'error_id' => $error->id,
                'record_id' => $error->record_id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Retry a specific error by ID.
     * 
     * @param int $errorId The error ID
     * @return bool True if retry was successful
     */
    public function retryById(int $errorId): bool
    {
        $error = TableSyncError::find($errorId);
        
        if (!$error) {
            return false;
        }

        return $this->retryFromQueue($error);
    }

    /**
     * Retry all eligible errors for a configuration.
     * 
     * @param int $configurationId The configuration ID
     * @return array Summary of retry results
     */
    public function retryAllForConfiguration(int $configurationId): array
    {
        $errors = TableSyncError::forConfiguration($configurationId)
            ->retryable()
            ->get();

        return $this->retryErrors($errors);
    }

    /**
     * Retry all eligible errors.
     * 
     * @return array Summary of retry results
     */
    public function retryAllEligible(): array
    {
        $errors = TableSyncError::retryable()->get();
        return $this->retryErrors($errors);
    }

    /**
     * Mark an error as resolved.
     * 
     * @param int $errorId The error ID
     * @return bool
     */
    public function markResolved(int $errorId): bool
    {
        $error = TableSyncError::find($errorId);
        
        if (!$error) {
            return false;
        }

        $result = $error->markResolved();

        Log::info('Error manually marked as resolved', [
            'error_id' => $errorId,
            'record_id' => $error->record_id,
        ]);

        return $result;
    }

    /**
     * Mark multiple errors as resolved.
     * 
     * @param array $errorIds Array of error IDs
     * @return int Number of errors resolved
     */
    public function markMultipleResolved(array $errorIds): int
    {
        $count = 0;

        foreach ($errorIds as $errorId) {
            if ($this->markResolved($errorId)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get queued errors with filtering.
     * 
     * @param array $filters Optional filters (configuration_id, source_table, resolved, retryable)
     * @param int $perPage Number of records per page
     * @return LengthAwarePaginator
     */
    public function getQueuedErrors(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = TableSyncError::query()->orderBy('created_at', 'desc');

        if (!empty($filters['configuration_id'])) {
            $query->forConfiguration($filters['configuration_id']);
        }

        if (!empty($filters['source_table'])) {
            $query->forTable($filters['source_table']);
        }

        if (isset($filters['resolved'])) {
            if ($filters['resolved']) {
                $query->resolved();
            } else {
                $query->unresolved();
            }
        }

        if (isset($filters['retryable']) && $filters['retryable']) {
            $query->retryable();
        }

        if (isset($filters['exceeded_max_retries']) && $filters['exceeded_max_retries']) {
            $query->exceededMaxRetries();
        }

        return $query->paginate($perPage);
    }

    /**
     * Get unresolved errors for a configuration.
     * 
     * @param int $configurationId The configuration ID
     * @return Collection
     */
    public function getUnresolvedForConfiguration(int $configurationId): Collection
    {
        return TableSyncError::forConfiguration($configurationId)
            ->unresolved()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get errors that have exceeded max retries.
     * 
     * @param int|null $configurationId Optional configuration ID filter
     * @return Collection
     */
    public function getExceededMaxRetries(?int $configurationId = null): Collection
    {
        $query = TableSyncError::exceededMaxRetries()
            ->orderBy('created_at', 'desc');

        if ($configurationId !== null) {
            $query->forConfiguration($configurationId);
        }

        return $query->get();
    }

    /**
     * Get error queue statistics.
     * 
     * @param int|null $configurationId Optional configuration ID filter
     * @return array
     */
    public function getStatistics(?int $configurationId = null): array
    {
        $baseQuery = TableSyncError::query();

        if ($configurationId !== null) {
            $baseQuery->forConfiguration($configurationId);
        }

        return [
            'total_unresolved' => (clone $baseQuery)->unresolved()->count(),
            'retryable' => (clone $baseQuery)->retryable()->count(),
            'exceeded_max_retries' => (clone $baseQuery)->exceededMaxRetries()->count(),
            'resolved' => (clone $baseQuery)->resolved()->count(),
            'total' => (clone $baseQuery)->count(),
        ];
    }

    /**
     * Get error count by configuration.
     * 
     * @return Collection
     */
    public function getErrorCountByConfiguration(): Collection
    {
        return TableSyncError::unresolved()
            ->selectRaw('configuration_id, source_table, COUNT(*) as error_count')
            ->groupBy('configuration_id', 'source_table')
            ->get();
    }

    /**
     * Clean up old resolved errors.
     * 
     * @param int $daysOld Errors older than this will be deleted
     * @return int Number of errors deleted
     */
    public function cleanupOldErrors(int $daysOld = 30): int
    {
        $deleted = TableSyncError::resolved()
            ->where('resolved_at', '<', now()->subDays($daysOld))
            ->delete();

        Log::info('Cleaned up old table sync errors', [
            'deleted' => $deleted,
            'days_old' => $daysOld,
        ]);

        return $deleted;
    }

    /**
     * Sync a single record to PostgreSQL.
     * 
     * ⚠️ NO DELETION FROM MYSQL: Only writes to PostgreSQL
     * 
     * @param TableSyncConfiguration $config
     * @param array $recordArray
     * @return bool
     */
    protected function syncSingleRecord(TableSyncConfiguration $config, array $recordArray): bool
    {
        $primaryKey = $config->primary_key_column ?? 'id';
        $syncMarkerColumn = $config->sync_marker_column ?? 'synced_at';
        $targetTable = $config->getEffectiveTargetTable();
        $mappings = $config->column_mappings ?? [];
        $excluded = $config->excluded_columns ?? [];

        try {
            // Get source schema for type conversion
            $sourceSchema = $this->schemaDetector->getTableSchema($config->source_table, 'mysql');
            $columnTypes = [];
            foreach ($sourceSchema as $col) {
                $columnTypes[$col['name']] = $col['data_type'];
            }

            // Apply column mapping and exclusions
            $mappedRecord = $this->columnMapper->mapColumns($recordArray, $mappings, $excluded);

            // Convert data types
            foreach ($mappedRecord as $column => $value) {
                $originalColumn = array_search($column, $mappings) ?: $column;
                if (isset($columnTypes[$originalColumn])) {
                    $mappedRecord[$column] = $this->columnMapper->convertType(
                        $value,
                        $columnTypes[$originalColumn]
                    );
                }
            }

            // Add synced_at timestamp
            $mappedRecord['synced_at'] = now();

            // Insert to PostgreSQL with transaction
            DB::connection('pgsql')->transaction(function () use ($targetTable, $mappedRecord, $config, $syncMarkerColumn, $recordArray, $primaryKey) {
                // Insert to PostgreSQL
                DB::connection('pgsql')->table($targetTable)->insert($mappedRecord);

                // Update sync marker in MySQL (UPDATE only, NEVER DELETE)
                DB::connection('mysql')
                    ->table($config->source_table)
                    ->where($primaryKey, $recordArray[$primaryKey])
                    ->update([$syncMarkerColumn => now()]);
            });

            return true;

        } catch (Exception $e) {
            Log::error('Failed to sync single record', [
                'configuration_id' => $config->id,
                'record_id' => $recordArray[$primaryKey] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Retry a collection of errors.
     * 
     * @param Collection $errors
     * @return array Summary of retry results
     */
    protected function retryErrors(Collection $errors): array
    {
        $results = [
            'total' => $errors->count(),
            'successful' => 0,
            'failed' => 0,
        ];

        foreach ($errors as $error) {
            if ($this->retryFromQueue($error)) {
                $results['successful']++;
            } else {
                $results['failed']++;
            }
        }

        Log::info('Completed retry of table sync errors', $results);

        return $results;
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

        if (strlen($message) > 2000) {
            return substr($message, 0, 2000) . '... [truncated]';
        }

        return $message;
    }
}
