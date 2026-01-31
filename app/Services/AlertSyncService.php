<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AlertUpdateLog;
use App\Models\SyncedAlert;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AlertSyncService
 * 
 * Handles synchronization of individual alerts from MySQL to PostgreSQL partitioned tables.
 * This service is responsible for:
 * - Fetching alert data from MySQL (READ-ONLY)
 * - Determining correct partition table based on receivedtime
 * - Ensuring partition table exists (creates if needed)
 * - Updating/inserting alert data in PostgreSQL partition tables
 * - Updating partition_registry record counts
 * - Marking log entries as processed in MySQL alert_pg_update_log
 * 
 * CRITICAL CONSTRAINTS:
 * - MySQL alerts table: READ-ONLY (only SELECT operations)
 * - MySQL alert_pg_update_log: UPDATE only (to mark processed)
 * - PostgreSQL partition tables: INSERT/UPDATE operations (target)
 */
class AlertSyncService
{
    private SyncLogger $logger;
    private PartitionManager $partitionManager;
    private DateExtractor $dateExtractor;
    private int $maxRetries;
    private string $connection = 'pgsql';

    /**
     * Create a new AlertSyncService instance
     * 
     * @param SyncLogger $logger Logger service for structured logging
     * @param PartitionManager|null $partitionManager Partition manager service
     * @param DateExtractor|null $dateExtractor Date extractor service
     * @param int $maxRetries Maximum number of retry attempts for failed operations
     */
    public function __construct(
        SyncLogger $logger, 
        ?PartitionManager $partitionManager = null,
        ?DateExtractor $dateExtractor = null,
        int $maxRetries = 3
    ) {
        $this->logger = $logger;
        $this->partitionManager = $partitionManager ?? new PartitionManager();
        $this->dateExtractor = $dateExtractor ?? new DateExtractor();
        $this->maxRetries = $maxRetries;
    }

    /**
     * Set the maximum number of retry attempts
     * 
     * @param int $maxRetries Maximum number of retry attempts
     * @return void
     */
    public function setMaxRetries(int $maxRetries): void
    {
        $this->maxRetries = $maxRetries;
    }

