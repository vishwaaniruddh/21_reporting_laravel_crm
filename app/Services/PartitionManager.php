<?php

namespace App\Services;

use App\Models\PartitionRegistry;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

/**
 * PartitionManager
 * 
 * Manages date-partitioned alert tables in PostgreSQL.
 * This service is responsible for:
 * - Checking if partition tables exist
 * - Creating partition tables with base alerts schema
 * - Creating all necessary indexes on new partitions
 * - Registering new partitions in partition_registry
 * - Ensuring schema consistency across all partitions
 * 
 * Requirements: 2.1, 2.2, 2.3, 2.4, 3.1, 3.2, 3.3, 1.5
 */
class PartitionManager
{
    /**
     * DateExtractor service for partition naming
     */
    private DateExtractor $dateExtractor;
    
    /**
     * PostgreSQL connection name
     */
    private string $connection = 'pgsql';
    
    /**
     * Maximum retry attempts for partition creation
     */
    private int $maxRetries = 3;
    
    /**
     * Base alerts table schema template
     * Defines the structure that will be replicated across all partitions
     */
    private array $schemaTemplate;
    
    /**
     * Index definitions for partition tables
     */
    private array $indexDefinitions;
    
    /**
     * Create a new PartitionManager instance
     * 
     * @param DateExtractor|null $dateExtractor Optional DateExtractor instance
     */
    public function __construct(?DateExtractor $dateExtractor = null)
    {
        $this->dateExtractor = $dateExtractor ?? new DateExtractor();
        $this->initializeSchemaTemplate();
        $this->initializeIndexDefinitions();
    }
    
    /**
     * Initialize the base alerts table schema template
     * 
     * This defines the exact structure that will be replicated across all partition tables.
     * The schema mirrors the MySQL alerts table structure.
     * 
     * Requirements: 1.5, 3.1, 3.2
     */
    private function initializeSchemaTemplate(): void
    {
        $this->schemaTemplate = [
            // Primary key - ID from MySQL (not auto-generated)
            ['name' => 'id', 'type' => 'BIGINT', 'primary' => true, 'nullable' => false],
            
            // Mirror MySQL alerts table structure - all as strings to handle mixed data
            ['name' => 'panelid', 'type' => 'VARCHAR(50)', 'nullable' => true],
            ['name' => 'seqno', 'type' => 'VARCHAR(50)', 'nullable' => true],
            ['name' => 'zone', 'type' => 'VARCHAR(50)', 'nullable' => true],
            ['name' => 'alarm', 'type' => 'VARCHAR(255)', 'nullable' => true],
            ['name' => 'createtime', 'type' => 'TIMESTAMP', 'nullable' => true],
            ['name' => 'receivedtime', 'type' => 'TIMESTAMP', 'nullable' => true],
            ['name' => 'comment', 'type' => 'TEXT', 'nullable' => true],
            ['name' => 'status', 'type' => 'VARCHAR(50)', 'nullable' => true],
            ['name' => 'sendtoclient', 'type' => 'VARCHAR(50)', 'nullable' => true],
            ['name' => 'closedBy', 'type' => 'VARCHAR(100)', 'nullable' => true],
            ['name' => 'closedtime', 'type' => 'TIMESTAMP', 'nullable' => true],
            ['name' => 'sendip', 'type' => 'VARCHAR(50)', 'nullable' => true],
            ['name' => 'alerttype', 'type' => 'VARCHAR(100)', 'nullable' => true],
            ['name' => 'location', 'type' => 'VARCHAR(255)', 'nullable' => true],
            ['name' => 'priority', 'type' => 'VARCHAR(50)', 'nullable' => true],
            ['name' => 'AlertUserStatus', 'type' => 'VARCHAR(50)', 'nullable' => true],
            ['name' => 'level', 'type' => 'VARCHAR(50)', 'nullable' => true],
            ['name' => 'sip2', 'type' => 'VARCHAR(100)', 'nullable' => true],
            ['name' => 'c_status', 'type' => 'VARCHAR(50)', 'nullable' => true],
            ['name' => 'auto_alert', 'type' => 'VARCHAR(50)', 'nullable' => true],
            ['name' => 'critical_alerts', 'type' => 'VARCHAR(50)', 'nullable' => true],
            ['name' => 'Readstatus', 'type' => 'VARCHAR(50)', 'nullable' => true],
            
            // Sync metadata
            ['name' => 'synced_at', 'type' => 'TIMESTAMP', 'nullable' => false, 'default' => 'CURRENT_TIMESTAMP'],
            ['name' => 'sync_batch_id', 'type' => 'BIGINT', 'nullable' => false],
        ];
    }
    
