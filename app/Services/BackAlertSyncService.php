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
     */
    private function processUpdateLogEntry(BackAlertUpdateLog $updateLog): void
    {
        // Get the backalert record
        $backAlert = BackAlert::find($updateLog->backalert_id);
        
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
     */
    private function upsertBackAlertToPartition(BackAlert $backAlert, string $partitionTable): void
    {
        $now = now();
        
        $data = [
            'id' => $backAlert->id,
            'panelid' => $backAlert->panelid,
            'seqno' => $backAlert->seqno,
            'zone' => $backAlert->zone,
            'alarm' => $backAlert->alarm,
            'createtime' => $backAlert->createtime,
            'receivedtime' => $backAlert->receivedtime,
            'comment' => $backAlert->comment,
            'status' => $backAlert->status,
            'sendtoclient' => $backAlert->sendtoclient,
            'closedby' => $backAlert->closedBy, // Note: PostgreSQL column is lowercase
            'closedtime' => $backAlert->closedtime,
            'sendip' => $backAlert->sendip,
            'alerttype' => $backAlert->alerttype,
            'location' => $backAlert->location,
            'priority' => $backAlert->priority,
            'alertuserstatus' => $backAlert->AlertUserStatus, // Note: PostgreSQL column is lowercase
            'level' => $backAlert->level,
            'sip2' => $backAlert->sip2,
            'c_status' => $backAlert->c_status,
            'auto_alert' => $backAlert->auto_alert,
            'critical_alerts' => $backAlert->critical_alerts,
            'synced_at' => $now,
            'sync_batch_id' => -2, // Special batch ID for backalert updates
        ];

        DB::connection($this->connection)->table($partitionTable)->upsert(
            [$data],
            ['id'], // Unique key
            array_keys($data) // Update all columns on conflict
        );
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