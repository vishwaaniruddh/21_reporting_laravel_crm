<?php

namespace App\Services;

use App\Models\BackAlert;
use App\Models\BackAlertUpdateLog;
use App\Services\DateExtractor;
use App\Services\PartitionManager;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BackAlertSyncService
 * 
 * Handles synchronization of backalerts from MySQL to PostgreSQL partition tables.
 * Similar to AlertSyncService but for backalerts table.
 * 
 * Key differences from AlertSyncService:
 * - Uses backalerts table instead of alerts
 * - Uses backalert_pg_update_log instead of alert_pg_update_log
 * - Creates backalerts_YYYY_MM_DD partition tables
 * - No deletion from MySQL (keep all backalerts records)
 */
class BackAlertSyncService
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
     * Default batch size for sync operations
     */
    private int $batchSize;
    
    /**
     * PostgreSQL connection name
     */
    private string $connection = 'pgsql';
    
    /**
     * Maximum retry attempts for failed syncs
     */
    private int $maxRetries;

    /**
     * Create a new BackAlertSyncService instance
     */
    public function __construct(
        ?DateExtractor $dateExtractor = null,
        ?PartitionManager $partitionManager = null,
        ?int $batchSize = null,
        ?int $maxRetries = null
    ) {
        $this->dateExtractor = $dateExtractor ?? new DateExtractor();
        $this->partitionManager = $partitionManager ?? new PartitionManager($this->dateExtractor);
        $this->batchSize = $batchSize ?? 100;
        $this->maxRetries = $maxRetries ?? 3;
    }

    /**
     * Process pending backalert update log entries
     */
    public function processPendingUpdates(int $batchSize = null): array
    {
        $batchSize = $batchSize ?? $this->batchSize;
        $startTime = microtime(true);
        
        // Get pending update log entries
        $pendingUpdates = BackAlertUpdateLog::pending()
            ->withinRetryLimit($this->maxRetries)
            ->oldestFirst()
            ->limit($batchSize)
            ->get();

        if ($pendingUpdates->isEmpty()) {
            return [
                'processed' => 0,
                'successful' => 0,
                'failed' => 0,
                'duration' => microtime(true) - $startTime,
                'message' => 'No pending updates to process'
            ];
        }

        Log::info('Processing backalert updates', [
            'batch_size' => $pendingUpdates->count(),
            'service' => 'BackAlertSyncService'
        ]);

        $successful = 0;
        $failed = 0;

        foreach ($pendingUpdates as $updateLog) {
            try {
                $this->processUpdateLogEntry($updateLog);
                $successful++;
            } catch (Exception $e) {
                $updateLog->markAsFailed($e->getMessage());
                $failed++;
                
                Log::error('Failed to process backalert update', [
                    'update_log_id' => $updateLog->id,
                    'backalert_id' => $updateLog->backalert_id,
                    'error' => $e->getMessage(),
                    'service' => 'BackAlertSyncService'
                ]);
            }
        }

        $duration = microtime(true) - $startTime;

        Log::info('Backalert update processing completed', [
            'processed' => $pendingUpdates->count(),
            'successful' => $successful,
            'failed' => $failed,
            'duration' => round($duration, 2),
            'service' => 'BackAlertSyncService'
        ]);

        return [
            'processed' => $pendingUpdates->count(),
            'successful' => $successful,
            'failed' => $failed,
            'duration' => $duration,
            'message' => "Processed {$pendingUpdates->count()} updates: {$successful} successful, {$failed} failed"
        ];
    }

    /**
     * Process a single update log entry
     * 
     * SIMPLIFIED APPROACH:
     * 1. Fetch fresh data from MySQL at upsert time
     * 2. Use ALL values from MySQL as-is
     * 3. Validate after upsert
     */
    private function processUpdateLogEntry(BackAlertUpdateLog $updateLog): void
    {
        // STEP 1: Fetch FRESH data from MySQL (raw, no conversion)
        $backAlert = DB::connection('mysql')
            ->table('backalerts')
            ->where('id', $updateLog->backalert_id)
            ->first();
        
        if (!$backAlert) {
            throw new Exception("BackAlert record not found: {$updateLog->backalert_id}");
        }

        // Extract date from receivedtime
        $date = $this->dateExtractor->extractDate($backAlert->receivedtime);
        $partitionTable = $this->getPartitionTableName($date);

        // Ensure partition table exists
        $this->ensureBackAlertPartitionExists($date, $partitionTable);

        // Sync the backalert to partition table
        DB::connection($this->connection)->transaction(function () use ($backAlert, $partitionTable, $updateLog) {
            $this->upsertBackAlertToPartition($backAlert, $partitionTable);
            $updateLog->markAsCompleted();
        });

        Log::debug('BackAlert synced to partition', [
            'backalert_id' => $backAlert->id,
            'partition_table' => $partitionTable,
            'update_log_id' => $updateLog->id,
            'service' => 'BackAlertSyncService'
        ]);
    }

    /**
     * Upsert a backalert record to a partition table
     * 
     * SIMPLIFIED: Use ALL values from MySQL as-is, then validate
     */
    private function upsertBackAlertToPartition($backAlert, string $partitionTable): void
    {
        $now = now();
        
        // Convert stdClass to array
        $backAlertData = (array) $backAlert;
        
        // STEP 1: Prepare data - USE ALL VALUES FROM MYSQL AS-IS
        $data = [
            'id' => $backAlertData['id'],
            'panelid' => $backAlertData['panelid'] ?? null,
            'seqno' => $backAlertData['seqno'] ?? null,
            'zone' => $backAlertData['zone'] ?? null,
            'alarm' => $backAlertData['alarm'] ?? null,
            'createtime' => $backAlertData['createtime'] ?? null,      // From MySQL as-is
            'receivedtime' => $backAlertData['receivedtime'] ?? null,  // From MySQL as-is
            'closedtime' => $backAlertData['closedtime'] ?? null,      // From MySQL as-is
            'comment' => $backAlertData['comment'] ?? null,
            'status' => $backAlertData['status'] ?? null,
            'sendtoclient' => $backAlertData['sendtoclient'] ?? null,
            'closedby' => $backAlertData['closedBy'] ?? null,
            'sendip' => $backAlertData['sendip'] ?? null,
            'alerttype' => $backAlertData['alerttype'] ?? null,
            'location' => $backAlertData['location'] ?? null,
            'priority' => $backAlertData['priority'] ?? null,
            'alertuserstatus' => $backAlertData['AlertUserStatus'] ?? null,
            'level' => $backAlertData['level'] ?? null,
            'sip2' => $backAlertData['sip2'] ?? null,
            'c_status' => $backAlertData['c_status'] ?? null,
            'auto_alert' => $backAlertData['auto_alert'] ?? null,
            'critical_alerts' => $backAlertData['critical_alerts'] ?? null,
            'synced_at' => $now,
            'sync_batch_id' => -2,
        ];

        // STEP 2: Perform UPSERT with explicit timestamp casting to prevent timezone conversion
        // Using raw SQL to ensure timestamps are preserved exactly as-is
        $sql = "
            INSERT INTO {$partitionTable} (
                id, panelid, seqno, zone, alarm,
                createtime, receivedtime, closedtime,
                comment, status, sendtoclient, closedby, sendip,
                alerttype, location, priority, alertuserstatus,
                level, sip2, c_status, auto_alert, critical_alerts,
                synced_at, sync_batch_id
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?::timestamp, ?::timestamp, " . ($data['closedtime'] ? "?::timestamp" : "NULL") . ",
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                NOW(), ?
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
                closedby = EXCLUDED.closedby,
                sendip = EXCLUDED.sendip,
                alerttype = EXCLUDED.alerttype,
                location = EXCLUDED.location,
                priority = EXCLUDED.priority,
                alertuserstatus = EXCLUDED.alertuserstatus,
                level = EXCLUDED.level,
                sip2 = EXCLUDED.sip2,
                c_status = EXCLUDED.c_status,
                auto_alert = EXCLUDED.auto_alert,
                critical_alerts = EXCLUDED.critical_alerts,
                synced_at = NOW(),
                sync_batch_id = EXCLUDED.sync_batch_id
        ";
        
        $bindings = [
            $data['id'],
            $data['panelid'],
            $data['seqno'],
            $data['zone'],
            $data['alarm'],
            $data['createtime'],
            $data['receivedtime']
        ];
        
        if ($data['closedtime']) {
            $bindings[] = $data['closedtime'];
        }
        
        $bindings = array_merge($bindings, [
            $data['comment'],
            $data['status'],
            $data['sendtoclient'],
            $data['closedby'],
            $data['sendip'],
            $data['alerttype'],
            $data['location'],
            $data['priority'],
            $data['alertuserstatus'],
            $data['level'],
            $data['sip2'],
            $data['c_status'],
            $data['auto_alert'],
            $data['critical_alerts'],
            $data['sync_batch_id']
        ]);
        
        DB::connection($this->connection)->statement($sql, $bindings);
        
        // STEP 3: VALIDATE - Fetch back and compare
        $pgData = DB::connection($this->connection)
            ->table($partitionTable)
            ->where('id', $backAlertData['id'])
            ->first();
        
        if (!$pgData) {
            throw new Exception("BackAlert {$backAlertData['id']} not found in PostgreSQL after upsert");
        }
        
        // Compare critical columns
        $mismatches = [];
        
        if ($backAlertData['createtime'] !== $pgData->createtime) {
            $mismatches[] = "createtime: MySQL={$backAlertData['createtime']}, PG={$pgData->createtime}";
        }
        if ($backAlertData['receivedtime'] !== $pgData->receivedtime) {
            $mismatches[] = "receivedtime: MySQL={$backAlertData['receivedtime']}, PG={$pgData->receivedtime}";
        }
        if ($backAlertData['closedtime'] !== $pgData->closedtime) {
            $mismatches[] = "closedtime: MySQL=" . ($backAlertData['closedtime'] ?? 'NULL') . ", PG=" . ($pgData->closedtime ?? 'NULL');
        }
        
        if (!empty($mismatches)) {
            Log::warning('BackAlert column value mismatches detected after upsert', [
                'backalert_id' => $backAlertData['id'],
                'partition_table' => $partitionTable,
                'mismatches' => $mismatches
            ]);
        } else {
            Log::debug('BackAlert all columns match after upsert', [
                'backalert_id' => $backAlertData['id'],
                'partition_table' => $partitionTable
            ]);
        }
    }

    /**
     * Get partition table name for a date
     */
    private function getPartitionTableName(Carbon $date): string
    {
        return 'backalerts_' . $date->format('Y_m_d');
    }

    /**
     * Get statistics about pending updates
     */
    public function getPendingUpdateStats(): array
    {
        return [
            'pending' => BackAlertUpdateLog::pending()->count(),
            'completed' => BackAlertUpdateLog::completed()->count(),
            'failed' => BackAlertUpdateLog::failed()->count(),
            'total' => BackAlertUpdateLog::count(),
            'failed_retryable' => BackAlertUpdateLog::failed()
                ->withinRetryLimit($this->maxRetries)
                ->count(),
        ];
    }

    /**
     * Retry failed update log entries
     */
    public function retryFailedUpdates(int $batchSize = null): array
    {
        $batchSize = $batchSize ?? $this->batchSize;
        
        $failedUpdates = BackAlertUpdateLog::failed()
            ->withinRetryLimit($this->maxRetries)
            ->oldestFirst()
            ->limit($batchSize)
            ->get();

        if ($failedUpdates->isEmpty()) {
            return [
                'retried' => 0,
                'message' => 'No failed updates to retry'
            ];
        }

        foreach ($failedUpdates as $updateLog) {
            $updateLog->resetForRetry();
        }

        Log::info('Reset failed backalert updates for retry', [
            'count' => $failedUpdates->count(),
            'service' => 'BackAlertSyncService'
        ]);

        return [
            'retried' => $failedUpdates->count(),
            'message' => "Reset {$failedUpdates->count()} failed updates for retry"
        ];
    }

    /**
     * Ensure a backalerts partition table exists for the given date
     */
    private function ensureBackAlertPartitionExists(Carbon $date, string $partitionTable): void
    {
        // Check if partition table already exists
        if ($this->backAlertPartitionTableExists($partitionTable)) {
            return;
        }

        // Create the partition table
        $this->createBackAlertPartitionTable($partitionTable);
        
        Log::info('Created backalerts partition table', [
            'table' => $partitionTable,
            'date' => $date->toDateString(),
            'service' => 'BackAlertSyncService'
        ]);
    }

    /**
     * Check if a backalerts partition table exists
     */
    private function backAlertPartitionTableExists(string $tableName): bool
    {
        $exists = DB::connection($this->connection)
            ->select("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = ?)", [$tableName]);
        
        return $exists[0]->exists ?? false;
    }

    /**
     * Create a backalerts partition table with proper schema
     */
    private function createBackAlertPartitionTable(string $tableName): void
    {
        // Create the table
        $createTableSql = "
            CREATE TABLE {$tableName} (
                id BIGINT PRIMARY KEY,
                panelid VARCHAR(50),
                seqno VARCHAR(100),
                zone VARCHAR(10),
                alarm VARCHAR(10),
                createtime TIMESTAMP,
                receivedtime TIMESTAMP,
                comment TEXT,
                status VARCHAR(10),
                sendtoclient VARCHAR(10),
                closedby VARCHAR(50),
                closedtime TIMESTAMP,
                sendip VARCHAR(50),
                alerttype VARCHAR(100),
                location VARCHAR(10),
                priority VARCHAR(10),
                alertuserstatus VARCHAR(100),
                level INTEGER,
                sip2 VARCHAR(50),
                c_status VARCHAR(10),
                auto_alert INTEGER,
                critical_alerts VARCHAR(10),
                synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sync_batch_id INTEGER NOT NULL
            )
        ";

        DB::connection($this->connection)->statement($createTableSql);

        // Create indexes separately
        $indexes = [
            "CREATE INDEX idx_{$tableName}_receivedtime ON {$tableName}(receivedtime)",
            "CREATE INDEX idx_{$tableName}_panelid ON {$tableName}(panelid)",
            "CREATE INDEX idx_{$tableName}_status ON {$tableName}(status)",
            "CREATE INDEX idx_{$tableName}_synced_at ON {$tableName}(synced_at)"
        ];

        foreach ($indexes as $indexSql) {
            DB::connection($this->connection)->statement($indexSql);
        }
    }

    /**
     * Clean up old completed update log entries
     */
    public function cleanupOldLogs(int $daysOld = 7): int
    {
        $cutoffDate = now()->subDays($daysOld);
        
        $deletedCount = BackAlertUpdateLog::completed()
            ->where('updated_at', '<', $cutoffDate)
            ->delete();

        if ($deletedCount > 0) {
            Log::info('Cleaned up old backalert update logs', [
                'deleted_count' => $deletedCount,
                'cutoff_date' => $cutoffDate,
                'service' => 'BackAlertSyncService'
            ]);
        }

        return $deletedCount;
    }
}