    /**
     * Initialize index definitions for partition tables
     * 
     * These indexes will be created on every partition table to ensure
     * consistent query performance across all partitions.
     * 
     * Requirements: 2.3, 3.3
     */
    private function initializeIndexDefinitions(): void
    {
        $this->indexDefinitions = [
            ['columns' => ['panelid'], 'name_suffix' => 'panelid'],
            ['columns' => ['alerttype'], 'name_suffix' => 'alerttype'],
            ['columns' => ['priority'], 'name_suffix' => 'priority'],
            ['columns' => ['createtime'], 'name_suffix' => 'createtime'],
            ['columns' => ['synced_at'], 'name_suffix' => 'synced_at'],
            ['columns' => ['sync_batch_id'], 'name_suffix' => 'sync_batch_id'],
        ];
    }
    
    /**
     * Ensure a partition table exists for the given date
     * 
     * Checks if the partition table exists, and creates it if it doesn't.
     * This method is idempotent - calling it multiple times for the same date
     * will not create duplicate tables.
     * 
     * Implements retry logic with up to 3 attempts on failure.
     * 
     * Requirements: 2.1, 2.2, 2.5, 8.1
     * 
     * @param Carbon $date The date for the partition
     * @return bool True if partition exists or was created successfully
     * @throws Exception If partition creation fails after max retries
     */
    public function ensurePartitionExists(Carbon $date): bool
    {
        $tableName = $this->getPartitionTableName($date);
        
        // Check if partition already exists
        if ($this->partitionTableExists($tableName)) {
            // Log::debug('Partition table already exists', [
            //     'table_name' => $tableName,
            //     'date' => $date->toDateString()
            // ]);
            return true;
        }
        
        // Create the partition table with retry logic
        return $this->createPartitionWithRetry($date);
    }
    
