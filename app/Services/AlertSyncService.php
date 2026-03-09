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
    private TimestampValidator $timestampValidator;
    private int $maxRetries;
    private string $connection = 'pgsql';

    /**
     * Create a new AlertSyncService instance
     * 
     * @param SyncLogger $logger Logger service for structured logging
     * @param PartitionManager|null $partitionManager Partition manager service
     * @param DateExtractor|null $dateExtractor Date extractor service
     * @param TimestampValidator|null $timestampValidator Timestamp validator service
     * @param int $maxRetries Maximum number of retry attempts for failed operations
     */
    public function __construct(
        SyncLogger $logger, 
        ?PartitionManager $partitionManager = null,
        ?DateExtractor $dateExtractor = null,
        ?TimestampValidator $timestampValidator = null,
        int $maxRetries = 3
    ) {
        $this->logger = $logger;
        $this->partitionManager = $partitionManager ?? new PartitionManager();
        $this->dateExtractor = $dateExtractor ?? new DateExtractor();
        $this->timestampValidator = $timestampValidator ?? new TimestampValidator();
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
                // CRITICAL: Fetch raw data directly from database to avoid timezone conversion
                // Using DB::table() instead of Alert model to bypass datetime casting
                $alert = DB::connection('mysql')
                    ->table('alerts')
                    ->where('id', $alertId)
                    ->first();
                
                if ($alert === null) {
                    return null;
                }
                
                // Convert stdClass to array with raw timestamp strings (no conversion)
                return (array) $alert;
            },
            "Fetch alert {$alertId} from MySQL"
        );
    }

    /**
     * Update or insert alert in PostgreSQL partition table
     * 
     * SIMPLIFIED APPROACH:
     * 1. Always fetch fresh data from MySQL at upsert time
     * 2. Use ALL values from MySQL as-is (including datetime columns)
     * 3. Validate after upsert to confirm values match
     * 
     * @param int $alertId The ID of the alert to update
     * @param array $data Alert data from MySQL (not used, will fetch fresh)
     * @return bool True if update succeeded, false otherwise (errors are logged)
     */
    private function updateAlertInPostgres(int $alertId, array $data): bool
    {
        try {
            return $this->retryWithBackoff(
                function () use ($alertId) {
                    // STEP 1: Fetch FRESH data from MySQL (raw, no conversion)
                    $mysqlData = DB::connection('mysql')
                        ->table('alerts')
                        ->where('id', $alertId)
                        ->first();
                    
                    if (!$mysqlData) {
                        throw new Exception("Alert {$alertId} not found in MySQL");
                    }
                    
                    // Convert to array
                    $mysqlData = (array) $mysqlData;
                    
                    // STEP 2: Determine partition table
                    if (!isset($mysqlData['receivedtime'])) {
                        throw new Exception("Alert {$alertId} missing receivedtime - cannot determine partition");
                    }
                    
                    $date = $this->dateExtractor->extractDate($mysqlData['receivedtime']);
                    $partitionTable = $this->partitionManager->getPartitionTableName($date);
                    
                    $this->logger->logInfo('Syncing alert to partition', [
                        'alert_id' => $alertId,
                        'receivedtime' => $mysqlData['receivedtime'],
                        'partition_table' => $partitionTable
                    ]);
                    
                    // STEP 3: Ensure partition exists
                    $this->partitionManager->ensurePartitionExists($date);
                    
                    // STEP 4: Prepare upsert data - USE ALL VALUES FROM MYSQL AS-IS
                    $now = now();
                    $upsertData = [
                        'id' => $mysqlData['id'],
                        'panelid' => $mysqlData['panelid'] ?? null,
                        'seqno' => $mysqlData['seqno'] ?? null,
                        'zone' => $mysqlData['zone'] ?? null,
                        'alarm' => $mysqlData['alarm'] ?? null,
                        'createtime' => $mysqlData['createtime'] ?? null,      // From MySQL as-is
                        'receivedtime' => $mysqlData['receivedtime'] ?? null,  // From MySQL as-is
                        'closedtime' => $mysqlData['closedtime'] ?? null,      // From MySQL as-is
                        'comment' => $mysqlData['comment'] ?? null,
                        'status' => $mysqlData['status'] ?? null,
                        'sendtoclient' => $mysqlData['sendtoclient'] ?? null,
                        'closedBy' => $mysqlData['closedBy'] ?? null,
                        'sendip' => $mysqlData['sendip'] ?? null,
                        'alerttype' => $mysqlData['alerttype'] ?? null,
                        'location' => $mysqlData['location'] ?? null,
                        'priority' => $mysqlData['priority'] ?? null,
                        'AlertUserStatus' => $mysqlData['AlertUserStatus'] ?? null,
                        'level' => $mysqlData['level'] ?? null,
                        'sip2' => $mysqlData['sip2'] ?? null,
                        'c_status' => $mysqlData['c_status'] ?? null,
                        'auto_alert' => $mysqlData['auto_alert'] ?? null,
                        'critical_alerts' => $mysqlData['critical_alerts'] ?? null,
                        'Readstatus' => $mysqlData['Readstatus'] ?? null,
                        'synced_at' => $now,
                        'sync_batch_id' => $mysqlData['sync_batch_id'] ?? 0,
                    ];
                    
                    // STEP 5: Perform UPSERT with explicit timestamp casting to prevent timezone conversion
                    DB::connection($this->connection)->beginTransaction();
                    
                    try {
                        // Using raw SQL to ensure timestamps are preserved exactly as-is
                        $sql = "
                            INSERT INTO {$partitionTable} (
                                id, panelid, seqno, zone, alarm,
                                createtime, receivedtime, closedtime,
                                comment, status, sendtoclient, \"closedBy\", sendip,
                                alerttype, location, priority, \"AlertUserStatus\",
                                level, sip2, c_status, auto_alert, critical_alerts,
                                \"Readstatus\", synced_at, sync_batch_id
                            ) VALUES (
                                ?, ?, ?, ?, ?,
                                ?::timestamp, ?::timestamp, " . ($upsertData['closedtime'] ? "?::timestamp" : "NULL") . ",
                                ?, ?, ?, ?, ?,
                                ?, ?, ?, ?,
                                ?, ?, ?, ?, ?,
                                ?, NOW(), ?
                            )
                            ON CONFLICT (id) DO UPDATE SET
                                panelid = EXCLUDED.panelid,
                                seqno = EXCLUDED.seqno,
                                zone = EXCLUDED.zone,
                                alarm = EXCLUDED.alarm,
                                createtime = EXCLUDED.createtime,
                                receivedtime = EXCLUDED.receivedtime,
                                closedtime = EXCLUDED.closedtime,
                                comment = EXCLUDED.comment,
                                status = EXCLUDED.status,
                                sendtoclient = EXCLUDED.sendtoclient,
                                \"closedBy\" = EXCLUDED.\"closedBy\",
                                sendip = EXCLUDED.sendip,
                                alerttype = EXCLUDED.alerttype,
                                location = EXCLUDED.location,
                                priority = EXCLUDED.priority,
                                \"AlertUserStatus\" = EXCLUDED.\"AlertUserStatus\",
                                level = EXCLUDED.level,
                                sip2 = EXCLUDED.sip2,
                                c_status = EXCLUDED.c_status,
                                auto_alert = EXCLUDED.auto_alert,
                                critical_alerts = EXCLUDED.critical_alerts,
                                \"Readstatus\" = EXCLUDED.\"Readstatus\",
                                synced_at = NOW(),
                                sync_batch_id = EXCLUDED.sync_batch_id
                        ";
                        
                        $bindings = [
                            $upsertData['id'],
                            $upsertData['panelid'],
                            $upsertData['seqno'],
                            $upsertData['zone'],
                            $upsertData['alarm'],
                            $upsertData['createtime'],
                            $upsertData['receivedtime']
                        ];
                        
                        if ($upsertData['closedtime']) {
                            $bindings[] = $upsertData['closedtime'];
                        }
                        
                        $bindings = array_merge($bindings, [
                            $upsertData['comment'],
                            $upsertData['status'],
                            $upsertData['sendtoclient'],
                            $upsertData['closedBy'],
                            $upsertData['sendip'],
                            $upsertData['alerttype'],
                            $upsertData['location'],
                            $upsertData['priority'],
                            $upsertData['AlertUserStatus'],
                            $upsertData['level'],
                            $upsertData['sip2'],
                            $upsertData['c_status'],
                            $upsertData['auto_alert'],
                            $upsertData['critical_alerts'],
                            $upsertData['Readstatus'],
                            $upsertData['sync_batch_id']
                        ]);
                        
                        DB::connection($this->connection)->statement($sql, $bindings);
                        
                        // STEP 6: VALIDATE - Fetch back and compare
                        $pgData = DB::connection($this->connection)
                            ->table($partitionTable)
                            ->where('id', $alertId)
                            ->first();
                        
                        if (!$pgData) {
                            throw new Exception("Alert {$alertId} not found in PostgreSQL after upsert");
                        }
                        
                        // Compare critical columns
                        $mismatches = [];
                        
                        // Check datetime columns
                        if ($mysqlData['createtime'] !== $pgData->createtime) {
                            $mismatches[] = "createtime: MySQL={$mysqlData['createtime']}, PG={$pgData->createtime}";
                        }
                        if ($mysqlData['receivedtime'] !== $pgData->receivedtime) {
                            $mismatches[] = "receivedtime: MySQL={$mysqlData['receivedtime']}, PG={$pgData->receivedtime}";
                        }
                        if ($mysqlData['closedtime'] !== $pgData->closedtime) {
                            $mismatches[] = "closedtime: MySQL=" . ($mysqlData['closedtime'] ?? 'NULL') . ", PG=" . ($pgData->closedtime ?? 'NULL');
                        }
                        
                        // Check other important columns
                        if ($mysqlData['status'] !== $pgData->status) {
                            $mismatches[] = "status: MySQL={$mysqlData['status']}, PG={$pgData->status}";
                        }
                        if ($mysqlData['closedBy'] !== $pgData->closedBy) {
                            $mismatches[] = "closedBy: MySQL=" . ($mysqlData['closedBy'] ?? 'NULL') . ", PG=" . ($pgData->closedBy ?? 'NULL');
                        }
                        
                        if (!empty($mismatches)) {
                            $this->logger->logWarning('Column value mismatches detected after upsert', [
                                'alert_id' => $alertId,
                                'partition_table' => $partitionTable,
                                'mismatches' => $mismatches
                            ]);
                            
                            // Log but don't fail - this helps us identify issues
                        } else {
                            $this->logger->logInfo('All columns match after upsert', [
                                'alert_id' => $alertId,
                                'partition_table' => $partitionTable
                            ]);
                        }
                        
                        $this->partitionManager->incrementRecordCount($partitionTable, 1);
                        
                        DB::connection($this->connection)->commit();
                        
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

