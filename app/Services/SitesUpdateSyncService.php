<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Exception;

/**
 * SitesUpdateSyncService processes entries from sites_pg_update_log
 * and syncs changes to PostgreSQL.
 * 
 * Similar to AlertSyncService but for sites/dvrsite/dvronline tables.
 * 
 * ⚠️ NO DELETION FROM MYSQL: Only SELECT operations on MySQL source tables
 */
class SitesUpdateSyncService
{
    /**
     * Table configurations
     */
    protected array $tableConfigs = [
        'sites' => [
            'primary_key' => 'SN',
        ],
        'dvrsite' => [
            'primary_key' => 'SN',
        ],
        'dvronline' => [
            'primary_key' => 'id',
        ],
    ];

    protected int $maxRetries;

    public function __construct()
    {
        $this->maxRetries = config('sites-sync.max_retries', 3);
    }

    /**
     * Fetch pending log entries from MySQL
     */
    public function fetchPendingEntries(int $limit = 100): Collection
    {
        return DB::connection('mysql')
            ->table('sites_pg_update_log')
            ->where('status', 1) // 1 = pending
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Sync a single record based on log entry
     */
    public function syncRecord(int $logId, string $tableName, int $recordId): SitesSyncResult
    {
        if (!isset($this->tableConfigs[$tableName])) {
            return new SitesSyncResult(
                success: false,
                inserted: 0,
                updated: 0,
                failed: 1,
                errorMessage: "Unknown table: {$tableName}"
            );
        }

        $primaryKey = $this->tableConfigs[$tableName]['primary_key'];

        try {
            // Fetch record from MySQL
            $record = DB::connection('mysql')
                ->table($tableName)
                ->where($primaryKey, $recordId)
                ->first();

            if (!$record) {
                // Record was deleted from MySQL - mark log as completed
                $this->markLogCompleted($logId);
                
                return new SitesSyncResult(
                    success: true,
                    inserted: 0,
                    updated: 0,
                    failed: 0,
                    message: "Record not found in MySQL (may have been deleted)"
                );
            }

            // Convert to array and get PostgreSQL columns
            $recordArray = (array) $record;
            $pgColumns = $this->getPostgresColumns($tableName);
            
            // Filter to only columns that exist in PostgreSQL
            $data = array_intersect_key($recordArray, array_flip($pgColumns));
            
            // Remove sync tracking columns
            unset($data['synced_at']);

            // Check if record exists in PostgreSQL
            $exists = DB::connection('pgsql')
                ->table($tableName)
                ->where($primaryKey, $recordId)
                ->exists();

            if ($exists) {
                // Update existing record
                DB::connection('pgsql')
                    ->table($tableName)
                    ->where($primaryKey, $recordId)
                    ->update($data);
                
                $this->markLogCompleted($logId);
                
                return new SitesSyncResult(
                    success: true,
                    inserted: 0,
                    updated: 1,
                    failed: 0
                );
            } else {
                // Insert new record
                DB::connection('pgsql')
                    ->table($tableName)
                    ->insert($data);
                
                $this->markLogCompleted($logId);
                
                return new SitesSyncResult(
                    success: true,
                    inserted: 1,
                    updated: 0,
                    failed: 0
                );
            }

        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            
            // Increment retry count
            $retryCount = $this->incrementRetryCount($logId);
            
            if ($retryCount >= $this->maxRetries) {
                // Max retries reached - mark as failed
                $this->markLogFailed($logId, $errorMessage);
            }
            
            Log::error("Sites update sync failed", [
                'log_id' => $logId,
                'table' => $tableName,
                'record_id' => $recordId,
                'retry_count' => $retryCount,
                'error' => $errorMessage,
            ]);

            return new SitesSyncResult(
                success: false,
                inserted: 0,
                updated: 0,
                failed: 1,
                errorMessage: $errorMessage
            );
        }
    }

    /**
     * Process a batch of log entries
     */
    public function processBatch(Collection $entries): array
    {
        $totalInserted = 0;
        $totalUpdated = 0;
        $totalFailed = 0;

        foreach ($entries as $entry) {
            $result = $this->syncRecord(
                $entry->id,
                $entry->table_name,
                $entry->record_id
            );

            $totalInserted += $result->inserted;
            $totalUpdated += $result->updated;
            $totalFailed += $result->failed;
        }

        return [
            'inserted' => $totalInserted,
            'updated' => $totalUpdated,
            'failed' => $totalFailed,
        ];
    }

    /**
     * Get PostgreSQL column names
     */
    protected function getPostgresColumns(string $tableName): array
    {
        static $cache = [];
        
        if (!isset($cache[$tableName])) {
            $columns = DB::connection('pgsql')->select("
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_name = ?
            ", [$tableName]);

            $cache[$tableName] = array_map(fn($col) => $col->column_name, $columns);
        }

        return $cache[$tableName];
    }

    /**
     * Mark log entry as completed
     */
    protected function markLogCompleted(int $logId): void
    {
        DB::connection('mysql')
            ->table('sites_pg_update_log')
            ->where('id', $logId)
            ->update([
                'status' => 2, // 2 = completed
                'updated_at' => now(),
            ]);
    }

    /**
     * Mark log entry as failed
     */
    protected function markLogFailed(int $logId, string $errorMessage): void
    {
        DB::connection('mysql')
            ->table('sites_pg_update_log')
            ->where('id', $logId)
            ->update([
                'status' => 3, // 3 = failed
                'error_message' => substr($errorMessage, 0, 1000),
                'updated_at' => now(),
            ]);
    }

    /**
     * Increment retry count and return new count
     */
    protected function incrementRetryCount(int $logId): int
    {
        DB::connection('mysql')
            ->table('sites_pg_update_log')
            ->where('id', $logId)
            ->increment('retry_count');

        $entry = DB::connection('mysql')
            ->table('sites_pg_update_log')
            ->where('id', $logId)
            ->first();

        return $entry->retry_count ?? 0;
    }

    /**
     * Get pending count
     */
    public function getPendingCount(): int
    {
        return DB::connection('mysql')
            ->table('sites_pg_update_log')
            ->where('status', 1)
            ->count();
    }

    /**
     * Get status summary
     */
    public function getStatusSummary(): array
    {
        $summary = DB::connection('mysql')
            ->table('sites_pg_update_log')
            ->selectRaw('
                status,
                COUNT(*) as count,
                MIN(created_at) as oldest,
                MAX(created_at) as newest
            ')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return [
            'pending' => $summary->get(1)?->count ?? 0,
            'completed' => $summary->get(2)?->count ?? 0,
            'failed' => $summary->get(3)?->count ?? 0,
            'oldest_pending' => $summary->get(1)?->oldest,
        ];
    }

    /**
     * Set max retries
     */
    public function setMaxRetries(int $maxRetries): void
    {
        $this->maxRetries = $maxRetries;
    }
}
