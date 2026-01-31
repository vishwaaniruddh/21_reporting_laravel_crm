<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\DateExtractor;
use App\Services\PartitionManager;
use Carbon\Carbon;
use Exception;

class SyncBackupDataCommand extends Command
{
    protected $signature = 'sync:backup-data 
                            {--start-id= : Start syncing from this ID (exclusive)}
                            {--batch-size=500 : Override the default batch size (recommended: 100-1000 for 50M+ records)}
                            {--continuous : Run continuously until all records are synced}
                            {--max-batches=1 : Maximum number of batches to process}
                            {--delete-after-sync : Delete records from alerts_all_data after successful sync}
                            {--status : Show current sync status}
                            {--force : Skip confirmation prompt (for service mode)}';

    protected $description = 'Sync 50M+ records from alerts_all_data backup table to PostgreSQL partitions with safe deletion';

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
        $deleteAfterSync = $this->option('delete-after-sync');
        $force = $this->option('force');

        // Validate batch size for large dataset
        if ($batchSize > 2000) {
            $this->warn("Large batch size ({$batchSize}) detected. For 50M+ records, consider using smaller batches (100-1000).");
            if (!$force && !$this->confirm('Continue with this batch size?')) {
                return 1;
            }
        }

        $this->info("=== BACKUP DATA SYNC (50M+ Records) ===");
        $this->info("Source: alerts_all_data table");
        $this->info("Target: PostgreSQL partitioned tables");
        $this->info("Batch size: {$batchSize}");
        $this->info("Delete after sync: " . ($deleteAfterSync ? 'YES' : 'NO'));
        
        if ($continuous) {
            $this->info("Mode: Continuous (until all records synced)");
        } else {
            $this->info("Mode: Limited ({$maxBatches} batches)");
        }

        // Show initial status
        $totalRecords = $this->getTotalBackupRecords();
        $this->info("Total records in alerts_all_data: " . number_format($totalRecords));

        if ($totalRecords == 0) {
            $this->info("No records to sync.");
            return 0;
        }

        $estimatedBatches = ceil($totalRecords / $batchSize);
        $this->info("Estimated batches needed: " . number_format($estimatedBatches));

        if (!$force && !$this->confirm('Start backup data sync?')) {
            return 1;
        }

        $totalProcessed = 0;
        $totalDeleted = 0;
        $batchCount = 0;
        $currentStartId = $startId;
        $startTime = microtime(true);

        do {
            $batchCount++;
            $batchStartTime = microtime(true);
            
            $this->info("=== Batch {$batchCount} ===");

            // Fetch batch from alerts_all_data
            $alerts = $this->fetchBackupDataBatch($currentStartId, $batchSize);

            if ($alerts->isEmpty()) {
                $this->info("No more records to process");
                break;
            }

            $this->info("Fetched {$alerts->count()} records (IDs: {$alerts->min('id')} - {$alerts->max('id')})");

            // Group by date and sync
            $dateGroups = $this->groupAlertsByDate($alerts);
            $batchProcessed = 0;
            $syncedIds = [];

            foreach ($dateGroups as $dateKey => $dateAlerts) {
                try {
                    $date = Carbon::parse($dateKey);
                    $partitionTable = $this->partitionManager->getPartitionTableName($date);
                    
                    $this->line("  → Syncing {$dateAlerts->count()} records to {$partitionTable}");
                    
                    // Ensure partition exists
                    $this->partitionManager->ensurePartitionExists($date);
                    
                    // Insert to partition with transaction
                    DB::connection('pgsql')->transaction(function () use ($dateAlerts, $partitionTable) {
                        $this->insertToPartition($dateAlerts, $partitionTable);
                    });
                    
                    // Track successfully synced IDs
                    $syncedIds = array_merge($syncedIds, $dateAlerts->pluck('id')->toArray());
                    $batchProcessed += $dateAlerts->count();
                    
                    $this->line("    ✓ Success: {$dateAlerts->count()} records");
                    
                } catch (Exception $e) {
                    $this->error("    ✗ Failed date group {$dateKey}: " . $e->getMessage());
                    // Don't include failed records in syncedIds for deletion
                    continue;
                }
            }

            // Delete successfully synced records from alerts_all_data
            $deletedCount = 0;
            if ($deleteAfterSync && !empty($syncedIds)) {
                try {
                    $deletedCount = $this->deleteBackupRecords($syncedIds);
                    $this->info("  → Deleted {$deletedCount} synced records from alerts_all_data");
                } catch (Exception $e) {
                    $this->error("  → Failed to delete records: " . $e->getMessage());
                    $this->warn("  → Records synced but not deleted. Manual cleanup may be needed.");
                }
            }

            $totalProcessed += $batchProcessed;
            $totalDeleted += $deletedCount;
            $currentStartId = $alerts->max('id');
            
            $batchDuration = microtime(true) - $batchStartTime;
            $this->info("Batch {$batchCount} completed in " . round($batchDuration, 2) . "s");
            $this->info("  Processed: {$batchProcessed}, Deleted: {$deletedCount}");
            $this->info("  Total so far: " . number_format($totalProcessed) . " processed, " . number_format($totalDeleted) . " deleted");

            // Progress indicator
            if ($totalRecords > 0) {
                $progress = ($totalProcessed / $totalRecords) * 100;
                $this->info("  Progress: " . round($progress, 2) . "%");
            }

            // Memory cleanup
            $hasMoreRecords = $alerts->isNotEmpty() && $alerts->count() == $batchSize;
            unset($alerts, $dateGroups, $syncedIds);
            if ($batchCount % 10 == 0) {
                gc_collect_cycles();
            }

        } while (($continuous || $batchCount < $maxBatches) && $hasMoreRecords);

