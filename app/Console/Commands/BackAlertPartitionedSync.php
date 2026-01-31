<?php

namespace App\Console\Commands;

use App\Models\BackAlert;
use App\Services\DateExtractor;
use App\Services\PartitionManager;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackAlertPartitionedSync extends Command
{
    protected $signature = 'backalerts:partitioned 
                            {--start-id= : Start syncing from this ID (exclusive)}
                            {--batch-size=1000 : Override the default batch size}
                            {--status : Show current sync status}
                            {--continuous : Run continuously until all records are synced}
                            {--max-batches=1 : Maximum number of batches to process}';

    protected $description = 'Sync backalerts from MySQL to PostgreSQL partition tables (initial sync)';

    private DateExtractor $dateExtractor;
    private PartitionManager $partitionManager;

    public function __construct()
    {
        parent::__construct();
        $this->dateExtractor = new DateExtractor();
        $this->partitionManager = new PartitionManager($this->dateExtractor);
    }

    public function handle()
    {
        // Show status if requested
        if ($this->option('status')) {
            return $this->showStatus();
        }

        $startId = $this->option('start-id') ? (int) $this->option('start-id') : null;
        $batchSize = (int) $this->option('batch-size');
        $continuous = $this->option('continuous');
        $maxBatches = (int) $this->option('max-batches');

        $this->info("=== BackAlert Partitioned Sync ===");
        $this->info("Batch size: {$batchSize}");
        
        if ($continuous) {
            $this->info("Mode: Continuous (until all records synced)");
        } else {
            $this->info("Mode: Limited ({$maxBatches} batches)");
        }

        $totalProcessed = 0;
        $batchCount = 0;
        $currentStartId = $startId;
        $startTime = microtime(true);

        do {
            $batchCount++;
            $batchStartTime = microtime(true);
            
            $this->info("=== Batch {$batchCount} ===");

            // Fetch batch of unsynced backalerts
            $backAlerts = $this->fetchUnsyncedBatch($currentStartId, $batchSize);

            if ($backAlerts->isEmpty()) {
                $this->info("No more unsynced records to process");
                break;
            }

            $this->info("Fetched {$backAlerts->count()} unsynced backalerts (IDs: {$backAlerts->min('id')} - {$backAlerts->max('id')})");

            // Group by date and sync
            $dateGroups = $this->groupBackAlertsByDate($backAlerts);
            $batchProcessed = 0;

            foreach ($dateGroups as $dateKey => $dateBackAlerts) {
                try {
                    $date = Carbon::parse($dateKey);
                    $partitionTable = $this->getPartitionTableName($date);
                    
                    $this->line("  → Syncing {$dateBackAlerts->count()} records to {$partitionTable}");
                    
                    // Ensure partition exists
                    $this->ensureBackAlertPartitionExists($date, $partitionTable);
                    
                    // Insert to partition with transaction
                    DB::connection('pgsql')->transaction(function () use ($dateBackAlerts, $partitionTable) {
                        $this->insertToPartition($dateBackAlerts, $partitionTable);
                    });
                    
                    // Mark as synced in MySQL
                    $this->markAsSynced($dateBackAlerts);
                    
                    $batchProcessed += $dateBackAlerts->count();
                    $this->line("    ✓ Success: {$dateBackAlerts->count()} records");
                    
                } catch (Exception $e) {
                    $this->error("    ✗ Failed date group {$dateKey}: " . $e->getMessage());
                    continue;
                }
            }

            $totalProcessed += $batchProcessed;
            $currentStartId = $backAlerts->max('id');
            $hasMoreRecords = !$backAlerts->isEmpty();
            
            $batchDuration = microtime(true) - $batchStartTime;
            $this->info("Batch {$batchCount} completed in " . round($batchDuration, 2) . "s");
            $this->info("  Processed: {$batchProcessed}");
            $this->info("  Total so far: " . number_format($totalProcessed));

            // Memory cleanup
            unset($backAlerts, $dateGroups);
            if ($batchCount % 10 == 0) {
                gc_collect_cycles();
            }

        } while (($continuous || $batchCount < $maxBatches) && $hasMoreRecords);

        $totalDuration = microtime(true) - $startTime;
        
        $this->info("=== BACKALERT PARTITIONED SYNC COMPLETED ===");
        $this->info("Total records processed: " . number_format($totalProcessed));
        $this->info("Total batches processed: " . number_format($batchCount));
        $this->info("Total duration: " . gmdate('H:i:s', $totalDuration));
        
        if ($totalProcessed > 0) {
            $avgPerSecond = $totalProcessed / $totalDuration;
            $this->info("Average speed: " . number_format($avgPerSecond, 2) . " records/second");
        }

        return 0;
    }

    private function showStatus(): int
    {
        $this->info("=== BACKALERT PARTITIONED SYNC STATUS ===");
        
        $totalRecords = BackAlert::count();
        $unsyncedRecords = BackAlert::unsynced()->count();
        $syncedRecords = BackAlert::synced()->count();
        
        $this->info("Total backalerts: " . number_format($totalRecords));
        $this->info("Unsynced records: " . number_format($unsyncedRecords));
        $this->info("Synced records: " . number_format($syncedRecords));
        
        if ($unsyncedRecords == 0) {
            $this->info("✓ All records synced!");
            return 0;
        }

        $progress = ($syncedRecords / $totalRecords) * 100;
        $this->info("Progress: " . round($progress, 2) . "%");

        // Show date range of unsynced records
        $dateRange = $this->getUnsyncedDateRange();
        if ($dateRange) {
            $this->info("Unsynced date range: {$dateRange['min']} to {$dateRange['max']}");
        }

        return 0;
    }

    private function fetchUnsyncedBatch(?int $startId, int $batchSize)
    {
        $query = BackAlert::unsynced()->orderBy('id', 'asc');
        
        if ($startId !== null && $startId > 0) {
            $query->where('id', '>', $startId);
        }
        
        return $query->limit($batchSize)->get();
    }

    private function groupBackAlertsByDate($backAlerts): array
    {
        $dateGroups = [];
        
        foreach ($backAlerts as $backAlert) {
            try {
                $date = $this->dateExtractor->extractDate($backAlert->receivedtime);
                $dateKey = $date->toDateString();
                
                if (!isset($dateGroups[$dateKey])) {
                    $dateGroups[$dateKey] = collect();
                }
                
                $dateGroups[$dateKey]->push($backAlert);
                
            } catch (Exception $e) {
                $this->error("Failed to extract date from backalert {$backAlert->id}: " . $e->getMessage());
                continue;
            }
        }
        
        return $dateGroups;
    }

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
                'sync_batch_id' => -3, // Special batch ID for backalert initial sync
            ];
        })->toArray();

        // Insert in chunks
        $chunks = array_chunk($insertData, 500);
        foreach ($chunks as $chunk) {
            DB::connection('pgsql')->table($partitionTable)->upsert(
                $chunk,
                ['id'], // Unique key
                array_keys($chunk[0]) // Update all columns on conflict
            );
        }
    }

    private function markAsSynced($backAlerts): void
    {
        $now = now();
        $backAlertIds = $backAlerts->pluck('id')->toArray();
        
        // Update in chunks
        $chunks = array_chunk($backAlertIds, 1000);
        foreach ($chunks as $chunk) {
            BackAlert::whereIn('id', $chunk)->update([
                'synced_at' => $now,
                'sync_batch_id' => -3,
            ]);
        }
    }

    private function getPartitionTableName(Carbon $date): string
    {
        return 'backalerts_' . $date->format('Y_m_d');
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
        
        $this->line("    Created partition table: {$partitionTable}");
    }

    /**
     * Check if a backalerts partition table exists
     */
    private function backAlertPartitionTableExists(string $tableName): bool
    {
        $exists = DB::connection('pgsql')
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

        DB::connection('pgsql')->statement($createTableSql);

        // Create indexes separately
        $indexes = [
            "CREATE INDEX idx_{$tableName}_receivedtime ON {$tableName}(receivedtime)",
            "CREATE INDEX idx_{$tableName}_panelid ON {$tableName}(panelid)",
            "CREATE INDEX idx_{$tableName}_status ON {$tableName}(status)",
            "CREATE INDEX idx_{$tableName}_synced_at ON {$tableName}(synced_at)"
        ];

        foreach ($indexes as $indexSql) {
            DB::connection('pgsql')->statement($indexSql);
        }
    }

    private function getUnsyncedDateRange(): ?array
    {
        $result = BackAlert::unsynced()
            ->selectRaw('MIN(receivedtime) as min_date, MAX(receivedtime) as max_date')
            ->first();

        if ($result && $result->min_date && $result->max_date) {
            return [
                'min' => $result->min_date,
                'max' => $result->max_date
            ];
        }

        return null;
    }
}