    /**
     * Get the maximum number of retry attempts
     * 
     * @return int Maximum number of retry attempts
     */
    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * Synchronize a single alert from MySQL to PostgreSQL
     * 
     * This is the main entry point for syncing an alert. It:
     * 1. Fetches the alert data from MySQL (read-only)
     * 2. Updates or inserts the alert in PostgreSQL
     * 3. Marks the log entry as processed in MySQL alert_pg_update_log
     * 
     * The method handles all errors gracefully and returns a SyncResult
     * indicating success or failure. Errors are logged but not thrown.
     * 
     * @param int $logEntryId The ID of the log entry being processed
     * @param int $alertId The ID of the alert to sync
     * @return SyncResult Result of the sync operation (never throws)
     */
    public function syncAlert(int $logEntryId, int $alertId): SyncResult
    {
        $startTime = microtime(true);
        
        try {
            // Step 1: Fetch alert data from MySQL (READ-ONLY operation)
            $alertData = $this->fetchAlertFromMysql($alertId);
            
            if ($alertData === null) {
                // Alert not found in MySQL - mark as failed
                $errorMessage = "Alert ID {$alertId} not found in MySQL alerts table";
                $this->markLogEntryProcessed($logEntryId, false, $errorMessage);
                
                $duration = microtime(true) - $startTime;
                $this->logger->logAlertSync($alertId, false, $duration, $errorMessage);
                
                return new SyncResult(
                    success: false,
                    alertId: $alertId,
                    errorMessage: $errorMessage,
                    duration: $duration
                );
            }
            
            // Step 2: Update or insert alert in PostgreSQL (target database)
            $updateSuccess = $this->updateAlertInPostgres($alertId, $alertData);
            
            if (!$updateSuccess) {
                $errorMessage = "Failed to update alert ID {$alertId} in PostgreSQL";
                $this->markLogEntryProcessed($logEntryId, false, $errorMessage);
                
                $duration = microtime(true) - $startTime;
                $this->logger->logAlertSync($alertId, false, $duration, $errorMessage);
                
                return new SyncResult(
                    success: false,
                    alertId: $alertId,
                    errorMessage: $errorMessage,
                    duration: $duration
                );
            }
            
            // Step 3: Mark log entry as successfully processed
            $this->markLogEntryProcessed($logEntryId, true);
            
            $duration = microtime(true) - $startTime;
            $this->logger->logAlertSync($alertId, true, $duration);
            
            return new SyncResult(
                success: true,
                alertId: $alertId,
                errorMessage: null,
                duration: $duration
            );
            
        } catch (Exception $e) {
            // Handle unexpected errors
            $errorMessage = "Unexpected error syncing alert ID {$alertId}: " . $e->getMessage();
            
            try {
                $this->markLogEntryProcessed($logEntryId, false, $errorMessage);
            } catch (Exception $markException) {
                // Log but don't throw - we want to return the original error
                $this->logger->logError(
                    'Failed to mark log entry as failed',
                    $markException,
                    ['log_entry_id' => $logEntryId, 'alert_id' => $alertId]
                );
            }
            
            $duration = microtime(true) - $startTime;
            $this->logger->logAlertSync($alertId, false, $duration, $errorMessage);
            $this->logger->logError('Alert sync failed', $e, ['alert_id' => $alertId, 'log_entry_id' => $logEntryId]);
            
            return new SyncResult(
                success: false,
                alertId: $alertId,
                errorMessage: $errorMessage,
                duration: $duration
            );
        }
    }

    /**
     * Retry an operation with exponential backoff
     * 
     * Implements retry logic with exponential backoff for handling transient failures:
     * - Delay calculation: base_delay * (multiplier ^ attempt)
     * - Maximum delay is capped at 60 seconds
     * - Retries on connection errors and query failures
     * 
     * @param callable $operation The operation to retry
     * @param string $context Context description for logging
     * @return mixed The result of the operation
     * @throws Exception If all retry attempts fail
     */
    private function retryWithBackoff(callable $operation, string $context): mixed
    {
        $baseDelay = 1000; // milliseconds
        $maxDelay = 60000; // milliseconds (60 seconds)
        $multiplier = 2;
        
        $lastException = null;
        
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            try {
                return $operation();
            } catch (Exception $e) {
                $lastException = $e;
                
                // Check if this is a retryable error
                $isRetryable = $this->isRetryableError($e);
                
                if (!$isRetryable || $attempt >= $this->maxRetries) {
                    // Not retryable or max retries reached
                    $this->logger->logError(
                        "Operation failed after {$attempt} attempts: {$context}",
                        $e,
                        ['attempt' => $attempt, 'context' => $context]
                    );
                    throw $e;
                }
                
                // Calculate delay with exponential backoff
                $delay = min($baseDelay * pow($multiplier, $attempt), $maxDelay);
                
                $this->logger->logWarning(
                    "Retrying operation after failure: {$context}",
                    [
                        'attempt' => $attempt + 1,
                        'max_retries' => $this->maxRetries,
                        'delay_ms' => $delay,
                        'error' => $e->getMessage()
                    ]
                );
                
                // Sleep for the calculated delay (convert to microseconds)
                usleep($delay * 1000);
            }
        }
        
