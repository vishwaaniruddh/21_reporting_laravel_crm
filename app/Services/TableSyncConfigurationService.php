<?php

namespace App\Services;

use App\Models\TableSyncConfiguration;
use App\Models\TableSyncLog;
use App\Models\TableSyncError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Collection;

/**
 * TableSyncConfigurationService manages table sync configurations.
 * 
 * Provides CRUD operations for table sync configurations stored in PostgreSQL.
 * Includes validation of source tables, column mappings, and sync settings.
 * 
 * ⚠️ NO DELETION FROM MYSQL: Config CRUD operates on PostgreSQL only.
 * Validation only reads MySQL schema, never modifies or deletes data.
 * 
 * Requirements: 1.2, 8.1, 8.6
 */
class TableSyncConfigurationService
{
    protected SchemaDetectorService $schemaDetector;

    /**
     * Minimum and maximum batch size constraints
     */
    const MIN_BATCH_SIZE = 100;
    const MAX_BATCH_SIZE = 100000;
    const DEFAULT_BATCH_SIZE = 10000;

    public function __construct(SchemaDetectorService $schemaDetector)
    {
        $this->schemaDetector = $schemaDetector;
    }

    /**
     * Get all table sync configurations.
     * 
     * @param bool $enabledOnly If true, only return enabled configurations
     * @return Collection
     */
    public function getAll(bool $enabledOnly = false): Collection
    {
        $query = TableSyncConfiguration::query();
        
        if ($enabledOnly) {
            $query->enabled();
        }
        
        return $query->orderBy('name')->get();
    }

    /**
     * Get a specific configuration by ID.
     * 
     * @param int $id Configuration ID
     * @return TableSyncConfiguration|null
     */
    public function getById(int $id): ?TableSyncConfiguration
    {
        return TableSyncConfiguration::find($id);
    }

    /**
     * Get a configuration by source table name.
     * 
     * @param string $sourceTable Source table name
     * @return TableSyncConfiguration|null
     */
    public function getBySourceTable(string $sourceTable): ?TableSyncConfiguration
    {
        return TableSyncConfiguration::forSourceTable($sourceTable)->first();
    }


    /**
     * Create a new table sync configuration.
     * 
     * @param array $data Configuration data
     * @return TableSyncConfiguration
     * @throws ValidationException If validation fails
     */
    public function create(array $data): TableSyncConfiguration
    {
        // Validate the input data
        $validated = $this->validate($data);

        // Validate source table exists and column mappings are valid
        $this->validateSourceTable($validated['source_table']);
        
        if (!empty($validated['column_mappings'])) {
            $this->validateColumnMappings(
                $validated['source_table'],
                $validated['column_mappings']
            );
        }

        if (!empty($validated['excluded_columns'])) {
            $this->validateExcludedColumns(
                $validated['source_table'],
                $validated['excluded_columns']
            );
        }

        // Set default target table if not provided
        if (empty($validated['target_table'])) {
            $validated['target_table'] = $validated['source_table'];
        }

        // Create the configuration
        $configuration = TableSyncConfiguration::create($validated);

        Log::info('Table sync configuration created', [
            'id' => $configuration->id,
            'name' => $configuration->name,
            'source_table' => $configuration->source_table,
            'target_table' => $configuration->target_table,
        ]);

        return $configuration;
    }