        $totalDuration = microtime(true) - $startTime;
        
        $this->info("=== BACKUP DATA SYNC COMPLETED ===");
        $this->info("Total records processed: " . number_format($totalProcessed));
        $this->info("Total records deleted: " . number_format($totalDeleted));
        $this->info("Total batches processed: " . number_format($batchCount));
        $this->info("Total duration: " . gmdate('H:i:s', $totalDuration));
        
        if ($totalProcessed > 0) {
            $avgPerSecond = $totalProcessed / $totalDuration;
            $this->info("Average speed: " . number_format($avgPerSecond, 2) . " records/second");
        }

        // Final status
        $remainingRecords = $this->getTotalBackupRecords();
        $this->info("Remaining records in alerts_all_data: " . number_format($remainingRecords));

        return 0;
    }

    private function showStatus(): int
    {
        $this->info("=== BACKUP DATA SYNC STATUS ===");
        
        $totalRecords = $this->getTotalBackupRecords();
        $this->info("Total records in alerts_all_data: " . number_format($totalRecords));
        
        if ($totalRecords == 0) {
            $this->info("✓ No records remaining - sync complete!");
            return 0;
        }

        // Show date range
        $dateRange = $this->getBackupDateRange();
        if ($dateRange) {
            $this->info("Date range: {$dateRange['min']} to {$dateRange['max']}");
        }

        // Show sample of oldest records
        $oldestRecords = $this->getOldestBackupRecords(5);
        if ($oldestRecords->isNotEmpty()) {
            $this->info("Oldest records (next to sync):");
            foreach ($oldestRecords as $record) {
                $this->line("  ID: {$record->id}, Date: {$record->receivedtime}");
            }
        }

        return 0;
    }

    private function fetchBackupDataBatch(?int $startId, int $batchSize)
    {
        $query = DB::connection('mysql')
            ->table('alerts_all_data')
            ->orderBy('id', 'asc');

        if ($startId !== null && $startId > 0) {
            $query->where('id', '>', $startId);
        }

        return $query->limit($batchSize)->get();
    }

    private function groupAlertsByDate($alerts): array
    {
        $dateGroups = [];

        foreach ($alerts as $alert) {
            try {
                $date = $this->dateExtractor->extractDate($alert->receivedtime);
                $dateKey = $date->toDateString();

                if (!isset($dateGroups[$dateKey])) {
                    $dateGroups[$dateKey] = collect();
                }

                $dateGroups[$dateKey]->push($alert);

            } catch (Exception $e) {
                $this->error("Failed to extract date from alert {$alert->id}: " . $e->getMessage());
                continue;
            }
        }

        return $dateGroups;
    }

    private function insertToPartition($alerts, string $partitionTable): void
    {
        $now = now();

        $insertData = $alerts->map(function ($alert) use ($now) {
            return [
                'id' => $alert->id,
                'panelid' => $alert->panelid,
                'seqno' => $alert->seqno,
                'zone' => $alert->zone,
                'alarm' => $alert->alarm,
                'createtime' => $alert->createtime,
                'receivedtime' => $alert->receivedtime,
                'comment' => $alert->comment,
                'status' => $alert->status,
                'sendtoclient' => $alert->sendtoclient,
                'closedBy' => $alert->closedBy,
                'closedtime' => $alert->closedtime,
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
                'sync_batch_id' => -1, // Special batch ID for backup sync
            ];
        })->toArray();

        // Insert in chunks to prevent memory issues with large batches
        $chunks = array_chunk($insertData, 500);
        foreach ($chunks as $chunk) {
            DB::connection('pgsql')->table($partitionTable)->upsert(
                $chunk,
                ['id'], // Unique key
                [ // Update columns
                    'panelid', 'seqno', 'zone', 'alarm', 'createtime', 'receivedtime',
                    'comment', 'status', 'sendtoclient', 'closedBy', 'closedtime',
                    'sendip', 'alerttype', 'location', 'priority', 'AlertUserStatus',
                    'level', 'sip2', 'c_status', 'auto_alert', 'critical_alerts',
                    'Readstatus', 'synced_at', 'sync_batch_id'
                ]
            );
        }
    }

    /**
     * Get total count of records in alerts_all_data
     */
    private function getTotalBackupRecords(): int
    {
        return DB::connection('mysql')->table('alerts_all_data')->count();
    }

    /**
     * Get date range of backup data
     */
    private function getBackupDateRange(): ?array
    {
        $result = DB::connection('mysql')
            ->table('alerts_all_data')
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

    /**
     * Get oldest records for status display
     */
    private function getOldestBackupRecords(int $limit = 5)
    {
        return DB::connection('mysql')
            ->table('alerts_all_data')
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get(['id', 'receivedtime']);
    }

    /**
     * Safely delete records from alerts_all_data after successful sync
     * Only deletes records that were successfully synced to PostgreSQL
     */
    private function deleteBackupRecords(array $syncedIds): int
    {
        if (empty($syncedIds)) {
            return 0;
        }

        // Delete in chunks to prevent long-running queries
        $deletedCount = 0;
        $chunks = array_chunk($syncedIds, 1000);
        
        foreach ($chunks as $chunk) {
            $deleted = DB::connection('mysql')
                ->table('alerts_all_data')
                ->whereIn('id', $chunk)
                ->delete();
            
            $deletedCount += $deleted;
        }

        return $deletedCount;
    }
}