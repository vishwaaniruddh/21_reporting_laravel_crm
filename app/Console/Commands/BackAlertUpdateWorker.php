<?php

namespace App\Console\Commands;

use App\Services\BackAlertSyncService;
use Illuminate\Console\Command;
use Exception;

class BackAlertUpdateWorker extends Command
{
    protected $signature = 'backalerts:update-worker 
                            {--poll-interval=5 : Polling interval in seconds}
                            {--batch-size=100 : Number of updates to process per batch}
                            {--max-retries=3 : Maximum retry attempts for failed updates}
                            {--cleanup-days=7 : Days to keep completed logs}';

    protected $description = 'Continuously sync backalert updates from MySQL to PostgreSQL partitions';

    private BackAlertSyncService $syncService;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $pollInterval = (int) $this->option('poll-interval');
        $batchSize = (int) $this->option('batch-size');
        $maxRetries = (int) $this->option('max-retries');
        $cleanupDays = (int) $this->option('cleanup-days');

        $this->syncService = new BackAlertSyncService(
            batchSize: $batchSize,
            maxRetries: $maxRetries
        );

        $this->info("Starting BackAlert Update Worker");
        $this->info("Poll interval: {$pollInterval} seconds");
        $this->info("Batch size: {$batchSize}");
        $this->info("Max retries: {$maxRetries}");
        $this->info("Cleanup days: {$cleanupDays}");

        $cycleCount = 0;
        $lastCleanup = time();
        $lastInitialSync = 0;
        $lastOldRecordCleanup = 0;
        $cleanupInterval = 3600; // Cleanup every hour
        $initialSyncInterval = 30; // Check for initial sync every 30 seconds
        $oldRecordCleanupInterval = 300; // Cleanup old records every 5 minutes

        while (true) {
            $cycleCount++;
            $cycleStart = microtime(true);

            try {
                // Check for unsynced records first (prioritize initial sync)
                $unsyncedCount = \App\Models\BackAlert::unsynced()->count();
                
                if ($unsyncedCount > 0) {
                    // Prioritize initial sync when there are unsynced records
                    $initialSyncResult = $this->runInitialSyncBatch($batchSize * 10); // Larger batch for initial sync
                    if ($initialSyncResult['processed'] > 0) {
                        $this->info("Cycle {$cycleCount}: Initial sync priority - {$initialSyncResult['message']} (Remaining: " . number_format($unsyncedCount) . ")");
                    } else {
                        // If no initial sync, process updates
                        $result = $this->syncService->processPendingUpdates($batchSize);
                        if ($result['processed'] > 0) {
                            $this->info("Cycle {$cycleCount}: {$result['message']} in " . round($result['duration'], 2) . "s");
                        } else {
                            $this->line("No pending updates. Sleeping...");
                        }
                    }
                } else {
                    // No unsynced records, process updates normally
                    $result = $this->syncService->processPendingUpdates($batchSize);
                    
                    if ($result['processed'] > 0) {
                        $this->info("Cycle {$cycleCount}: {$result['message']} in " . round($result['duration'], 2) . "s");
                    } else {
                        $this->line("No pending updates. All records synced!");
                    }
                }

                // Retry failed updates occasionally
                if ($cycleCount % 10 == 0) {
                    $retryResult = $this->syncService->retryFailedUpdates($batchSize);
                    if ($retryResult['retried'] > 0) {
                        $this->info("Retried {$retryResult['retried']} failed updates");
                    }
                }

                // Cleanup old logs periodically
                if (time() - $lastCleanup > $cleanupInterval) {
                    $cleanedCount = $this->syncService->cleanupOldLogs($cleanupDays);
                    if ($cleanedCount > 0) {
                        $this->info("Cleaned up {$cleanedCount} old log entries");
                    }
                    $lastCleanup = time();
                }

                // Cleanup old BackAlert records periodically (48+ hours old, synced only)
                if (time() - $lastOldRecordCleanup > $oldRecordCleanupInterval) {
                    $cleanupResult = $this->cleanupOldBackAlerts(48, 1000, true);
                    if ($cleanupResult['deleted'] > 0) {
                        $this->info("Old record cleanup: {$cleanupResult['message']}");
                    }
                    $lastOldRecordCleanup = time();
                }

                // Show stats every 50 cycles
                if ($cycleCount % 50 == 0) {
                    $stats = $this->syncService->getPendingUpdateStats();
                    $this->info("Stats - Pending: {$stats['pending']}, Completed: {$stats['completed']}, Failed: {$stats['failed']}");
                }

            } catch (Exception $e) {
                $this->error("Error in cycle {$cycleCount}: " . $e->getMessage());
                $this->error("Stack trace: " . $e->getTraceAsString());
            }

            // Sleep for the specified interval
            sleep($pollInterval);
        }