    /**
     * Update an existing table sync configuration.
     * 
     * @param int $id Configuration ID
     * @param array $data Updated configuration data
     * @return TableSyncConfiguration
     * @throws ValidationException If validation fails
     * @throws \InvalidArgumentException If configuration not found
     */
    public function update(int $id, array $data): TableSyncConfiguration
    {
        $configuration = $this->getById($id);
        
        if (!$configuration) {
            throw new \InvalidArgumentException("Configuration with ID {$id} not found");
        }

        // Validate the input data (for update, some fields may be optional)
        $validated = $this->validate($data, true);

        // If source_table is being changed, validate it exists
        if (isset($validated['source_table']) && $validated['source_table'] !== $configuration->source_table) {
            $this->validateSourceTable($validated['source_table']);
        }

        // Determine which source table to use for column validation
        $sourceTable = $validated['source_table'] ?? $configuration->source_table;

        // Validate column mappings if provided
        if (isset($validated['column_mappings']) && !empty($validated['column_mappings'])) {
            $this->validateColumnMappings($sourceTable, $validated['column_mappings']);
        }

        // Validate excluded columns if provided
        if (isset($validated['excluded_columns']) && !empty($validated['excluded_columns'])) {
            $this->validateExcludedColumns($sourceTable, $validated['excluded_columns']);
        }

        // Update the configuration
        $configuration->update($validated);

        Log::info('Table sync configuration updated', [
            'id' => $configuration->id,
            'name' => $configuration->name,
            'updated_fields' => array_keys($validated),
        ]);

        return $configuration->fresh();
    }


    /**
     * Delete a table sync configuration.
     * 
     * Deletes the configuration from PostgreSQL along with associated logs and errors.
     * ⚠️ NO DELETION FROM MYSQL: Only deletes from PostgreSQL tables.
     * 
     * @param int $id Configuration ID
     * @return bool True if deleted successfully
     * @throws \InvalidArgumentException If configuration not found
     */
    public function delete(int $id): bool
    {
        $configuration = $this->getById($id);
        
        if (!$configuration) {
            throw new \InvalidArgumentException("Configuration with ID {$id} not found");
        }

        $name = $configuration->name;
        $sourceTable = $configuration->source_table;

        // Use transaction to ensure all related data is cleaned up
        DB::connection('pgsql')->transaction(function () use ($configuration) {
            // Delete associated errors first (foreign key constraint)
            TableSyncError::where('configuration_id', $configuration->id)->delete();
            
            // Delete associated logs (foreign key constraint)
            TableSyncLog::where('configuration_id', $configuration->id)->delete();
            
            // Delete the configuration itself
            $configuration->delete();
        });

        Log::info('Table sync configuration deleted', [
            'id' => $id,
            'name' => $name,
            'source_table' => $sourceTable,
        ]);

        return true;
    }

    /**
     * Enable a configuration.
     * 
     * @param int $id Configuration ID
     * @return TableSyncConfiguration
     * @throws \InvalidArgumentException If configuration not found
     */
    public function enable(int $id): TableSyncConfiguration
    {
        $configuration = $this->getById($id);
        
        if (!$configuration) {
            throw new \InvalidArgumentException("Configuration with ID {$id} not found");
        }

        $configuration->update(['is_enabled' => true]);

        Log::info('Table sync configuration enabled', [
            'id' => $id,
            'name' => $configuration->name,
        ]);

        return $configuration->fresh();
    }

    /**
     * Disable a configuration.
     * 
     * @param int $id Configuration ID
     * @return TableSyncConfiguration
     * @throws \InvalidArgumentException If configuration not found
     */
    public function disable(int $id): TableSyncConfiguration
    {
        $configuration = $this->getById($id);
        
        if (!$configuration) {
            throw new \InvalidArgumentException("Configuration with ID {$id} not found");
        }

        $configuration->update(['is_enabled' => false]);

        Log::info('Table sync configuration disabled', [
            'id' => $id,
            'name' => $configuration->name,
        ]);

        return $configuration->fresh();
    }