    /**
     * Create a partition table with retry logic
     * 
     * Attempts to create the partition table up to maxRetries times.
     * Logs each attempt and error details.
     * 
     * Requirements: 2.5, 8.1, 8.3
     * 
     * @param Carbon $date The date for the partition
     * @return bool True if partition was created successfully
     * @throws Exception If partition creation fails after max retries
     */
    private function createPartitionWithRetry(Carbon $date): bool
    {
        $tableName = $this->getPartitionTableName($date);
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                Log::info('Attempting to create partition', [
                    'table_name' => $tableName,
                    'date' => $date->toDateString(),
                    'attempt' => $attempt,
                    'max_retries' => $this->maxRetries
                ]);
                
                $result = $this->createPartition($date);
                
                if ($result) {
                    if ($attempt > 1) {
                        Log::info('Partition created successfully after retry', [
                            'table_name' => $tableName,
                            'date' => $date->toDateString(),
                            'attempt' => $attempt
                        ]);
                    }
                    return true;
                }
                
            } catch (Exception $e) {
                $lastException = $e;
                
                Log::error('Partition creation attempt failed', [
                    'table_name' => $tableName,
                    'date' => $date->toDateString(),
                    'attempt' => $attempt,
                    'max_retries' => $this->maxRetries,
                    'error' => $e->getMessage(),
                    'error_code' => $e->getCode(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // If this was the last attempt, throw the exception
                if ($attempt >= $this->maxRetries) {
                    Log::critical('Partition creation failed after max retries', [
                        'table_name' => $tableName,
                        'date' => $date->toDateString(),
                        'attempts' => $attempt,
                        'final_error' => $e->getMessage()
                    ]);
                    
                    throw new Exception(
                        "Failed to create partition table '{$tableName}' after {$this->maxRetries} attempts: {$e->getMessage()}",
                        0,
                        $e
                    );
                }
                
                // Wait before retrying (exponential backoff: 1s, 2s, 4s)
                $waitTime = pow(2, $attempt - 1);
                Log::info('Waiting before retry', [
                    'table_name' => $tableName,
                    'wait_seconds' => $waitTime
                ]);
                sleep($waitTime);
            }
        }
        
        // This should never be reached, but just in case
        throw new Exception(
            "Failed to create partition table '{$tableName}' after {$this->maxRetries} attempts",
            0,
            $lastException
        );
    }
    
    /**
     * Create a new partition table for the given date
     * 
     * Creates the partition table with the base alerts schema,
     * creates all necessary indexes, and registers it in the partition_registry.
     * 
     * Requirements: 2.1, 2.2, 2.3, 2.4, 3.1, 3.2, 3.3
     * 
     * @param Carbon $date The date for the partition
     * @return bool True if partition was created successfully
     * @throws Exception If partition creation fails
     */
    public function createPartition(Carbon $date): bool
    {
        $tableName = $this->getPartitionTableName($date);
        
        try {
            DB::connection($this->connection)->transaction(function () use ($tableName, $date) {
                // Generate and execute CREATE TABLE statement
                $createTableSql = $this->generateCreateTableStatement($tableName);
                DB::connection($this->connection)->statement($createTableSql);
                
                Log::info('Created partition table', [
                    'table_name' => $tableName,
                    'date' => $date->toDateString()
                ]);
                
                // Create indexes on the new partition
                $this->createPartitionIndexes($tableName);
                
                // Register the partition in the registry
                PartitionRegistry::registerPartition($tableName, $date);
                
                Log::info('Registered partition in registry', [
                    'table_name' => $tableName,
                    'date' => $date->toDateString()
                ]);
            });
            
            return true;
            
        } catch (Exception $e) {
            Log::error('Failed to create partition table', [
                'table_name' => $tableName,
                'date' => $date->toDateString(),
                'error' => $e->getMessage()
            ]);
            
            throw new Exception(
                "Failed to create partition table '{$tableName}': {$e->getMessage()}",
                0,
                $e
            );
        }
    }
    
    /**
     * Check if a partition table exists in PostgreSQL
     * 
     * @param string $tableName The partition table name
     * @return bool True if the table exists
     */
    public function partitionTableExists(string $tableName): bool
    {
        try {
            $result = DB::connection($this->connection)
                ->select(
                    "SELECT EXISTS (
                        SELECT FROM information_schema.tables 
                        WHERE table_schema = 'public' 
                        AND table_name = ?
                    ) as exists",
                    [$tableName]
                );
            
            return $result[0]->exists ?? false;
            
        } catch (Exception $e) {
            Log::error('Error checking if partition table exists', [
                'table_name' => $tableName,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    /**
     * Get the actual record count from a partition table
     * 
     * @param string $tableName The partition table name
     * @return int The number of records in the table
     * @throws Exception If the table doesn't exist or query fails
     */
    public function getPartitionRecordCount(string $tableName): int
    {
        try {
            $result = DB::connection($this->connection)
                ->select("SELECT COUNT(*) as count FROM {$tableName}");
            
            return (int) ($result[0]->count ?? 0);
            
        } catch (Exception $e) {
            Log::error('Error getting partition record count', [
                'table_name' => $tableName,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception("Failed to get record count for partition {$tableName}: {$e->getMessage()}");
        }
    }
    
    /**
     * Get the partition table name for a given date and table prefix
     * 
     * @param Carbon $date The date
     * @param string $tablePrefix The table prefix (default: 'alerts')
     * @return string The partition table name (e.g., "alerts_2026_01_08" or "backalerts_2026_01_08")
     */
    public function getPartitionTableName(Carbon $date, string $tablePrefix = 'alerts'): string
    {
        return $this->dateExtractor->formatPartitionName($date, $tablePrefix);
    }
    
    /**
     * List all partition tables
     * 
     * @return Collection Collection of PartitionRegistry models
     */
    public function listPartitions(): Collection
    {
        return PartitionRegistry::getAllPartitions();
    }
    
    /**
     * Get the partition schema template
     * 
     * @return array The schema template array
     */
    public function getPartitionSchema(): array
    {
        return $this->schemaTemplate;
    }
    
    /**
     * Generate CREATE TABLE statement for a partition
     * 
     * Dynamically generates a CREATE TABLE statement based on the schema template.
     * This ensures all partition tables have identical structure.
     * 
     * Requirements: 3.1, 3.2
     * 
     * @param string $tableName The partition table name
     * @return string The CREATE TABLE SQL statement
     */
    private function generateCreateTableStatement(string $tableName): string
    {
        $columns = [];
        $primaryKey = null;
        
        foreach ($this->schemaTemplate as $column) {
            $columnDef = $this->formatColumnDefinition($column);
            $columns[] = $columnDef;
            
            if (isset($column['primary']) && $column['primary']) {
                $primaryKey = $column['name'];
            }
        }
        
        $columnsSql = implode(",\n    ", $columns);
        
        if ($primaryKey) {
            $columnsSql .= ",\n    PRIMARY KEY (\"" . $primaryKey . "\")";
        }
        
        $sql = "CREATE TABLE {$tableName} (\n    {$columnsSql}\n)";
        
        return $sql;
    }
    
    /**
     * Format a column definition for SQL
     * 
     * @param array $column The column definition array
     * @return string The formatted column definition
     */
    private function formatColumnDefinition(array $column): string
    {
        // Quote column name to preserve case in PostgreSQL
        $def = '"' . $column['name'] . '" ' . $column['type'];
        
        // Add NOT NULL constraint
        if (isset($column['nullable']) && !$column['nullable']) {
            $def .= ' NOT NULL';
        }
        
        // Add DEFAULT value
        if (isset($column['default'])) {
            $def .= ' DEFAULT ' . $column['default'];
        }
        
        return $def;
    }
    
    /**
     * Create indexes on a partition table
     * 
     * Creates all indexes defined in the index definitions array.
     * This ensures consistent query performance across all partitions.
     * 
     * Requirements: 2.3, 3.3
     * 
     * @param string $tableName The partition table name
     * @return void
     * @throws Exception If index creation fails
     */
    private function createPartitionIndexes(string $tableName): void
    {
        foreach ($this->indexDefinitions as $indexDef) {
            $indexSql = $this->generateCreateIndexStatement($tableName, $indexDef);
            
            try {
                DB::connection($this->connection)->statement($indexSql);
                
                Log::debug('Created index on partition', [
                    'table_name' => $tableName,
                    'columns' => implode(', ', $indexDef['columns'])
                ]);
                
            } catch (Exception $e) {
                Log::error('Failed to create index on partition', [
                    'table_name' => $tableName,
                    'columns' => implode(', ', $indexDef['columns']),
                    'error' => $e->getMessage()
                ]);
                
                throw $e;
            }
        }
    }
    
    /**
     * Generate CREATE INDEX statement for a partition
     * 
     * @param string $tableName The partition table name
     * @param array $indexDef The index definition
     * @return string The CREATE INDEX SQL statement
     */
    private function generateCreateIndexStatement(string $tableName, array $indexDef): string
    {
        $indexName = "idx_{$tableName}_{$indexDef['name_suffix']}";
        $columns = implode(', ', $indexDef['columns']);
        
        return "CREATE INDEX {$indexName} ON {$tableName} ({$columns})";
    }
    
    /**
     * Validate schema consistency across partitions
     * 
     * Checks that all partition tables have the same schema structure.
     * This is useful for maintenance and debugging.
     * 
     * Requirements: 3.1, 3.2, 3.3
     * 
     * @param array $tableNames Optional array of table names to check (defaults to all partitions)
     * @return bool True if all partitions have consistent schema
     */
    public function validateSchemaConsistency(?array $tableNames = null): bool
    {
        if ($tableNames === null) {
            $partitions = $this->listPartitions();
            $tableNames = $partitions->pluck('table_name')->toArray();
        }
        
        if (empty($tableNames)) {
            return true; // No partitions to validate
        }
        
        $referenceSchema = null;
        
        foreach ($tableNames as $tableName) {
            if (!$this->partitionTableExists($tableName)) {
                Log::warning('Partition table does not exist during schema validation', [
                    'table_name' => $tableName
                ]);
                continue;
            }
            
            $schema = $this->getTableSchema($tableName);
            
            if ($referenceSchema === null) {
                $referenceSchema = $schema;
            } else {
                if ($schema !== $referenceSchema) {
                    Log::error('Schema inconsistency detected', [
                        'table_name' => $tableName,
                        'expected_columns' => count($referenceSchema),
                        'actual_columns' => count($schema)
                    ]);
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * Get the schema of a table
     * 
     * @param string $tableName The table name
     * @return array Array of column definitions
     */
    private function getTableSchema(string $tableName): array
    {
        try {
            $columns = DB::connection($this->connection)
                ->select(
                    "SELECT column_name, data_type, is_nullable, column_default
                     FROM information_schema.columns
                     WHERE table_schema = 'public' AND table_name = ?
                     ORDER BY ordinal_position",
                    [$tableName]
                );
            
            return array_map(function ($column) {
                return [
                    'name' => $column->column_name,
                    'type' => $column->data_type,
                    'nullable' => $column->is_nullable === 'YES',
                    'default' => $column->column_default,
                ];
            }, $columns);
            
        } catch (Exception $e) {
            Log::error('Failed to get table schema', [
                'table_name' => $tableName,
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }
    
    /**
     * Get partition info from registry
     * 
     * @param string $tableName The partition table name
     * @return PartitionRegistry|null
     */
    public function getPartitionInfo(string $tableName): ?PartitionRegistry
    {
        return PartitionRegistry::getPartitionInfo($tableName);
    }
    
    /**
     * Get partition by date
     * 
     * @param Carbon $date The date
     * @return PartitionRegistry|null
     */
    public function getPartitionByDate(Carbon $date): ?PartitionRegistry
    {
        return PartitionRegistry::getPartitionByDate($date);
    }
    
    /**
     * Get partitions in a date range for a specific table prefix
     * 
     * @param Carbon $startDate Start date
     * @param Carbon $endDate End date
     * @param string $tablePrefix Table prefix (e.g., 'alerts', 'backalerts')
     * @return Collection
     */
    public function getPartitionsInRange(Carbon $startDate, Carbon $endDate, string $tablePrefix = 'alerts'): Collection
    {
        // Generate expected partition table names for the date range
        $expectedPartitions = collect();
        $currentDate = $startDate->copy();
        
        while ($currentDate->lte($endDate)) {
            $tableName = $this->dateExtractor->formatPartitionName($currentDate, $tablePrefix);
            
            // Check if this partition table actually exists
            if ($this->partitionTableExists($tableName)) {
                $expectedPartitions->push((object) [
                    'table_name' => $tableName,
                    'partition_date' => $currentDate->toDateString(),
                    'table_prefix' => $tablePrefix
                ]);
            }
            
            $currentDate->addDay();
        }
        
        return $expectedPartitions;
    }
    
    /**
     * Get partitions in a date range (legacy method for backward compatibility)
     * 
     * @param Carbon $startDate Start date
     * @param Carbon $endDate End date
     * @return Collection
     */
    public function getPartitionsInRangeLegacy(Carbon $startDate, Carbon $endDate): Collection
    {
        return PartitionRegistry::getPartitionsInRange($startDate, $endDate);
    }
    
    /**
     * Update record count for a partition
     * 
     * @param string $tableName The partition table name
     * @param int $count The new record count
     * @return bool
     */
    public function updateRecordCount(string $tableName, int $count): bool
    {
        return PartitionRegistry::updateRecordCount($tableName, $count);
    }
    
    /**
     * Increment record count for a partition
     * 
     * @param string $tableName The partition table name
     * @param int $increment The amount to increment by
     * @return bool
     */
    public function incrementRecordCount(string $tableName, int $increment): bool
    {
        return PartitionRegistry::incrementRecordCount($tableName, $increment);
    }
}