        // This should never be reached, but just in case
        throw $lastException ?? new Exception("Operation failed: {$context}");
    }

    /**
     * Determine if an error is retryable
     * 
     * Retryable errors include:
     * - Connection errors
     * - Timeout errors
     * - Deadlock errors
     * - Temporary database unavailability
     * 
     * @param Exception $e The exception to check
     * @return bool True if the error is retryable
     */
    private function isRetryableError(Exception $e): bool
    {
        $message = strtolower($e->getMessage());
        
        // Check for common retryable error patterns
        $retryablePatterns = [
            'connection',
            'timeout',
            'deadlock',
            'lock wait timeout',
            'too many connections',
            'server has gone away',
            'lost connection',
            'broken pipe',
        ];
        
        foreach ($retryablePatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Fetch alert data from MySQL alerts table
     * 
     * CRITICAL: This method performs READ-ONLY operations on MySQL.
     * No DELETE, TRUNCATE, or UPDATE operations are allowed.
     * 
     * Uses retry logic with exponential backoff for transient failures.
     * 
     * @param int $alertId The ID of the alert to fetch
     * @return array|null Alert data as associative array, or null if not found
     * @throws Exception If database query fails after all retry attempts
     */
    private function fetchAlertFromMysql(int $alertId): ?array
    {
        return $this->retryWithBackoff(
            function () use ($alertId) {
                // Use Alert model to fetch from MySQL (READ-ONLY)
                $alert = Alert::find($alertId);
                
                if ($alert === null) {
                    return null;
                }
                
                // Convert model to array for processing
                return $alert->toArray();
            },
            "Fetch alert {$alertId} from MySQL"
        );
    }

    /**
     * Update or insert alert in PostgreSQL partition table
     * 
     * This method performs an upsert operation to the correct partition table:
     * 1. Extracts date from receivedtime to determine partition
     * 2. Gets partition table name (e.g., "alerts_2026_01_08")
     * 3. Ensures partition table exists (creates if needed)
     * 4. Performs UPSERT to partition table
     * 5. Updates partition_registry record count
     * 
     * CRITICAL: This writes to date-partitioned tables, NOT a single alerts table.
     * 
     * Uses retry logic with exponential backoff for transient failures.
     * Wraps operation in a transaction for atomicity.
     * 
     * @param int $alertId The ID of the alert to update
     * @param array $data Alert data from MySQL
     * @return bool True if update succeeded, false otherwise (errors are logged)
     */
    private function updateAlertInPostgres(int $alertId, array $data): bool
    {
        try {
            return $this->retryWithBackoff(
                function () use ($alertId, $data) {
                    // Step 1: Extract date from receivedtime to determine partition
                    if (!isset($data['receivedtime'])) {
                        throw new Exception("Alert {$alertId} missing receivedtime - cannot determine partition");
                    }
                    
                    $date = $this->dateExtractor->extractDate($data['receivedtime']);
                    
                    // Step 2: Get partition table name (e.g., "alerts_2026_01_08")
                    $partitionTable = $this->partitionManager->getPartitionTableName($date);
                    
                    $this->logger->logInfo('Syncing alert to partition', [
                        'alert_id' => $alertId,
                        'receivedtime' => $data['receivedtime'],
                        'partition_date' => $date->toDateString(),
                        'partition_table' => $partitionTable
                    ]);
                    
                    // Step 3: Ensure partition table exists (creates if needed with retry logic)
                    $this->partitionManager->ensurePartitionExists($date);
                    
                    // Step 4: Start a transaction for atomicity
                    DB::connection($this->connection)->beginTransaction();
                    
                    try {
                        // Prepare data for upsert
                        $now = now();
                        $upsertData = [
                            'id' => $alertId,
                            'panelid' => $data['panelid'] ?? null,
                            'seqno' => $data['seqno'] ?? null,
                            'zone' => $data['zone'] ?? null,
                            'alarm' => $data['alarm'] ?? null,
                            'createtime' => $data['createtime'] ?? null,
                            'receivedtime' => $data['receivedtime'] ?? null,
                            'comment' => $data['comment'] ?? null,
                            'status' => $data['status'] ?? null,
                            'sendtoclient' => $data['sendtoclient'] ?? null,
                            'closedBy' => $data['closedBy'] ?? null,
                            'closedtime' => $data['closedtime'] ?? null,
                            'sendip' => $data['sendip'] ?? null,
                            'alerttype' => $data['alerttype'] ?? null,
                            'location' => $data['location'] ?? null,
                            'priority' => $data['priority'] ?? null,
                            'AlertUserStatus' => $data['AlertUserStatus'] ?? null,
                            'level' => $data['level'] ?? null,
                            'sip2' => $data['sip2'] ?? null,
                            'c_status' => $data['c_status'] ?? null,
                            'auto_alert' => $data['auto_alert'] ?? null,
                            'critical_alerts' => $data['critical_alerts'] ?? null,
                            'Readstatus' => $data['Readstatus'] ?? null,
                            'synced_at' => $now,
                            'sync_batch_id' => $data['sync_batch_id'] ?? 0,
                        ];
                        
                        // Perform UPSERT to partition table
                        // If record exists (by id), update it; otherwise insert
                        DB::connection($this->connection)->table($partitionTable)->upsert(
                            [$upsertData],
                            ['id'], // Unique key to check for conflicts
                            [ // Columns to update if record exists
                                'panelid',
                                'seqno',
                                'zone',
                                'alarm',
                                'createtime',
                                'receivedtime',
                                'comment',
                                'status',
                                'sendtoclient',
                                'closedBy',
                                'closedtime',
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
                            ]
                        );
                        
                        // Step 5: Update partition_registry record count
                        // Note: This increments even for updates, which is acceptable
                        // as it tracks total operations. For exact counts, use partition manager's
                        // getPartitionRecordCount() method which queries the actual table.
                        $this->partitionManager->incrementRecordCount($partitionTable, 1);
                        
                        DB::connection($this->connection)->commit();
                        
                        $this->logger->logInfo('Alert synced to partition successfully', [
                            'alert_id' => $alertId,
                            'partition_table' => $partitionTable
                        ]);
                        
                        return true;
                        
                    } catch (Exception $e) {
                        DB::connection($this->connection)->rollBack();
                        throw $e;
                    }
                },
                "Update alert {$alertId} in PostgreSQL partition"
            );
            
        } catch (Exception $e) {
            $this->logger->logError(
                'Failed to update alert in PostgreSQL partition after retries',
                $e,
                ['alert_id' => $alertId]
            );
            return false;
        }
    }

    /**
     * Mark log entry as processed in MySQL alert_pg_update_log table
     * 
     * Updates the log entry with:
     * - Status: 2 (completed) or 3 (failed)
     * - updated_at: current timestamp
     * - error_message: error details if failed
     * - retry_count: incremented on failure
     * 
     * CRITICAL: This method performs UPDATE operations on MySQL alert_pg_update_log only.
     * No operations are performed on the MySQL alerts table.
     * 
     * Uses retry logic with exponential backoff for transient failures.
     * 
     * @param int $logEntryId The ID of the log entry to update
     * @param bool $success Whether the sync was successful
     * @param string|null $error Error message if sync failed
     * @return void
     * @throws Exception If update fails after all retry attempts
     */
    private function markLogEntryProcessed(int $logEntryId, bool $success, ?string $error = null): void
    {
        $this->retryWithBackoff(
            function () use ($logEntryId, $success, $error) {
                $logEntry = AlertUpdateLog::find($logEntryId);
                
                if ($logEntry === null) {
                    throw new Exception("Log entry ID {$logEntryId} not found");
                }
                
                // Update status: 2 = completed, 3 = failed
                $logEntry->status = $success ? 2 : 3;
                $logEntry->updated_at = now();
                
                if (!$success) {
                    $logEntry->error_message = $error;
                    $logEntry->retry_count = ($logEntry->retry_count ?? 0) + 1;
                }
                
                $logEntry->save();
            },
            "Mark log entry {$logEntryId} as processed"
        );
    }
}

