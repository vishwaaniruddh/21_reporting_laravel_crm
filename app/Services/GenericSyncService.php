<?php

namespace App\Services;

use App\Models\TableSyncConfiguration;
use App\Models\TableSyncLog;
use App\Models\TableSyncError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Exception;
use PDOException;
use Illuminate\Database\QueryException;

/**
 * GenericSyncService handles synchronization of any configured MySQL table to PostgreSQL.
 * 
 * This service implements:
 * - Dynamic table sync based on configuration
 * - Batch fetching from MySQL (READ-ONLY)
 * - Bulk insert to PostgreSQL with column mapping
 * - Sync marker updates in MySQL (UPDATE only synced_at column)
 * 
 * ⚠️ NO DELETION FROM MYSQL: Only SELECT and UPDATE (sync marker) operations allowed on MySQL
 * 
 * Requirements: 3.2, 3.3, 4.1, 4.2, 4.3, 4.4, 4.5
 */
class GenericSyncService
{
    protected SchemaDetectorService $schemaDetector;
    protected ColumnMapperService $columnMapper;
    protected SyncLockService $lockService;
    protected RetryService $retryService;
    protected ErrorThresholdService $errorThresholdService;

    /**
     * Retry configuration
     */
    protected int $maxRetries;
    protected int $initialDelaySeconds;
    protected int $maxDelaySeconds;

    /**
     * Lock timeout in seconds
     */
    protected int $lockTimeout = 3600;

    /**
     * Error threshold configuration
     */
    protected int $warningThreshold;
    protected int $criticalThreshold;

    /**
     * Consecutive failure tracking cache key prefix
     */
    protected string $failureCountPrefix = 'table_sync_failures_';

    public function __construct(
        SchemaDetectorService $schemaDetector,
        ColumnMapperService $columnMapper,
        ?SyncLockService $lockService = null,
        ?RetryService $retryService = null,
        ?ErrorThresholdService $errorThresholdService = null
    ) {
        $this->schemaDetector = $schemaDetector;
        $this->columnMapper = $columnMapper;
        $this->lockService = $lockService ?? new SyncLockService();
        $this->retryService = $retryService ?? new RetryService();
        $this->errorThresholdService = $errorThresholdService ?? new ErrorThresholdService();
        $this->maxRetries = (int) config('pipeline.retry.max_attempts', 5);
        $this->initialDelaySeconds = (int) config('pipeline.retry.initial_delay_seconds', 1);
        $this->maxDelaySeconds = (int) config('pipeline.retry.max_delay_seconds', 30);
        $this->warningThreshold = (int) config('pipeline.alerts.warning_failures', 3);
        $this->criticalThreshold = (int) config('pipeline.alerts.critical_failures', 5);
    }


