<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\PartitionRegistry;
use App\Models\SyncBatch;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DateGroupedSyncService
 * 
 * Handles synchronization of alerts from MySQL to date-partitioned PostgreSQL tables.
 * This service is responsible for:
 * - Fetching alerts from MySQL in batches (read-only)
 * - Grouping alerts by extracted date within batch
 * - Processing each date group separately
 * - Inserting alerts into appropriate partition tables
 * - Updating partition_registry record counts
 * - Wrapping each date group insert in transaction
 * - Rolling back on failure for that date group
 * - Continuing processing other date groups on failure
 * - Logging all sync operations
 * 
 * Requirements: 1.3, 4.1, 5.1, 5.2, 5.3, 5.4, 5.5, 8.2, 8.4
 */
class DateGroupedSyncService
{
    /**
     * DateExtractor service for date extraction
     */
    private DateExtractor $dateExtractor;
    
    /**
     * PartitionManager service for partition management
     */
    private PartitionManager $partitionManager;
    
    /**
     * TimestampValidator service for timestamp validation
     */
    private TimestampValidator $timestampValidator;
    
    /**
     * PartitionErrorQueueService for error handling
     */
    private ?PartitionErrorQueueService $errorQueueService = null;
    
    /**
     * PartitionFailureAlertService for alerting
     */
    private ?PartitionFailureAlertService $alertService = null;
    
    /**
     * Default batch size for sync operations
     */
    private int $batchSize;
    
    /**
     * PostgreSQL connection name
     */
    private string $connection = 'pgsql';
    
    /**
     * Create a new DateGroupedSyncService instance
     * 
     * @param DateExtractor|null $dateExtractor Optional DateExtractor instance
     * @param PartitionManager|null $partitionManager Optional PartitionManager instance
     * @param TimestampValidator|null $timestampValidator Optional TimestampValidator instance
     * @param int|null $batchSize Optional batch size override
     */
    public function __construct(
        ?DateExtractor $dateExtractor = null,
        ?PartitionManager $partitionManager = null,
        ?TimestampValidator $timestampValidator = null,
        ?int $batchSize = null
    ) {
        $this->dateExtractor = $dateExtractor ?? new DateExtractor();
        $this->partitionManager = $partitionManager ?? new PartitionManager($this->dateExtractor);
        $this->timestampValidator = $timestampValidator ?? new TimestampValidator();
        $this->batchSize = $batchSize ?? (int) config('pipeline.batch_size', 10000);
    }
    
    /**
     * Set the error queue service
     * 
     * @param PartitionErrorQueueService $service
     * @return self
     */
    public function setErrorQueueService(PartitionErrorQueueService $service): self
    {
        $this->errorQueueService = $service;
        return $this;
    }
    
    /**
     * Set the alert service
     * 
     * @param PartitionFailureAlertService $service
     * @return self
     */
    public function setAlertService(PartitionFailureAlertService $service): self
    {
        $this->alertService = $service;
        return $this;
    }
    
    /**
     * Get the error queue service (lazy initialization)
     * 
     * @return PartitionErrorQueueService
     */
    private function getErrorQueueService(): PartitionErrorQueueService
    {
        if ($this->errorQueueService === null) {
            // Avoid circular dependency by not passing $this
            $this->errorQueueService = new PartitionErrorQueueService();
        }
        return $this->errorQueueService;
    }
    
    /**
     * Get the alert service (lazy initialization)
     * 
     * @return PartitionFailureAlertService
     */
    private function getAlertService(): PartitionFailureAlertService
    {
        if ($this->alertService === null) {
            $this->alertService = new PartitionFailureAlertService();
        }
        return $this->alertService;
    }
    
