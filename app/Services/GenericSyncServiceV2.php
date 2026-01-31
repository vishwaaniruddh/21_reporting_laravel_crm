<?php

namespace App\Services;

use App\Models\TableSyncConfiguration;
use App\Models\TableSyncLog;
use App\Models\TableSyncError;
use App\Models\SyncTracking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Exception;

/**
 * GenericSyncServiceV2 handles synchronization of any configured MySQL table to PostgreSQL.
 * 
 * This version uses a dedicated tracking table instead of modifying source tables.
 * 
 * Key differences from V1:
 * - NO modification to MySQL source tables (no synced_at column added)
 * - NO extra columns added to PostgreSQL target tables
 * - Uses sync_tracking table to track which records have been synced
 * - Clean separation of sync metadata from actual data
 * 
 * ⚠️ COMPLETELY READ-ONLY ON MYSQL: Only SELECT operations on MySQL
 * 
 * Requirements: 3.2, 3.3, 4.1, 4.2, 4.3, 4.4, 4.5
 */
class GenericSyncServiceV2 extends GenericSyncService
{
    public function __construct(
        SchemaDetectorService $schemaDetector,
        ColumnMapperService $columnMapper,
        ?SyncLockService $lockService = null,
        ?RetryService $retryService = null,
        ?ErrorThresholdService $errorThresholdService = null
    ) {
        parent::__construct($schemaDetector, $columnMapper, $lockService, $retryService, $errorThresholdService);
    }