    /**
     * Sync a table based on its configuration ID.
     * 
     * @param int|string $configId Configuration ID or source table name
     * @return GenericSyncResult
     */
    public function syncTable($configId): GenericSyncResult
    {
        // Get configuration
        $config = $this->getConfiguration($configId);
        
        if (!$config) {
            return new GenericSyncResult(
                success: false,
                recordsSynced: 0,
                recordsFailed: 0,
                errorMessage: "Configuration not found for: {$configId}"
            );
        }

        if (!$config->is_enabled) {
            return new GenericSyncResult(
                success: false,
                recordsSynced: 0,
                recordsFailed: 0,
                errorMessage: "Sync is disabled for table: {$config->source_table}"
            );
        }

        // Check if sync is paused due to error threshold using ErrorThresholdService
        if ($this->errorThresholdService->isSyncPaused($config->id)) {
            return new GenericSyncResult(
                success: false,
                recordsSynced: 0,
                recordsFailed: 0,
                errorMessage: "Sync is paused for table: {$config->source_table} due to consecutive failures exceeding threshold"
            );
        }

        // Acquire lock to prevent concurrent syncs using SyncLockService
        if (!$this->lockService->acquireLock($config->id, $this->lockTimeout)) {
            return new GenericSyncResult(
                success: false,
                recordsSynced: 0,
                recordsFailed: 0,
                errorMessage: "Sync already running for table: {$config->source_table}"
            );
        }

        // Start sync log
        $syncLog = TableSyncLog::startSync($config->id, $config->source_table);
        $config->updateSyncStatus(TableSyncConfiguration::STATUS_RUNNING);

        try {
            // Validate source table exists
            if (!$this->schemaDetector->tableExists($config->source_table, 'mysql')) {
                throw new Exception("Source table '{$config->source_table}' does not exist in MySQL");
            }

            // Ensure sync marker column exists
            $this->ensureSyncMarkerColumn($config);

            // Ensure target table exists in PostgreSQL
            $this->ensureTargetTable($config);

            // Process batches
            $result = $this->processBatches($config, $syncLog);

            // Update configuration status
            $config->updateSyncStatus(
                $result->success ? TableSyncConfiguration::STATUS_COMPLETED : TableSyncConfiguration::STATUS_FAILED,
                now()
            );

            // Record success or failure with ErrorThresholdService
            if ($result->success) {
                $this->errorThresholdService->recordSuccess($config->id);
            } else {
                $this->errorThresholdService->recordFailure($config->id, $result->errorMessage);
            }

            return $result;

        } catch (Exception $e) {
            $errorMessage = $this->formatErrorMessage($e);
            
            $syncLog->completeFailed($errorMessage);
            $config->updateSyncStatus(TableSyncConfiguration::STATUS_FAILED);

            // Record failure with ErrorThresholdService
            $this->errorThresholdService->recordFailure($config->id, $errorMessage);

            Log::error('Table sync failed', [
                'config_id' => $config->id,
                'source_table' => $config->source_table,
                'error' => $errorMessage,
            ]);

            return new GenericSyncResult(
                success: false,
                recordsSynced: 0,
                recordsFailed: 0,
                errorMessage: $errorMessage
            );

        } finally {
            $this->lockService->releaseLock($config->id);
        }
    }

    /**
     * Sync all enabled tables.
     * 
     * @return array Array of GenericSyncResult keyed by configuration ID
     */
    public function syncAllTables(): array
    {
        $results = [];
        $configs = TableSyncConfiguration::enabled()->get();

        foreach ($configs as $config) {
            $results[$config->id] = $this->syncTable($config->id);
        }

        return $results;
    }

    /**
     * Get count of unsynced records for a configuration.
     * 
     * @param int|string $configId Configuration ID or source table name
     * @return int
     */
    public function getUnsyncedCount($configId): int
    {
        $config = $this->getConfiguration($configId);
        
        if (!$config) {
            return 0;
        }

        if (!$this->schemaDetector->tableExists($config->source_table, 'mysql')) {
            return 0;
        }

        $syncMarkerColumn = $config->sync_marker_column ?? 'synced_at';

        // Check if sync marker column exists
        if (!$this->schemaDetector->columnExists($config->source_table, $syncMarkerColumn, 'mysql')) {
            // If no sync marker, all records are unsynced
            return DB::connection('mysql')
                ->table($config->source_table)
                ->count();
        }

        return DB::connection('mysql')
            ->table($config->source_table)
            ->whereNull($syncMarkerColumn)
            ->count();
    }

    /**
     * Get sync status for a configuration.
     * 
     * @param int|string $configId Configuration ID or source table name
     * @return string
     */
    public function getSyncStatus($configId): string
    {
        $config = $this->getConfiguration($configId);
        
        if (!$config) {
            return 'unknown';
        }

        // Check if sync is currently running using SyncLockService
        if ($this->lockService->isLocked($config->id)) {
            return TableSyncConfiguration::STATUS_RUNNING;
        }

        // Check if sync is paused due to error threshold using ErrorThresholdService
        if ($this->errorThresholdService->isSyncPaused($config->id)) {
            return 'paused';
        }

        return $config->last_sync_status ?? TableSyncConfiguration::STATUS_IDLE;
    }


