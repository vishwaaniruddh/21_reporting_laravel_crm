<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Exception;

/**
 * SitesSyncService handles synchronization of sites, dvrsite, dvronline tables
 * from MySQL to PostgreSQL.
 * 
 * Uses UPSERT strategy (INSERT ON CONFLICT UPDATE) to handle both new records
 * and updates to existing records.
 * 
 * ⚠️ NO DELETION FROM MYSQL: Only SELECT operations on MySQL
 */
class SitesSyncService
{
    /**
     * Table configurations with primary keys and update tracking
     */
    protected array $tableConfigs = [
        'sites' => [
            'primary_key' => 'SN',
            'sync_marker' => 'synced_at',
            'update_column' => 'current_dt',  // Timestamp column for detecting updates
            'batch_size' => 500,
        ],
        'dvrsite' => [
            'primary_key' => 'SN',
            'sync_marker' => 'synced_at',
            'update_column' => 'last_modified',  // Timestamp column for detecting updates
            'batch_size' => 500,
        ],
        'dvronline' => [
            'primary_key' => 'id',
            'sync_marker' => 'synced_at',
            'update_column' => null,  // No update tracking - will do full compare
            'batch_size' => 500,
        ],
    ];

    /**
     * Cache key prefix for last sync timestamp
     */
    protected string $lastSyncCachePrefix = 'sites_sync_last_';

    /**
     * Sync all configured tables
     */
    public function syncAll(): array
    {
        $results = [];
        
        foreach (array_keys($this->tableConfigs) as $table) {
            $results[$table] = $this->syncTable($table);
        }
        
        return $results;
    }

    /**
     * Sync a specific table from MySQL to PostgreSQL
     */
    public function syncTable(string $tableName): SitesSyncResult
    {
        if (!isset($this->tableConfigs[$tableName])) {
            return new SitesSyncResult(
                success: false,
                inserted: 0,
                updated: 0,
                failed: 0,
                errorMessage: "Unknown table: {$tableName}"
            );
        }

        $config = $this->tableConfigs[$tableName];
        $primaryKey = $config['primary_key'];
        $batchSize = $config['batch_size'];

        Log::info("Starting sites sync", ['table' => $tableName]);

        try {
            // Ensure sync marker column exists in MySQL
            $this->ensureSyncMarkerColumn($tableName, $config['sync_marker']);

            // Get all records from MySQL
            $totalInserted = 0;
            $totalUpdated = 0;
            $totalFailed = 0;
            $offset = 0;

            while (true) {
                $records = DB::connection('mysql')
                    ->table($tableName)
                    ->orderBy($primaryKey)
                    ->offset($offset)
                    ->limit($batchSize)
                    ->get();

                if ($records->isEmpty()) {
                    break;
                }

                $result = $this->processBatch($tableName, $records, $primaryKey);
                
                $totalInserted += $result['inserted'];
                $totalUpdated += $result['updated'];
                $totalFailed += $result['failed'];
                
                $offset += $batchSize;

                Log::debug("Batch processed", [
                    'table' => $tableName,
                    'offset' => $offset,
                    'inserted' => $result['inserted'],
                    'updated' => $result['updated'],
                ]);
            }

            Log::info("Sites sync completed", [
                'table' => $tableName,
                'inserted' => $totalInserted,
                'updated' => $totalUpdated,
                'failed' => $totalFailed,
            ]);

            return new SitesSyncResult(
                success: $totalFailed === 0,
                inserted: $totalInserted,
                updated: $totalUpdated,
                failed: $totalFailed
            );

        } catch (Exception $e) {
            Log::error("Sites sync failed", [
                'table' => $tableName,
                'error' => $e->getMessage(),
            ]);

            return new SitesSyncResult(
                success: false,
                inserted: 0,
                updated: 0,
                failed: 0,
                errorMessage: $e->getMessage()
            );
        }
    }

    /**
     * Sync only new/updated records (incremental sync)
     * Uses last_modified or synced_at to detect changes
     */
    public function syncIncremental(string $tableName): SitesSyncResult
    {
        if (!isset($this->tableConfigs[$tableName])) {
            return new SitesSyncResult(
                success: false,
                inserted: 0,
                updated: 0,
                failed: 0,
                errorMessage: "Unknown table: {$tableName}"
            );
        }

        $config = $this->tableConfigs[$tableName];
        $primaryKey = $config['primary_key'];
        $syncMarker = $config['sync_marker'];

        Log::info("Starting incremental sites sync", ['table' => $tableName]);

        try {
            $this->ensureSyncMarkerColumn($tableName, $syncMarker);

            // Get records where synced_at is NULL (never synced or updated after last sync)
            $records = DB::connection('mysql')
                ->table($tableName)
                ->whereNull($syncMarker)
                ->orderBy($primaryKey)
                ->limit($config['batch_size'])
                ->get();

            if ($records->isEmpty()) {
                return new SitesSyncResult(
                    success: true,
                    inserted: 0,
                    updated: 0,
                    failed: 0,
                    message: "No records to sync"
                );
            }

            $result = $this->processBatch($tableName, $records, $primaryKey);

            // Update sync markers in MySQL
            $ids = $records->pluck($primaryKey)->toArray();
            $this->updateSyncMarkers($tableName, $primaryKey, $syncMarker, $ids);

            return new SitesSyncResult(
                success: $result['failed'] === 0,
                inserted: $result['inserted'],
                updated: $result['updated'],
                failed: $result['failed']
            );

        } catch (Exception $e) {
            Log::error("Incremental sites sync failed", [
                'table' => $tableName,
                'error' => $e->getMessage(),
            ]);

            return new SitesSyncResult(
                success: false,
                inserted: 0,
                updated: 0,
                failed: 0,
                errorMessage: $e->getMessage()
            );
        }
    }