    /**
     * Sync a table based on its configuration ID.
     * 
     * @param int|string $configId Configuration ID or source table name
     * @return GenericSyncResult
     */
    public function syncTable($configId): GenericSyncResult
    {
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

        if ($this->errorThresholdService->isSyncPaused($config->id)) {
            return new GenericSyncResult(
                success: false,
                recordsSynced: 0,
                recordsFailed: 0,
                errorMessage: "Sync is paused for table: {$config->source_table} due to consecutive failures"
            );
        }

        if (!$this->lockService->acquireLock($config->id, $this->lockTimeout)) {
            return new GenericSyncResult(
                success: false,
                recordsSynced: 0,
                recordsFailed: 0,
                errorMessage: "Sync already running for table: {$config->source_table}"
            );
        }

        $syncLog = TableSyncLog::startSync($config->id, $config->source_table);
        $config->updateSyncStatus(TableSyncConfiguration::STATUS_RUNNING);

        try {
            if (!$this->schemaDetector->tableExists($config->source_table, 'mysql')) {
                throw new Exception("Source table '{$config->source_table}' does not exist in MySQL");
            }

            $this->ensureTargetTableV2($config);
            $result = $this->processBatchesV2($config, $syncLog);

            $config->updateSyncStatus(
                $result->success ? TableSyncConfiguration::STATUS_COMPLETED : TableSyncConfiguration::STATUS_FAILED,
                now()
            );

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
     * Get count of unsynced records using tracking table.
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

        // Get total count from MySQL
        $totalCount = DB::connection('mysql')
            ->table($config->source_table)
            ->count();

        // Get synced count from tracking table
        $syncedCount = SyncTracking::getSyncedCount($config->id, $config->source_table);

        return max(0, $totalCount - $syncedCount);
    }

    /**
     * Ensure target table exists in PostgreSQL (without extra sync columns).
     */
    protected function ensureTargetTableV2(TableSyncConfiguration $config): void
    {
        $targetTable = $config->getEffectiveTargetTable();

        if (Schema::connection('pgsql')->hasTable($targetTable)) {
            return;
        }

        $sourceSchema = $this->schemaDetector->getTableSchema($config->source_table, 'mysql');
        $mappings = $config->column_mappings ?? [];
        $excluded = $config->excluded_columns ?? [];

        // Filter out sync-related columns from source schema
        $sourceSchema = array_filter($sourceSchema, function($col) {
            return !in_array($col['name'], ['synced_at', 'sync_batch_id']);
        });

        $targetSchema = $this->columnMapper->generateTargetSchema($sourceSchema, $mappings, $excluded);
        $primaryKey = $config->primary_key_column ?? $this->schemaDetector->getPrimaryKey($config->source_table, 'mysql');

        if ($primaryKey && isset($mappings[$primaryKey])) {
            $primaryKey = $mappings[$primaryKey];
        }

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
     * Process batches of records for sync using tracking table.
     */
    protected function processBatchesV2(TableSyncConfiguration $config, TableSyncLog $syncLog): GenericSyncResult
    {
        $totalSynced = 0;
        $totalFailed = 0;
        $startId = null;
        $endId = null;
        $lastProcessedId = 0;
        $batchSize = $config->batch_size ?? 10000;
        $primaryKey = $config->primary_key_column ?? 'id';

        while (true) {
            $batch = $this->fetchUnsyncedBatchV2($config, $lastProcessedId, $batchSize);

            if ($batch->isEmpty()) {
                break;
            }

            $batchStartId = $batch->first()->{$primaryKey};
            $batchEndId = $batch->last()->{$primaryKey};

            if ($startId === null) {
                $startId = $batchStartId;
            }
            $endId = $batchEndId;

            try {
                $batchResult = $this->processSingleBatchV2($config, $batch, $syncLog->id);

                $totalSynced += $batchResult['synced'];
                $totalFailed += $batchResult['failed'];
                $lastProcessedId = $batchEndId;

                $this->checkpoint($config->id, $lastProcessedId);

            } catch (Exception $e) {
                $totalFailed += $batch->count();
                $lastProcessedId = $batchEndId;

                Log::warning('Batch failed, continuing with next batch', [
                    'config_id' => $config->id,
                    'batch_start_id' => $batchStartId,
                    'batch_end_id' => $batchEndId,
                    'error' => $e->getMessage(),
                ]);

                $this->addBatchToErrorQueueV2($config, $batch, $e->getMessage());
            }
        }

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
     * Fetch a batch of unsynced records using tracking table.
     * 
     * Uses an efficient cursor-based approach to avoid loading all synced IDs into memory.
     * This prevents the "too many placeholders" error when there are many synced records.
     * 
     * ⚠️ COMPLETELY READ-ONLY ON MYSQL: Only SELECT operations
     */
    protected function fetchUnsyncedBatchV2(TableSyncConfiguration $config, int $afterId, int $limit): Collection
    {
        $primaryKey = $config->primary_key_column ?? 'id';

        // Get the max synced ID - cast to integer for proper numeric comparison
        $maxSyncedId = SyncTracking::where('configuration_id', $config->id)
            ->where('source_table', $config->source_table)
            ->selectRaw('MAX(CAST(record_id AS BIGINT)) as max_id')
            ->value('max_id') ?? 0;

        // Get the count of synced records
        $syncedCount = SyncTracking::where('configuration_id', $config->id)
            ->where('source_table', $config->source_table)
            ->count();

        // Get total source count up to maxSyncedId to check for gaps
        $sourceCountUpToMax = 0;
        if ($maxSyncedId > 0) {
            $sourceCountUpToMax = DB::connection('mysql')
                ->table($config->source_table)
                ->where($primaryKey, '<=', $maxSyncedId)
                ->count();
        }

        // If synced count matches source count up to max ID, no gaps - use simple cursor
        if ($syncedCount >= $sourceCountUpToMax) {
            // No gaps - fetch records after the max synced ID
            $startId = max($afterId, (int) $maxSyncedId);
            
            return DB::connection('mysql')
                ->table($config->source_table)
                ->where($primaryKey, '>', $startId)
                ->orderBy($primaryKey, 'asc')
                ->limit($limit)
                ->get();
        }

        // There might be gaps - use chunked approach to find unsynced records
        return $this->fetchUnsyncedWithGaps($config, $afterId, $limit, $primaryKey);
    }

    /**
     * Fetch unsynced records when there are gaps in the synced data.
     * Uses chunked ID comparison to avoid loading all IDs into memory.
     */
    protected function fetchUnsyncedWithGaps(TableSyncConfiguration $config, int $afterId, int $limit, string $primaryKey): Collection
    {
        // Get min and max IDs from source
        $minId = DB::connection('mysql')->table($config->source_table)->min($primaryKey);
        $maxId = DB::connection('mysql')->table($config->source_table)->max($primaryKey);

        if ($minId === null) {
            return collect([]);
        }

        $unsyncedRecords = collect([]);
        $chunkSize = 10000; // Process IDs in chunks
        $currentId = max($minId, $afterId + 1);

        while ($currentId <= $maxId && $unsyncedRecords->count() < $limit) {
            $endId = min($currentId + $chunkSize - 1, $maxId);

            // Get synced IDs in this range only
            $syncedIdsInRange = SyncTracking::where('configuration_id', $config->id)
                ->where('source_table', $config->source_table)
                ->whereBetween('record_id', [$currentId, $endId])
                ->pluck('record_id')
                ->map(fn($id) => (int) $id)
                ->toArray();

            // Get source records in this range that aren't synced
            $query = DB::connection('mysql')
                ->table($config->source_table)
                ->whereBetween($primaryKey, [$currentId, $endId])
                ->orderBy($primaryKey, 'asc');

            if (!empty($syncedIdsInRange)) {
                $query->whereNotIn($primaryKey, $syncedIdsInRange);
            }

            $remaining = $limit - $unsyncedRecords->count();
            $records = $query->limit($remaining)->get();

            $unsyncedRecords = $unsyncedRecords->concat($records);

            $currentId = $endId + 1;
        }

        return $unsyncedRecords;
    }

    /**
     * Process a single batch - insert to PostgreSQL and track in sync_tracking.
     * 
     * ⚠️ NO MODIFICATION TO MYSQL: Only reads from MySQL
     */
    protected function processSingleBatchV2(TableSyncConfiguration $config, Collection $batch, int $syncLogId): array
    {
        $synced = 0;
        $failed = 0;
        $primaryKey = $config->primary_key_column ?? 'id';
        $targetTable = $config->getEffectiveTargetTable();
        $mappings = $config->column_mappings ?? [];
        $excluded = $config->excluded_columns ?? [];

        // Add sync-related columns to exclusions
        $excluded = array_merge($excluded, ['synced_at', 'sync_batch_id']);

        $sourceSchema = $this->schemaDetector->getTableSchema($config->source_table, 'mysql');
        $columnTypes = [];
        foreach ($sourceSchema as $col) {
            $columnTypes[$col['name']] = $col['data_type'];
        }

        $insertData = [];
        $recordIds = [];

        foreach ($batch as $record) {
            try {
                $recordArray = (array) $record;
                $recordIds[] = $recordArray[$primaryKey];

                $mappedRecord = $this->columnMapper->mapColumns($recordArray, $mappings, $excluded);

                foreach ($mappedRecord as $column => $value) {
                    $originalColumn = array_search($column, $mappings) ?: $column;
                    if (isset($columnTypes[$originalColumn])) {
                        $mappedRecord[$column] = $this->columnMapper->convertType(
                            $value,
                            $columnTypes[$originalColumn]
                        );
                    }
                }

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

        // Insert to PostgreSQL
        $this->bulkInsertToPostgresV2($targetTable, $insertData);

        // Track synced records in dedicated tracking table (NOT in MySQL)
        SyncTracking::bulkMarkSynced($config->id, $config->source_table, $recordIds, $syncLogId);

        $synced = count($insertData);

        return ['synced' => $synced, 'failed' => $failed];
    }

    /**
     * Bulk insert records to PostgreSQL with retry logic.
     */
    protected function bulkInsertToPostgresV2(string $tableName, array $records): void
    {
        $this->retryService->executeWithRetry(
            function () use ($tableName, $records) {
                DB::connection('pgsql')->transaction(function () use ($tableName, $records) {
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
     * Add failed batch records to error queue.
     */
    protected function addBatchToErrorQueueV2(TableSyncConfiguration $config, Collection $batch, string $errorMessage): void
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
}