    /**
     * Add sync marker column to source table if it doesn't exist.
     * 
     * ⚠️ NO DELETION FROM MYSQL: Only adds a column, NEVER deletes records or columns
     * 
     * @param string $tableName The source table name
     * @param string $columnName The sync marker column name (default: 'synced_at')
     * @return bool True if column was added or already exists
     */
    public function addSyncMarkerColumn(string $tableName, string $columnName = 'synced_at'): bool
    {
        if (!$this->schemaDetector->tableExists($tableName, 'mysql')) {
            throw new Exception("Table '{$tableName}' does not exist in MySQL");
        }

        // Check if column already exists
        if ($this->checkSyncMarkerExists($tableName, $columnName)) {
            return true;
        }

        try {
            // Add the sync marker column - ALTER TABLE ADD COLUMN only
            DB::connection('mysql')->statement(
                "ALTER TABLE `{$tableName}` ADD COLUMN `{$columnName}` TIMESTAMP NULL DEFAULT NULL"
            );

            Log::info('Added sync marker column to source table', [
                'table' => $tableName,
                'column' => $columnName,
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Failed to add sync marker column', [
                'table' => $tableName,
                'column' => $columnName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Check if sync marker column exists in source table.
     * 
     * @param string $tableName The source table name
     * @param string $columnName The sync marker column name
     * @return bool
     */
    public function checkSyncMarkerExists(string $tableName, string $columnName = 'synced_at'): bool
    {
        return $this->schemaDetector->columnExists($tableName, $columnName, 'mysql');
    }

    /**
     * Ensure sync marker column exists for a configuration.
     * 
     * @param TableSyncConfiguration $config
     * @return void
     */
    protected function ensureSyncMarkerColumn(TableSyncConfiguration $config): void
    {
        $syncMarkerColumn = $config->sync_marker_column ?? 'synced_at';
        
        if (!$this->checkSyncMarkerExists($config->source_table, $syncMarkerColumn)) {
            $this->addSyncMarkerColumn($config->source_table, $syncMarkerColumn);
        }
    }

    /**
     * Ensure target table exists in PostgreSQL.
     * 
     * ⚠️ NO DELETION FROM MYSQL: Only creates tables in PostgreSQL
     * 
     * @param TableSyncConfiguration $config
     * @return void
     */
    protected function ensureTargetTable(TableSyncConfiguration $config): void
    {
        $targetTable = $config->getEffectiveTargetTable();

        // Check if target table already exists
        if (Schema::connection('pgsql')->hasTable($targetTable)) {
            return;
        }

        // Get source schema
        $sourceSchema = $this->schemaDetector->getTableSchema($config->source_table, 'mysql');
        
        // Get column mappings and exclusions
        $mappings = $config->column_mappings ?? [];
        $excluded = $config->excluded_columns ?? [];

        // Generate target schema
        $targetSchema = $this->columnMapper->generateTargetSchema($sourceSchema, $mappings, $excluded);

        // Get primary key
        $primaryKey = $config->primary_key_column ?? $this->schemaDetector->getPrimaryKey($config->source_table, 'mysql');

        // Apply column mapping to primary key if needed
        if ($primaryKey && isset($mappings[$primaryKey])) {
            $primaryKey = $mappings[$primaryKey];
        }

        // Generate and execute CREATE TABLE DDL
        $ddl = $this->columnMapper->generateCreateTableDDL($targetTable, $targetSchema, $primaryKey);

        try {
            DB::connection('pgsql')->statement($ddl);

            Log::info('Created target table in PostgreSQL', [
                'table' => $targetTable,
                'source_table' => $config->source_table,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to create target table', [
                'table' => $targetTable,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }


    /**
     * Process batches of records for sync.
     * 
     * ⚠️ NO DELETION FROM MYSQL: Transactions and rollbacks only affect PostgreSQL
     * 
     * @param TableSyncConfiguration $config
     * @param TableSyncLog $syncLog
     * @return GenericSyncResult
     */
    protected function processBatches(TableSyncConfiguration $config, TableSyncLog $syncLog): GenericSyncResult
    {
        $totalSynced = 0;
        $totalFailed = 0;
        $startId = null;
        $endId = null;
        $lastProcessedId = 0;
        $batchSize = $config->batch_size ?? 10000;
        $primaryKey = $config->primary_key_column ?? 'id';
        $syncMarkerColumn = $config->sync_marker_column ?? 'synced_at';

        while (true) {
            // Fetch batch of unsynced records from MySQL (READ-ONLY)
            $batch = $this->fetchUnsyncedBatch($config, $lastProcessedId, $batchSize);

            if ($batch->isEmpty()) {
                break;
            }

            // Track ID range
            $batchStartId = $batch->first()->{$primaryKey};
            $batchEndId = $batch->last()->{$primaryKey};

            if ($startId === null) {
                $startId = $batchStartId;
            }
            $endId = $batchEndId;

            try {
                // Process this batch with transaction
                $batchResult = $this->processSingleBatch($config, $batch);

                $totalSynced += $batchResult['synced'];
                $totalFailed += $batchResult['failed'];
                $lastProcessedId = $batchEndId;

                // Checkpoint after successful batch
                $this->checkpoint($config->id, $lastProcessedId);

            } catch (Exception $e) {
                // Batch failed - log and continue with next batch
                $totalFailed += $batch->count();
                $lastProcessedId = $batchEndId;

                Log::warning('Batch failed, continuing with next batch', [
                    'config_id' => $config->id,
                    'batch_start_id' => $batchStartId,
                    'batch_end_id' => $batchEndId,
                    'error' => $e->getMessage(),
                ]);

                // Add failed records to error queue
                $this->addBatchToErrorQueue($config, $batch, $e->getMessage());
            }
        }

        // Complete sync log
        if ($totalFailed > 0 && $totalSynced > 0) {
            $syncLog->completePartial($totalSynced, $totalFailed, null, $startId, $endId);
        } elseif ($totalFailed > 0) {
            $syncLog->completeFailed("All batches failed", $totalSynced, $totalFailed, $startId, $endId);
        } else {
            $syncLog->completeSuccess($totalSynced, $startId, $endId);
        }

        return new GenericSyncResult(
            success: $totalFailed === 0,
            recordsSynced: $totalSynced,
            recordsFailed: $totalFailed,
            startId: $startId,
            endId: $endId,
            errorMessage: $totalFailed > 0 ? "{$totalFailed} records failed to sync" : null
        );
    }

    /**
     * Fetch a batch of unsynced records from MySQL.
     * 
     * ⚠️ NO DELETION FROM MYSQL: Only SELECT operations
     * 
     * @param TableSyncConfiguration $config
     * @param int $afterId Fetch records with ID greater than this
     * @param int $limit Batch size
     * @return Collection
     */
    protected function fetchUnsyncedBatch(TableSyncConfiguration $config, int $afterId, int $limit): Collection
    {
        $primaryKey = $config->primary_key_column ?? 'id';
        $syncMarkerColumn = $config->sync_marker_column ?? 'synced_at';

        $query = DB::connection('mysql')
            ->table($config->source_table)
            ->whereNull($syncMarkerColumn)
            ->orderBy($primaryKey, 'asc');

        if ($afterId > 0) {
            $query->where($primaryKey, '>', $afterId);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Process a single batch with PostgreSQL transaction.
     * 
     * ⚠️ NO DELETION FROM MYSQL: Transaction only affects PostgreSQL
     * 
     * @param TableSyncConfiguration $config
     * @param Collection $batch
     * @return array ['synced' => int, 'failed' => int]
     */
    protected function processSingleBatch(TableSyncConfiguration $config, Collection $batch): array
    {
        $synced = 0;
        $failed = 0;
        $primaryKey = $config->primary_key_column ?? 'id';
        $syncMarkerColumn = $config->sync_marker_column ?? 'synced_at';
        $targetTable = $config->getEffectiveTargetTable();
        $mappings = $config->column_mappings ?? [];
        $excluded = $config->excluded_columns ?? [];

        // Get source schema for type conversion
        $sourceSchema = $this->schemaDetector->getTableSchema($config->source_table, 'mysql');
        $columnTypes = [];
        foreach ($sourceSchema as $col) {
            $columnTypes[$col['name']] = $col['data_type'];
        }

        // Prepare records for insert
        $insertData = [];
        $recordIds = [];

        foreach ($batch as $record) {
            try {
                $recordArray = (array) $record;
                $recordIds[] = $recordArray[$primaryKey];

                // Apply column mapping and exclusions
                $mappedRecord = $this->columnMapper->mapColumns($recordArray, $mappings, $excluded);

                // Convert data types
                foreach ($mappedRecord as $column => $value) {
                    // Find original column name for type lookup
                    $originalColumn = array_search($column, $mappings) ?: $column;
                    if (isset($columnTypes[$originalColumn])) {
                        $mappedRecord[$column] = $this->columnMapper->convertType(
                            $value,
                            $columnTypes[$originalColumn]
                        );
                    }
                }

                // Add synced_at timestamp to target record
                $mappedRecord['synced_at'] = now();

                $insertData[] = $mappedRecord;

            } catch (Exception $e) {
                $failed++;
                Log::warning('Failed to prepare record for sync', [
                    'record_id' => $record->{$primaryKey} ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (empty($insertData)) {
            return ['synced' => 0, 'failed' => $failed];
        }

        // Insert to PostgreSQL with retry logic
        $this->bulkInsertToPostgresWithRetry($targetTable, $insertData);

        // Update sync markers in MySQL (UPDATE only, NEVER DELETE)
        $this->updateSyncMarkers($config->source_table, $syncMarkerColumn, $recordIds);

        $synced = count($insertData);

        return ['synced' => $synced, 'failed' => $failed];
    }


    /**
     * Bulk insert records to PostgreSQL with retry logic.
     * 
     * ⚠️ NO DELETION FROM MYSQL: Only writes to PostgreSQL
     * 
     * @param string $tableName Target table name
     * @param array $records Records to insert
     * @throws Exception If insert fails after all retries
     */
    protected function bulkInsertToPostgresWithRetry(string $tableName, array $records): void
    {
        $this->retryService->executeWithRetry(
            function () use ($tableName, $records) {
                DB::connection('pgsql')->transaction(function () use ($tableName, $records) {
                    // Insert in chunks to prevent memory issues
                    $chunks = array_chunk($records, 1000);
                    foreach ($chunks as $chunk) {
                        DB::connection('pgsql')->table($tableName)->insert($chunk);
                    }
                });
            },
            'PostgreSQL bulk insert',
            ['table' => $tableName, 'record_count' => count($records)]
        );
    }

    /**
     * Update sync markers in MySQL source table.
     * 
     * ⚠️ NO DELETION FROM MYSQL: Only UPDATE operations on sync marker column
     * 
     * @param string $tableName Source table name
     * @param string $syncMarkerColumn Sync marker column name
     * @param array $recordIds Record IDs to mark as synced
     */
    protected function updateSyncMarkers(string $tableName, string $syncMarkerColumn, array $recordIds): void
    {
        $now = now();

        // Update in chunks to prevent long-running queries
        $chunks = array_chunk($recordIds, 1000);
        foreach ($chunks as $chunk) {
            DB::connection('mysql')
                ->table($tableName)
                ->whereIn('id', $chunk)
                ->update([$syncMarkerColumn => $now]);
        }
    }

    /**
     * Add failed batch records to error queue.
     * 
     * @param TableSyncConfiguration $config
     * @param Collection $batch
     * @param string $errorMessage
     */
    protected function addBatchToErrorQueue(TableSyncConfiguration $config, Collection $batch, string $errorMessage): void
    {
        $primaryKey = $config->primary_key_column ?? 'id';

        foreach ($batch as $record) {
            try {
                $recordArray = (array) $record;
                TableSyncError::addToQueue(
                    $config->id,
                    $config->source_table,
                    $recordArray[$primaryKey],
                    $recordArray,
                    $errorMessage
                );
            } catch (Exception $e) {
                Log::error('Failed to add record to error queue', [
                    'config_id' => $config->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get configuration by ID or source table name.
     * 
     * @param int|string $configId
     * @return TableSyncConfiguration|null
     */
    protected function getConfiguration($configId): ?TableSyncConfiguration
    {
        if (is_numeric($configId)) {
            return TableSyncConfiguration::find($configId);
        }

        return TableSyncConfiguration::where('source_table', $configId)->first();
    }

    /**
     * Get lock key for a configuration.
     * @deprecated Use SyncLockService instead
     * 
     * @param int $configId
     * @return string
     */
    protected function getLockKey(int $configId): string
    {
        return "table_sync_lock_{$configId}";
    }

    /**
     * Acquire lock for sync operation.
     * @deprecated Use SyncLockService instead
     * 
     * @param string $lockKey
     * @return bool
     */
    protected function acquireLock(string $lockKey): bool
    {
        return Cache::add($lockKey, true, $this->lockTimeout);
    }

    /**
     * Release lock after sync operation.
     * @deprecated Use SyncLockService instead
     * 
     * @param string $lockKey
     */
    protected function releaseLock(string $lockKey): void
    {
        Cache::forget($lockKey);
    }

    /**
     * Check if sync is paused due to consecutive failures exceeding threshold.
     * @deprecated Use ErrorThresholdService instead
     * 
     * ⚠️ NO DELETION FROM MYSQL: Alerting does not modify MySQL
     * 
     * @param int $configId Configuration ID
     * @return bool True if sync is paused
     */
    public function isSyncPaused(int $configId): bool
    {
        return $this->errorThresholdService->isSyncPaused($configId);
    }

    /**
     * Pause sync for a configuration due to error threshold.
     * @deprecated Use ErrorThresholdService instead
     * 
     * @param int $configId Configuration ID
     * @param int $pauseDuration Duration in seconds (default: 1 hour)
     */
    public function pauseSync(int $configId, int $pauseDuration = 3600): void
    {
        $this->errorThresholdService->pauseSync($configId, $pauseDuration);
    }

    /**
     * Resume sync for a configuration (clear pause state).
     * @deprecated Use ErrorThresholdService instead
     * 
     * @param int $configId Configuration ID
     */
    public function resumeSync(int $configId): void
    {
        $this->errorThresholdService->resumeSync($configId);
    }

    /**
     * Get the consecutive failure count for a configuration.
     * @deprecated Use ErrorThresholdService instead
     * 
     * @param int $configId Configuration ID
     * @return int
     */
    public function getFailureCount(int $configId): int
    {
        return $this->errorThresholdService->getFailureCount($configId);
    }

    /**
     * Increment the consecutive failure count and check threshold.
     * @deprecated Use ErrorThresholdService instead
     * 
     * ⚠️ NO DELETION FROM MYSQL: Alerting does not modify MySQL
     * 
     * @param int $configId Configuration ID
     * @return int New failure count
     */
    protected function incrementFailureCount(int $configId): int
    {
        $result = $this->errorThresholdService->recordFailure($configId);
        return $result['failure_count'];
    }

    /**
     * Reset the consecutive failure count for a configuration.
     * @deprecated Use ErrorThresholdService instead
     * 
     * @param int $configId Configuration ID
     */
    protected function resetFailureCount(int $configId): void
    {
        $this->errorThresholdService->recordSuccess($configId);
    }

    /**
     * Get the cache key for paused state.
     * @deprecated Use ErrorThresholdService instead
     * 
     * @param int $configId Configuration ID
     * @return string
     */
    protected function getPausedKey(int $configId): string
    {
        return "table_sync_paused_{$configId}";
    }

    /**
     * Get the SyncLockService instance.
     * 
     * @return SyncLockService
     */
    public function getLockService(): SyncLockService
    {
        return $this->lockService;
    }

    /**
     * Get the ErrorThresholdService instance.
     * 
     * @return ErrorThresholdService
     */
    public function getErrorThresholdService(): ErrorThresholdService
    {
        return $this->errorThresholdService;
    }

    /**
     * Save checkpoint for resume capability.
     * 
     * @param int $configId
     * @param int $lastProcessedId
     */
    protected function checkpoint(int $configId, int $lastProcessedId): void
    {
        Cache::put("table_sync_checkpoint_{$configId}", $lastProcessedId, 86400); // 24 hours
    }

    /**
     * Get last checkpoint for a configuration.
     * 
     * @param int $configId
     * @return int|null
     */
    public function getLastCheckpoint(int $configId): ?int
    {
        return Cache::get("table_sync_checkpoint_{$configId}");
    }

    /**
     * Calculate exponential backoff delay.
     * @deprecated Use RetryService instead
     * 
     * @param int $attempt Current attempt number (1-based)
     * @return int Delay in seconds
     */
    protected function calculateBackoffDelay(int $attempt): int
    {
        return $this->retryService->calculateBackoffDelay($attempt);
    }

    /**
     * Check if an exception is a retryable connection error.
     * @deprecated Use RetryService instead
     * 
     * @param Exception $e
     * @return bool
     */
    protected function isRetryableError(Exception $e): bool
    {
        return $this->retryService->isRetryableError($e);
    }

    /**
     * Get the RetryService instance.
     * 
     * @return RetryService
     */
    public function getRetryService(): RetryService
    {
        return $this->retryService;
    }

    /**
     * Format error message for logging and storage.
     * 
     * @param Exception $e
     * @return string
     */
    protected function formatErrorMessage(Exception $e): string
    {
        $message = $e->getMessage();
        
        if (strlen($message) > 1000) {
            $message = substr($message, 0, 1000) . '... [truncated]';
        }

        return $message;
    }
}