    /**
     * Get the configured batch size
     * 
     * @return int The batch size
     */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }
    
    /**
     * Set the batch size for sync operations
     * 
     * @param int $size The new batch size
     * @return self
     */
    public function setBatchSize(int $size): self
    {
        $this->batchSize = max(1, min($size, 50000)); // Clamp between 1 and 50000
        return $this;
    }
    
    /**
     * Fetch a batch of unsynced alerts from MySQL
     * 
     * CRITICAL: This method performs READ-ONLY operations on MySQL.
     * No DELETE, UPDATE, TRUNCATE, or DROP operations are allowed.
     * 
     * Requirements: 4.1, 5.1
     * 
     * @param int|null $startId Start from this ID (exclusive), null to start from beginning
     * @param int|null $batchSize Override default batch size
     * @return Collection Collection of Alert models
     */
    public function fetchUnsyncedBatch(?int $startId = null, ?int $batchSize = null): Collection
    {
        $size = $batchSize ?? $this->batchSize;
        
        $query = Alert::unsynced()
            ->orderBy('id', 'asc');
        
        if ($startId !== null && $startId > 0) {
            $query->where('id', '>', $startId);
        }
        
        // READ-ONLY: Only SELECT operations on MySQL alerts table
        return $query->limit($size)->get();
    }
    
    /**
     * Group alerts by extracted date
     * 
     * Extracts the date from each alert's receivedtime column and groups
     * alerts by that date. This allows processing each date group separately.
     * 
     * Requirements: 5.2
     * 
     * @param Collection $alerts Collection of Alert models
     * @return array Array of date groups: ['2026-01-08' => Collection, '2026-01-09' => Collection, ...]
     */
    public function groupAlertsByDate(Collection $alerts): array
    {
        $dateGroups = [];
        
        foreach ($alerts as $alert) {
            try {
                // Extract date from receivedtime
                $date = $this->dateExtractor->extractDate($alert->receivedtime);
                $dateKey = $date->toDateString(); // Format: YYYY-MM-DD
                
                // Initialize group if not exists
                if (!isset($dateGroups[$dateKey])) {
                    $dateGroups[$dateKey] = collect();
                }
                
                // Add alert to date group
                $dateGroups[$dateKey]->push($alert);
                
            } catch (Exception $e) {
                Log::error('Failed to extract date from alert', [
                    'alert_id' => $alert->id,
                    'receivedtime' => $alert->receivedtime,
                    'error' => $e->getMessage()
                ]);
                
                // Skip this alert - it will remain unsynced and can be retried later
                continue;
            }
        }
        
        return $dateGroups;
    }
    
    /**
     * Sync a batch of alerts to date-partitioned tables
     * 
     * This is the main entry point for syncing alerts. It:
     * 1. Fetches alerts from MySQL in batches (read-only)
     * 2. Groups alerts by extracted date within batch
     * 3. Processes each date group separately
     * 4. Inserts alerts into appropriate partition tables
     * 5. Updates partition_registry record counts
     * 
     * Requirements: 1.3, 4.1, 5.1, 5.2, 5.3, 5.4, 5.5
     * 
     * @param int|null $batchSize Optional batch size override
     * @param int|null $startId Optional start ID for resuming
     * @return DateGroupedSyncResult Result of the sync operation
     */
    public function syncBatch(?int $batchSize = null, ?int $startId = null): DateGroupedSyncResult
    {
        $startTime = microtime(true);
        
        // Fetch unsynced alerts from MySQL (READ-ONLY)
        $alerts = $this->fetchUnsyncedBatch($startId, $batchSize);
        
        if ($alerts->isEmpty()) {
            return new DateGroupedSyncResult(
                totalRecordsProcessed: 0,
                dateGroupResults: [],
                success: true,
                lastProcessedId: $startId ?? 0,
                errorMessage: null,
                duration: microtime(true) - $startTime
            );
        }
        
        // Group alerts by date
        $dateGroups = $this->groupAlertsByDate($alerts);
        
        // Process each date group separately
        $dateGroupResults = [];
        $totalRecordsProcessed = 0;
        $lastProcessedId = $startId ?? 0;
        $hasFailures = false;
        
        foreach ($dateGroups as $dateKey => $dateAlerts) {
            try {
                $date = Carbon::parse($dateKey);
                $result = $this->syncDateGroup($date, $dateAlerts);
                
                $dateGroupResults[] = $result;
                
                if ($result->success) {
                    $totalRecordsProcessed += $result->recordsInserted;
                    $lastProcessedId = max($lastProcessedId, $dateAlerts->max('id'));
                } else {
                    $hasFailures = true;
                }
                
            } catch (Exception $e) {
                Log::error('Failed to process date group', [
                    'date' => $dateKey,
                    'alert_count' => $dateAlerts->count(),
                    'error' => $e->getMessage()
                ]);
                
                // Create failed result for this date group
                $dateGroupResults[] = new DateGroupResult(
                    date: Carbon::parse($dateKey),
                    partitionTable: $this->dateExtractor->formatPartitionName(Carbon::parse($dateKey)),
                    recordsInserted: 0,
                    success: false,
                    errorMessage: $e->getMessage()
                );
                
                $hasFailures = true;
                
                // Continue processing other date groups (error isolation)
                continue;
            }
        }
        
        $duration = microtime(true) - $startTime;
        
        return new DateGroupedSyncResult(
            totalRecordsProcessed: $totalRecordsProcessed,
            dateGroupResults: $dateGroupResults,
            success: !$hasFailures,
            lastProcessedId: $lastProcessedId,
            errorMessage: $hasFailures ? 'One or more date groups failed to sync' : null,
            duration: $duration
        );
    }
    
    /**
     * Sync a single date group to its partition table
     * 
     * This method:
     * 1. Ensures the partition table exists (creates if needed with retry logic)
     * 2. Wraps the insert in a transaction
     * 3. Inserts all alerts for the date into the partition table
     * 4. Updates partition_registry record count
     * 5. Updates sync markers in MySQL
     * 6. Rolls back on failure
     * 7. Moves to error queue after max retries
     * 
     * Requirements: 5.3, 5.4, 5.5, 8.1, 8.2, 8.3, 8.4
     * 
     * @param Carbon $date The date for this group
     * @param Collection $alerts Collection of Alert models for this date
     * @return DateGroupResult Result of syncing this date group
     */
    public function syncDateGroup(Carbon $date, Collection $alerts): DateGroupResult
    {
        $partitionTable = $this->partitionManager->getPartitionTableName($date);
        $recordCount = $alerts->count();
        $syncBatchId = null;
        
        try {
            // Ensure partition table exists (creates if needed with retry logic)
            // This will throw an exception if creation fails after max retries
            $this->partitionManager->ensurePartitionExists($date);
            
            // Create sync batch record for tracking
            $syncBatch = SyncBatch::create([
                'start_id' => $alerts->min('id'),
                'end_id' => $alerts->max('id'),
                'records_count' => $recordCount,
                'status' => SyncBatch::STATUS_PENDING,
            ]);
            
            $syncBatchId = $syncBatch->id;
            $syncBatch->markProcessing();
            
            // Wrap insert in transaction for atomicity
            DB::connection($this->connection)->transaction(function () use ($alerts, $syncBatch, $partitionTable) {
                $this->insertAlertsToPartition($alerts, $syncBatch->id, $partitionTable);
            });
            
            // Update partition_registry record count
            $this->partitionManager->incrementRecordCount($partitionTable, $recordCount);
            
            // Update sync markers in MySQL (only after successful PostgreSQL insert)
            $this->updateSyncMarkers($alerts, $syncBatch->id);
            
            // Mark batch as completed
            $syncBatch->markCompleted();
            
            Log::info('Date group synced successfully', [
                'date' => $date->toDateString(),
                'partition_table' => $partitionTable,
                'records_inserted' => $recordCount,
                'batch_id' => $syncBatch->id
            ]);
            
            return new DateGroupResult(
                date: $date,
                partitionTable: $partitionTable,
                recordsInserted: $recordCount,
                success: true,
                errorMessage: null
            );
            
        } catch (Exception $e) {
            Log::error('Failed to sync date group', [
                'date' => $date->toDateString(),
                'partition_table' => $partitionTable,
                'alert_count' => $recordCount,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode()
            ]);
            
            // Transaction will automatically rollback on exception
            // No partial data will be left in the partition table
            
            // Move failed records to error queue for retry
            $this->moveToErrorQueue($alerts, $date, $partitionTable, $e, $syncBatchId);
            
            return new DateGroupResult(
                date: $date,
                partitionTable: $partitionTable,
                recordsInserted: 0,
                success: false,
                errorMessage: $e->getMessage()
            );
        }
    }
    
    /**
     * Move failed alerts to the error queue
     * 
     * Adds all alerts in a failed date group to the error queue for later retry.
     * Determines the error type based on the exception message.
     * Checks and sends alerts if failure threshold is exceeded.
     * 
     * Requirements: 8.3, 8.5
     * 
     * @param Collection $alerts Collection of Alert models
     * @param Carbon $date The partition date
     * @param string $partitionTable The partition table name
     * @param Exception $exception The exception that caused the failure
     * @param int|null $syncBatchId Optional sync batch ID
     * @return void
     */
    private function moveToErrorQueue(
        Collection $alerts,
        Carbon $date,
        string $partitionTable,
        Exception $exception,
        ?int $syncBatchId = null
    ): void {
        try {
            // Determine error type from exception message
            $errorType = $this->determineErrorType($exception);
            
            $errorQueueService = $this->getErrorQueueService();
            $errors = $errorQueueService->addBatchToErrorQueue(
                alerts: $alerts,
                partitionDate: $date,
                partitionTable: $partitionTable,
                errorType: $errorType,
                errorMessage: $exception->getMessage(),
                syncBatchId: $syncBatchId,
                exception: $exception
            );
            
            Log::info('Moved failed alerts to error queue', [
                'alert_count' => $alerts->count(),
                'errors_created' => $errors->count(),
                'partition_date' => $date->toDateString(),
                'partition_table' => $partitionTable,
                'error_type' => $errorType
            ]);
            
            // Check if failure threshold exceeded and send alert if needed
            $alertService = $this->getAlertService();
            $alertSent = $alertService->checkAndAlert();
            
            if ($alertSent) {
                Log::warning('Partition failure alert sent', [
                    'partition_table' => $partitionTable,
                    'error_type' => $errorType
                ]);
            }
            
        } catch (Exception $e) {
            Log::error('Failed to move alerts to error queue', [
                'alert_count' => $alerts->count(),
                'partition_date' => $date->toDateString(),
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Determine error type from exception
     * 
     * @param Exception $exception
     * @return string Error type constant
     */
    private function determineErrorType(Exception $exception): string
    {
        $message = strtolower($exception->getMessage());
        
        if (str_contains($message, 'partition') && str_contains($message, 'create')) {
            return \App\Models\PartitionSyncError::ERROR_PARTITION_CREATION;
        } elseif (str_contains($message, 'transaction')) {
            return \App\Models\PartitionSyncError::ERROR_TRANSACTION_FAILED;
        } elseif (str_contains($message, 'insert')) {
            return \App\Models\PartitionSyncError::ERROR_INSERT_FAILED;
        } elseif (str_contains($message, 'validation')) {
            return \App\Models\PartitionSyncError::ERROR_VALIDATION_FAILED;
        } else {
            return \App\Models\PartitionSyncError::ERROR_UNKNOWN;
        }
    }
    
    /**
     * Insert or update alerts into a partition table
     * 
     * Performs bulk upsert of alerts into the specified partition table.
     * Uses UPSERT (INSERT ... ON CONFLICT UPDATE) to handle duplicate keys gracefully.
     * If a record with the same ID already exists, it will be updated instead of causing an error.
     * This method should be called within a transaction.
     * 
     * Requirements: 5.3, 5.4
     * 
     * @param Collection $alerts Collection of Alert models
     * @param int $batchId The sync batch ID
     * @param string $partitionTable The partition table name
     * @return void
     * @throws Exception If upsert fails
     */
    private function insertAlertsToPartition(Collection $alerts, int $batchId, string $partitionTable): void
    {
        $now = now();
        
        // Get existing record IDs to determine which are updates vs inserts
        $alertIds = $alerts->pluck('id')->toArray();
        $existingRecords = DB::connection($this->connection)
            ->table($partitionTable)
            ->whereIn('id', $alertIds)
            ->get()
            ->keyBy('id');
        
        // Prepare data for bulk insert - mirror MySQL alerts structure
        $insertData = $alerts->map(function ($alert) use ($batchId, $now, $existingRecords, $partitionTable) {
            $isExisting = $existingRecords->has($alert->id);
            
            $preparedData = [
                'id' => $alert->id,
                'panelid' => $alert->panelid,
                'seqno' => $alert->seqno,
                'zone' => $alert->zone,
                'alarm' => $alert->alarm,
                'comment' => $alert->comment,
                'status' => $alert->status,
                'sendtoclient' => $alert->sendtoclient,
                'closedBy' => $alert->closedBy,
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
            
            // CRITICAL: Preserve original timestamps if record exists
            if ($isExisting) {
                $existing = $existingRecords->get($alert->id);
                $preparedData['createtime'] = $existing->createtime;
                $preparedData['receivedtime'] = $existing->receivedtime;
                $preparedData['closedtime'] = $existing->closedtime;
                
                Log::debug('Preserving original timestamps for existing record', [
                    'alert_id' => $alert->id,
                    'partition_table' => $partitionTable,
                ]);
            } else {
                // New record - use timestamps from MySQL
                $preparedData['createtime'] = $alert->createtime;
                $preparedData['receivedtime'] = $alert->receivedtime;
                $preparedData['closedtime'] = $alert->closedtime;
                
                // TIMESTAMP VALIDATION: Verify timestamps match before insert (new records only)
                $sourceData = $alert->toArray();
                $validation = $this->timestampValidator->validateBeforeSync($sourceData, $preparedData, $alert->id);
                
                if (!$validation['valid']) {
                    Log::error('Timestamp validation failed during batch insert', [
                        'alert_id' => $alert->id,
                        'errors' => $validation['errors'],
                        'partition_table' => $partitionTable,
                    ]);
                    throw new Exception("Timestamp validation failed for alert {$alert->id}: " . implode('; ', $validation['errors']));
                }
            }
            
            return $preparedData;
        })->toArray();
        
        // Use chunked upsert for very large date groups to prevent memory issues
        // UPSERT: Insert new records or update existing ones (prevents duplicate key errors)
        // CRITICAL: Timestamps (createtime, receivedtime, closedtime) are NOT in update list
        $chunks = array_chunk($insertData, 1000);
        foreach ($chunks as $chunk) {
            DB::connection($this->connection)->table($partitionTable)->upsert(
                $chunk,
                ['id'], // Unique key to check for conflicts
                [ // Columns to update if record exists (excludes createtime, receivedtime, closedtime)
                    'panelid',
                    'seqno',
                    'zone',
                    'alarm',
                    'comment',
                    'status',
                    'sendtoclient',
                    'closedBy',
                    'sendip',
                    'alerttype',
                    'location',
                    'priority',
                    'AlertUserStatus',
                    'level',
                    'sip2',
                    'c_status',
                    'auto_alert',
                    'critical_alerts',
                    'Readstatus',
                    'synced_at',
                    'sync_batch_id',
                    // NOTE: createtime, receivedtime, closedtime are NOT updated
                    // They are preserved from the original insert
                ]
            );
        }
    }
    
    /**
     * Update sync markers in MySQL for successfully synced alerts
     * 
     * CRITICAL: This method performs UPDATE operations on MySQL alerts table only.
     * Only updates synced_at and sync_batch_id columns.
     * 
     * Requirements: 4.1, 5.5
     * 
     * @param Collection $alerts Collection of Alert models
     * @param int $batchId The sync batch ID
     * @return void
     */
    private function updateSyncMarkers(Collection $alerts, int $batchId): void
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
     * Get count of unsynced records
     * 
     * @return int Number of unsynced alerts
     */
    public function getUnsyncedCount(): int
    {
        return Alert::unsynced()->count();
    }
    
    /**
     * Check if there are records to sync
     * 
     * @return bool True if there are unsynced alerts
     */
    public function hasRecordsToSync(): bool
    {
        return Alert::unsynced()->exists();
    }
}