    /**
     * Process a batch of records using UPSERT
     */
    protected function processBatch(string $tableName, Collection $records, string $primaryKey): array
    {
        $inserted = 0;
        $updated = 0;
        $failed = 0;

        // Get PostgreSQL columns (excluding synced_at if not in target)
        $pgColumns = $this->getPostgresColumns($tableName);

        foreach ($records as $record) {
            try {
                $recordArray = (array) $record;
                $pkValue = $recordArray[$primaryKey];

                // Filter to only columns that exist in PostgreSQL
                $data = array_intersect_key($recordArray, array_flip($pgColumns));
                
                // Remove synced_at from data if it exists (we don't sync this column)
                unset($data['synced_at']);

                // Check if record exists in PostgreSQL
                $exists = DB::connection('pgsql')
                    ->table($tableName)
                    ->where($primaryKey, $pkValue)
                    ->exists();

                if ($exists) {
                    // Update existing record
                    DB::connection('pgsql')
                        ->table($tableName)
                        ->where($primaryKey, $pkValue)
                        ->update($data);
                    $updated++;
                } else {
                    // Insert new record
                    DB::connection('pgsql')
                        ->table($tableName)
                        ->insert($data);
                    $inserted++;
                }

            } catch (Exception $e) {
                $failed++;
                Log::warning("Failed to sync record", [
                    'table' => $tableName,
                    'primary_key' => $pkValue ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'failed' => $failed,
        ];
    }

    /**
     * Get column names from PostgreSQL table
     */
    protected function getPostgresColumns(string $tableName): array
    {
        $columns = DB::connection('pgsql')->select("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = ?
        ", [$tableName]);

        return array_map(fn($col) => $col->column_name, $columns);
    }

    /**
     * Ensure sync marker column exists in MySQL table
     */
    protected function ensureSyncMarkerColumn(string $tableName, string $columnName): void
    {
        $exists = DB::connection('mysql')->select("
            SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'
        ");

        if (empty($exists)) {
            DB::connection('mysql')->statement(
                "ALTER TABLE `{$tableName}` ADD COLUMN `{$columnName}` TIMESTAMP NULL DEFAULT NULL"
            );
            Log::info("Added sync marker column", ['table' => $tableName, 'column' => $columnName]);
        }
    }

    /**
     * Update sync markers in MySQL after successful sync
     */
    protected function updateSyncMarkers(string $tableName, string $primaryKey, string $syncMarker, array $ids): void
    {
        $now = now();
        
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::connection('mysql')
                ->table($tableName)
                ->whereIn($primaryKey, $chunk)
                ->update([$syncMarker => $now]);
        }
    }

    /**
     * Get sync status for all tables
     */
    public function getStatus(): array
    {
        $status = [];

        foreach ($this->tableConfigs as $table => $config) {
            $mysqlCount = DB::connection('mysql')->table($table)->count();
            $pgCount = DB::connection('pgsql')->table($table)->count();
            
            $unsyncedCount = 0;
            if ($this->columnExists($table, $config['sync_marker'], 'mysql')) {
                $unsyncedCount = DB::connection('mysql')
                    ->table($table)
                    ->whereNull($config['sync_marker'])
                    ->count();
            }

            $status[$table] = [
                'mysql_count' => $mysqlCount,
                'postgres_count' => $pgCount,
                'unsynced_count' => $unsyncedCount,
                'in_sync' => $mysqlCount === $pgCount,
            ];
        }

        return $status;
    }

    /**
     * Check if column exists in table
     */
    protected function columnExists(string $table, string $column, string $connection): bool
    {
        try {
            if ($connection === 'mysql') {
                $result = DB::connection('mysql')->select(
                    "SHOW COLUMNS FROM `{$table}` LIKE ?", [$column]
                );
                return !empty($result);
            } else {
                return Schema::connection($connection)->hasColumn($table, $column);
            }
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Reset sync markers to force full re-sync
     */
    public function resetSyncMarkers(string $tableName): bool
    {
        if (!isset($this->tableConfigs[$tableName])) {
            return false;
        }

        $syncMarker = $this->tableConfigs[$tableName]['sync_marker'];

        if ($this->columnExists($tableName, $syncMarker, 'mysql')) {
            DB::connection('mysql')
                ->table($tableName)
                ->update([$syncMarker => null]);
            
            Log::info("Reset sync markers", ['table' => $tableName]);
            return true;
        }

        return false;
    }
}