        return 0;
    }

    /**
     * Run a batch of initial sync for unsynced records
     */
    private function runInitialSyncBatch(int $batchSize): array
    {
        try {
            // Get unsynced backalerts
            $unsyncedBackAlerts = \App\Models\BackAlert::unsynced()
                ->orderBy('id', 'asc')
                ->limit($batchSize)
                ->get();

            if ($unsyncedBackAlerts->isEmpty()) {
                return [
                    'processed' => 0,
                    'message' => 'All records synced'
                ];
            }

            $processed = 0;
            $dateExtractor = new \App\Services\DateExtractor();

            // Group by date for partition sync
            $dateGroups = [];
            foreach ($unsyncedBackAlerts as $backAlert) {
                $date = $dateExtractor->extractDate($backAlert->receivedtime);
                $dateKey = $date->toDateString();
                
                if (!isset($dateGroups[$dateKey])) {
                    $dateGroups[$dateKey] = collect();
                }
                
                $dateGroups[$dateKey]->push($backAlert);
            }

            // Sync each date group
            foreach ($dateGroups as $dateKey => $dateBackAlerts) {
                $date = \Carbon\Carbon::parse($dateKey);
                $partitionTable = 'backalerts_' . $date->format('Y_m_d');
                
                // Ensure partition exists
                $this->ensurePartitionExists($partitionTable);
                
                // Insert to partition
                \Illuminate\Support\Facades\DB::connection('pgsql')->transaction(function () use ($dateBackAlerts, $partitionTable) {
                    $this->insertToPartition($dateBackAlerts, $partitionTable);
                });
                
                // Mark as synced
                $this->markAsSynced($dateBackAlerts);
                
                $processed += $dateBackAlerts->count();
            }

            return [
                'processed' => $processed,
                'message' => "Initial sync: {$processed} records processed"
            ];

        } catch (Exception $e) {
            $this->error("Initial sync error: " . $e->getMessage());
            return [
                'processed' => 0,
                'message' => 'Initial sync failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Ensure partition table exists
     */
    private function ensurePartitionExists(string $tableName): void
    {
        $exists = \Illuminate\Support\Facades\DB::connection('pgsql')
            ->select("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = ?)", [$tableName]);
        
        if (!($exists[0]->exists ?? false)) {
            $this->createPartitionTable($tableName);
        }
    }

    /**
     * Create partition table
     */
    private function createPartitionTable(string $tableName): void
    {
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

        \Illuminate\Support\Facades\DB::connection('pgsql')->statement($createTableSql);

        // Create indexes
        $indexes = [
            "CREATE INDEX idx_{$tableName}_receivedtime ON {$tableName}(receivedtime)",
            "CREATE INDEX idx_{$tableName}_panelid ON {$tableName}(panelid)",
            "CREATE INDEX idx_{$tableName}_status ON {$tableName}(status)",
            "CREATE INDEX idx_{$tableName}_synced_at ON {$tableName}(synced_at)"
        ];

        foreach ($indexes as $indexSql) {
            \Illuminate\Support\Facades\DB::connection('pgsql')->statement($indexSql);
        }
    }

    /**
     * Insert records to partition table
     */
    private function insertToPartition($backAlerts, string $partitionTable): void
    {
        $now = now();

        $insertData = $backAlerts->map(function ($backAlert) use ($now) {
            return [
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
                'closedby' => $backAlert->closedBy,
                'closedtime' => $backAlert->closedtime,
                'sendip' => $backAlert->sendip,
                'alerttype' => $backAlert->alerttype,
                'location' => $backAlert->location,
                'priority' => $backAlert->priority,
                'alertuserstatus' => $backAlert->AlertUserStatus,
                'level' => $backAlert->level,
                'sip2' => $backAlert->sip2,
                'c_status' => $backAlert->c_status,
                'auto_alert' => $backAlert->auto_alert,
                'critical_alerts' => $backAlert->critical_alerts,
                'synced_at' => $now,
                'sync_batch_id' => -3, // Initial sync batch ID
            ];
        })->toArray();

        // Insert in chunks
        $chunks = array_chunk($insertData, 500);
        foreach ($chunks as $chunk) {
            \Illuminate\Support\Facades\DB::connection('pgsql')->table($partitionTable)->upsert(
                $chunk,
                ['id'],
                array_keys($chunk[0])
            );
        }
    }

    /**
     * Mark records as synced
     */
    private function markAsSynced($backAlerts): void
    {
        $now = now();
        $backAlertIds = $backAlerts->pluck('id')->toArray();
        
        $chunks = array_chunk($backAlertIds, 1000);
        foreach ($chunks as $chunk) {
            \App\Models\BackAlert::whereIn('id', $chunk)->update([
                'synced_at' => $now,
                'sync_batch_id' => -3,
            ]);
        }
    }

    /**
     * Clean up old BackAlert records
     */
    private function cleanupOldBackAlerts(int $hours, int $batchSize, bool $syncOnly): array
    {
        try {
            $cutoffTime = now()->subHours($hours);
            
            // Build query for old records
            $query = \Illuminate\Support\Facades\DB::connection('mysql')
                ->table('backalerts')
                ->where('receivedtime', '<', $cutoffTime);

            // If sync-only mode, only delete synced records
            if ($syncOnly) {
                $query->whereNotNull('synced_at');
            }

            // Get batch of old record IDs
            $oldRecordIds = $query
                ->orderBy('receivedtime', 'asc')
                ->limit($batchSize)
                ->pluck('id')
                ->toArray();

            if (empty($oldRecordIds)) {
                return [
                    'deleted' => 0,
                    'message' => 'No old records to cleanup'
                ];
            }

            // Delete the records
            $deletedCount = \Illuminate\Support\Facades\DB::connection('mysql')
                ->table('backalerts')
                ->whereIn('id', $oldRecordIds)
                ->delete();

            return [
                'deleted' => $deletedCount,
                'message' => "Deleted {$deletedCount} old records (>48h, synced)"
            ];

        } catch (Exception $e) {
            return [
                'deleted' => 0,
                'message' => 'Cleanup failed: ' . $e->getMessage()
            ];
        }
    }
}