    /**
     * Validate configuration data.
     * 
     * @param array $data Data to validate
     * @param bool $isUpdate If true, fields are optional (for partial updates)
     * @return array Validated data
     * @throws ValidationException If validation fails
     */
    protected function validate(array $data, bool $isUpdate = false): array
    {
        $requiredPrefix = $isUpdate ? 'nullable|' : 'required|';

        $rules = [
            'name' => $requiredPrefix . 'string|max:255',
            'source_table' => $requiredPrefix . 'string|max:255',
            'target_table' => 'nullable|string|max:255',
            'primary_key_column' => 'nullable|string|max:255',
            'sync_marker_column' => 'nullable|string|max:255',
            'column_mappings' => 'nullable|array',
            'column_mappings.*' => 'string|max:255',
            'excluded_columns' => 'nullable|array',
            'excluded_columns.*' => 'string|max:255',
            'batch_size' => 'nullable|integer|min:' . self::MIN_BATCH_SIZE . '|max:' . self::MAX_BATCH_SIZE,
            'schedule' => 'nullable|string|max:100',
            'is_enabled' => 'nullable|boolean',
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        // Validate schedule is a valid cron expression if provided
        if (!empty($validated['schedule'])) {
            $this->validateCronExpression($validated['schedule']);
        }

        // Validate batch size is within range
        if (isset($validated['batch_size'])) {
            $this->validateBatchSize($validated['batch_size']);
        }

        // Check for unique source_table constraint (only for create or when changing source_table)
        if (!$isUpdate && isset($validated['source_table'])) {
            $this->validateUniqueSourceTable($validated['source_table']);
        }

        return $validated;
    }

    /**
     * Validate that the source table exists in MySQL.
     * ⚠️ READ-ONLY: Only checks if table exists, never modifies MySQL.
     * 
     * @param string $tableName Source table name
     * @throws ValidationException If table does not exist
     */
    protected function validateSourceTable(string $tableName): void
    {
        if (!$this->schemaDetector->tableExists($tableName, 'mysql')) {
            throw ValidationException::withMessages([
                'source_table' => ["Source table '{$tableName}' does not exist in MySQL database"],
            ]);
        }
    }

    /**
     * Validate that column mappings reference valid columns in the source table.
     * ⚠️ READ-ONLY: Only reads schema, never modifies MySQL.
     * 
     * @param string $tableName Source table name
     * @param array $mappings Column mappings (source_column => target_column)
     * @throws ValidationException If any source column does not exist
     */
    protected function validateColumnMappings(string $tableName, array $mappings): void
    {
        if (empty($mappings)) {
            return;
        }

        $sourceColumns = $this->schemaDetector->getColumnNames($tableName, 'mysql');
        $invalidColumns = [];

        foreach (array_keys($mappings) as $sourceColumn) {
            if (!in_array($sourceColumn, $sourceColumns)) {
                $invalidColumns[] = $sourceColumn;
            }
        }

        if (!empty($invalidColumns)) {
            throw ValidationException::withMessages([
                'column_mappings' => [
                    "The following source columns do not exist in table '{$tableName}': " . 
                    implode(', ', $invalidColumns)
                ],
            ]);
        }
    }


    /**
     * Validate that excluded columns exist in the source table.
     * ⚠️ READ-ONLY: Only reads schema, never modifies MySQL.
     * 
     * @param string $tableName Source table name
     * @param array $excludedColumns List of columns to exclude
     * @throws ValidationException If any excluded column does not exist
     */
    protected function validateExcludedColumns(string $tableName, array $excludedColumns): void
    {
        if (empty($excludedColumns)) {
            return;
        }

        $sourceColumns = $this->schemaDetector->getColumnNames($tableName, 'mysql');
        $invalidColumns = [];

        foreach ($excludedColumns as $column) {
            if (!in_array($column, $sourceColumns)) {
                $invalidColumns[] = $column;
            }
        }

        if (!empty($invalidColumns)) {
            throw ValidationException::withMessages([
                'excluded_columns' => [
                    "The following columns do not exist in table '{$tableName}': " . 
                    implode(', ', $invalidColumns)
                ],
            ]);
        }
    }

    /**
     * Validate batch size is within allowed range.
     * 
     * @param int $batchSize Batch size to validate
     * @throws ValidationException If batch size is out of range
     */
    protected function validateBatchSize(int $batchSize): void
    {
        if ($batchSize < self::MIN_BATCH_SIZE || $batchSize > self::MAX_BATCH_SIZE) {
            throw ValidationException::withMessages([
                'batch_size' => [
                    "Batch size must be between " . self::MIN_BATCH_SIZE . 
                    " and " . self::MAX_BATCH_SIZE
                ],
            ]);
        }
    }

    /**
     * Validate cron expression format.
     * 
     * @param string $expression Cron expression to validate
     * @throws ValidationException If cron expression is invalid
     */
    protected function validateCronExpression(string $expression): void
    {
        // Basic validation: should have 5 parts separated by spaces
        $parts = preg_split('/\s+/', trim($expression));
        
        if (count($parts) !== 5) {
            throw ValidationException::withMessages([
                'schedule' => ['Invalid cron expression format. Expected 5 parts (minute hour day month weekday)'],
            ]);
        }

        // Validate each part has valid characters
        $validPattern = '/^[\d\*\/\-\,]+$/';
        
        foreach ($parts as $index => $part) {
            if (!preg_match($validPattern, $part)) {
                $partNames = ['minute', 'hour', 'day of month', 'month', 'day of week'];
                throw ValidationException::withMessages([
                    'schedule' => ["Invalid cron expression: invalid characters in {$partNames[$index]} field"],
                ]);
            }
        }

        // Validate ranges for each part
        $this->validateCronPart($parts[0], 0, 59, 'minute');
        $this->validateCronPart($parts[1], 0, 23, 'hour');
        $this->validateCronPart($parts[2], 1, 31, 'day of month');
        $this->validateCronPart($parts[3], 1, 12, 'month');
        $this->validateCronPart($parts[4], 0, 6, 'day of week');
    }

    /**
     * Validate a single cron expression part.
     * 
     * @param string $part The cron part to validate
     * @param int $min Minimum allowed value
     * @param int $max Maximum allowed value
     * @param string $name Name of the field for error messages
     * @throws ValidationException If the part is invalid
     */
    protected function validateCronPart(string $part, int $min, int $max, string $name): void
    {
        // Handle wildcard
        if ($part === '*') {
            return;
        }

        // Handle step values (e.g., */5)
        if (str_starts_with($part, '*/')) {
            $step = (int) substr($part, 2);
            if ($step < 1 || $step > $max) {
                throw ValidationException::withMessages([
                    'schedule' => ["Invalid cron expression: step value {$step} is out of range for {$name}"],
                ]);
            }
            return;
        }

        // Handle comma-separated values
        $values = explode(',', $part);
        
        foreach ($values as $value) {
            // Handle ranges (e.g., 1-5)
            if (str_contains($value, '-')) {
                $range = explode('-', $value);
                if (count($range) !== 2) {
                    throw ValidationException::withMessages([
                        'schedule' => ["Invalid cron expression: invalid range format in {$name}"],
                    ]);
                }
                
                $rangeStart = (int) $range[0];
                $rangeEnd = (int) $range[1];
                
                if ($rangeStart < $min || $rangeStart > $max || $rangeEnd < $min || $rangeEnd > $max) {
                    throw ValidationException::withMessages([
                        'schedule' => ["Invalid cron expression: range values out of bounds for {$name}"],
                    ]);
                }
                
                if ($rangeStart > $rangeEnd) {
                    throw ValidationException::withMessages([
                        'schedule' => ["Invalid cron expression: range start must be <= end for {$name}"],
                    ]);
                }
                
                continue;
            }

            // Handle step values with base (e.g., 5/10)
            if (str_contains($value, '/')) {
                $stepParts = explode('/', $value);
                $base = (int) $stepParts[0];
                $step = (int) $stepParts[1];
                
                if ($base < $min || $base > $max) {
                    throw ValidationException::withMessages([
                        'schedule' => ["Invalid cron expression: base value {$base} is out of range for {$name}"],
                    ]);
                }
                
                continue;
            }

            // Handle single numeric value
            $numValue = (int) $value;
            if ($numValue < $min || $numValue > $max) {
                throw ValidationException::withMessages([
                    'schedule' => ["Invalid cron expression: value {$numValue} is out of range for {$name} ({$min}-{$max})"],
                ]);
            }
        }
    }


    /**
     * Validate that source table is unique (not already configured).
     * 
     * @param string $sourceTable Source table name
     * @param int|null $excludeId Configuration ID to exclude (for updates)
     * @throws ValidationException If source table is already configured
     */
    protected function validateUniqueSourceTable(string $sourceTable, ?int $excludeId = null): void
    {
        $query = TableSyncConfiguration::where('source_table', $sourceTable);
        
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'source_table' => ["A configuration for source table '{$sourceTable}' already exists"],
            ]);
        }
    }

    /**
     * Get configurations with their sync statistics.
     * 
     * @return Collection
     */
    public function getAllWithStats(): Collection
    {
        $configurations = TableSyncConfiguration::query()
            ->withCount([
                'logs as total_syncs',
                'logs as successful_syncs' => function ($query) {
                    $query->where('status', TableSyncLog::STATUS_COMPLETED);
                },
                'logs as failed_syncs' => function ($query) {
                    $query->where('status', TableSyncLog::STATUS_FAILED);
                },
                'errors as unresolved_errors' => function ($query) {
                    $query->whereNull('resolved_at');
                },
            ])
            ->orderBy('name')
            ->get();

        // Add source/target counts for each configuration
        foreach ($configurations as $config) {
            $counts = $this->getTableCounts($config);
            $config->source_count = $counts['source_count'];
            $config->target_count = $counts['target_count'];
            $config->unsynced_count = $counts['unsynced_count'];
            $config->sync_progress = $counts['sync_progress'];
        }

        return $configurations;
    }

    /**
     * Get source and target table counts for a configuration.
     * Uses dedicated sync_tracking table instead of modifying source tables.
     * 
     * @param TableSyncConfiguration $config
     * @return array
     */
    public function getTableCounts(TableSyncConfiguration $config): array
    {
        $sourceCount = 0;
        $targetCount = 0;
        $unsyncedCount = 0;
        $syncedCount = 0;

        try {
            // Get source count from MySQL (READ-ONLY)
            if ($this->schemaDetector->tableExists($config->source_table, 'mysql')) {
                $sourceCount = DB::connection('mysql')
                    ->table($config->source_table)
                    ->count();
            }
        } catch (\Exception $e) {
            Log::warning('Failed to get source table counts', [
                'config_id' => $config->id,
                'source_table' => $config->source_table,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            // Get target count from PostgreSQL
            $targetTable = $config->getEffectiveTargetTable();
            if ($this->schemaDetector->tableExists($targetTable, 'pgsql')) {
                $targetCount = DB::connection('pgsql')
                    ->table($targetTable)
                    ->count();
            }
        } catch (\Exception $e) {
            Log::warning('Failed to get target table counts', [
                'config_id' => $config->id,
                'target_table' => $config->getEffectiveTargetTable(),
                'error' => $e->getMessage(),
            ]);
        }

        try {
            // Get synced count from tracking table (not from MySQL)
            $syncedCount = DB::connection('pgsql')
                ->table('sync_tracking')
                ->where('configuration_id', $config->id)
                ->where('source_table', $config->source_table)
                ->count();
        } catch (\Exception $e) {
            // Tracking table might not exist yet, use target count as fallback
            $syncedCount = $targetCount;
            Log::debug('Sync tracking table not available, using target count', [
                'config_id' => $config->id,
            ]);
        }

        // Calculate unsynced count
        $unsyncedCount = max(0, $sourceCount - $syncedCount);

        // Calculate sync progress percentage
        $syncProgress = $sourceCount > 0 
            ? round(($syncedCount / $sourceCount) * 100, 1)
            : 100;

        return [
            'source_count' => $sourceCount,
            'target_count' => $targetCount,
            'unsynced_count' => $unsyncedCount,
            'sync_progress' => $syncProgress,
        ];
    }

    /**
     * Get scheduled configurations (those with a cron schedule).
     * 
     * @param bool $enabledOnly If true, only return enabled configurations
     * @return Collection
     */
    public function getScheduled(bool $enabledOnly = true): Collection
    {
        $query = TableSyncConfiguration::scheduled();
        
        if ($enabledOnly) {
            $query->enabled();
        }
        
        return $query->orderBy('name')->get();
    }

    /**
     * Duplicate an existing configuration with a new name and source table.
     * 
     * @param int $id Configuration ID to duplicate
     * @param string $newName New configuration name
     * @param string $newSourceTable New source table name
     * @return TableSyncConfiguration
     * @throws \InvalidArgumentException If configuration not found
     * @throws ValidationException If validation fails
     */
    public function duplicate(int $id, string $newName, string $newSourceTable): TableSyncConfiguration
    {
        $original = $this->getById($id);
        
        if (!$original) {
            throw new \InvalidArgumentException("Configuration with ID {$id} not found");
        }

        $data = [
            'name' => $newName,
            'source_table' => $newSourceTable,
            'target_table' => $newSourceTable, // Default to same as source
            'primary_key_column' => $original->primary_key_column,
            'sync_marker_column' => $original->sync_marker_column,
            'column_mappings' => [], // Reset mappings as columns may differ
            'excluded_columns' => [], // Reset exclusions as columns may differ
            'batch_size' => $original->batch_size,
            'schedule' => $original->schedule,
            'is_enabled' => false, // Start disabled
        ];

        return $this->create($data);
    }

    /**
     * Test a configuration by validating all settings without creating it.
     * 
     * @param array $data Configuration data to test
     * @return array Validation result with details
     */
    public function testConfiguration(array $data): array
    {
        $result = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'details' => [],
        ];

        try {
            // Validate basic structure
            $validated = $this->validate($data);
            $result['details']['basic_validation'] = 'passed';

            // Validate source table exists
            $this->validateSourceTable($validated['source_table']);
            $result['details']['source_table_exists'] = true;

            // Get schema information
            $schema = $this->schemaDetector->getTableSchema($validated['source_table'], 'mysql');
            $result['details']['column_count'] = count($schema);
            $result['details']['columns'] = array_column($schema, 'name');

            // Detect primary key
            $primaryKey = $this->schemaDetector->getPrimaryKey($validated['source_table'], 'mysql');
            $result['details']['detected_primary_key'] = $primaryKey;

            if (!$primaryKey) {
                $result['warnings'][] = 'No primary key detected. Sync may be slower without a primary key.';
            }

            // Validate column mappings if provided
            if (!empty($validated['column_mappings'])) {
                $this->validateColumnMappings($validated['source_table'], $validated['column_mappings']);
                $result['details']['column_mappings_valid'] = true;
            }

            // Validate excluded columns if provided
            if (!empty($validated['excluded_columns'])) {
                $this->validateExcludedColumns($validated['source_table'], $validated['excluded_columns']);
                $result['details']['excluded_columns_valid'] = true;
            }

            // Check if source table already has a configuration
            $existing = $this->getBySourceTable($validated['source_table']);
            if ($existing) {
                $result['warnings'][] = "A configuration for this source table already exists (ID: {$existing->id})";
            }

        } catch (ValidationException $e) {
            $result['valid'] = false;
            $result['errors'] = $e->errors();
        } catch (\Exception $e) {
            $result['valid'] = false;
            $result['errors']['general'] = [$e->getMessage()];
        }

        return $result;
    }
}
