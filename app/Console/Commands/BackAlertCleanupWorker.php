<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Exception;

class BackAlertCleanupWorker extends Command
{
    protected $signature = 'backalerts:cleanup-worker 
                            {--hours=48 : Delete records older than this many hours}
                            {--batch-size=1000 : Number of records to delete per batch}
                            {--poll-interval=60 : Polling interval in seconds}
                            {--sync-only : Only delete synced records}
                            {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Continuously cleanup old backalert records from MySQL in batches';

    public function handle()
    {
        $hours = (int) $this->option('hours');
        $batchSize = (int) $this->option('batch-size');
        $pollInterval = (int) $this->option('poll-interval');
        $syncOnly = $this->option('sync-only');
        $dryRun = $this->option('dry-run');

        $this->info("=== BackAlert Cleanup Worker ===");
        $this->info("Delete records older than: {$hours} hours");
        $this->info("Batch size: {$batchSize}");
        $this->info("Poll interval: {$pollInterval} seconds");
        $this->info("Sync only mode: " . ($syncOnly ? 'YES' : 'NO'));
        $this->info("Dry run mode: " . ($dryRun ? 'YES' : 'NO'));
        $this->line("");

        $cycleCount = 0;
        $totalDeleted = 0;

        while (true) {
            $cycleCount++;
            $cycleStart = microtime(true);

            try {
                $result = $this->cleanupBatch($hours, $batchSize, $syncOnly, $dryRun);
                
                if ($result['deleted'] > 0) {
                    $totalDeleted += $result['deleted'];
                    $this->info("Cycle {$cycleCount}: {$result['message']} (Total deleted: " . number_format($totalDeleted) . ")");
                } else {
                    $this->line("Cycle {$cycleCount}: No old records to cleanup");
                }

                // Show stats every 10 cycles
                if ($cycleCount % 10 == 0) {
                    $stats = $this->getCleanupStats($hours, $syncOnly);
                    $this->info("Stats - Remaining old records: " . number_format($stats['old_records']) . 
                               ", Total records: " . number_format($stats['total_records']));
                }

            } catch (Exception $e) {
                $this->error("Error in cycle {$cycleCount}: " . $e->getMessage());
            }

            // Sleep for the specified interval
            sleep($pollInterval);
        }

        return 0;
    }

    /**
     * Clean up a batch of old records
     */
    private function cleanupBatch(int $hours, int $batchSize, bool $syncOnly, bool $dryRun): array
    {
        $cutoffTime = now()->subHours($hours);
        
        // Build query for old records
        $query = DB::connection('mysql')
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
                'message' => 'No old records found'
            ];
        }

        $recordCount = count($oldRecordIds);

        if ($dryRun) {
            // Dry run - just show what would be deleted
            $sampleRecord = DB::connection('mysql')
                ->table('backalerts')
                ->whereIn('id', array_slice($oldRecordIds, 0, 1))
                ->select('id', 'panelid', 'receivedtime', 'synced_at')
                ->first();

            $syncStatus = $sampleRecord->synced_at ? 'SYNCED' : 'NOT SYNCED';
            
            return [
                'deleted' => $recordCount,
                'message' => "DRY RUN: Would delete {$recordCount} records (Sample: ID {$sampleRecord->id}, {$syncStatus})"
            ];
        }

        // Actually delete the records
        $deletedCount = DB::connection('mysql')
            ->table('backalerts')
            ->whereIn('id', $oldRecordIds)
            ->delete();

        return [
            'deleted' => $deletedCount,
            'message' => "Deleted {$deletedCount} old records (batch size: {$recordCount})"
        ];
    }

    /**
     * Get cleanup statistics
     */
    private function getCleanupStats(int $hours, bool $syncOnly): array
    {
        $cutoffTime = now()->subHours($hours);
        
        $query = DB::connection('mysql')
            ->table('backalerts')
            ->where('receivedtime', '<', $cutoffTime);

        if ($syncOnly) {
            $query->whereNotNull('synced_at');
        }

        $oldRecords = $query->count();
        $totalRecords = DB::connection('mysql')->table('backalerts')->count();

        return [
            'old_records' => $oldRecords,
            'total_records' => $totalRecords,
            'cutoff_time' => $cutoffTime
        ];
    }
}