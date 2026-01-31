<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SchemaDetectorService detects and analyzes MySQL table schema.
 * 
 * This service provides READ-ONLY operations to inspect table structure,
 * column definitions, data types, and primary keys from MySQL source tables.
 * 
 * ⚠️ NO DELETION FROM MYSQL: This service only READS schema information,
 * never modifies or deletes data.
 * 
 * Requirements: 1.2, 1.3
 */
class SchemaDetectorService
{
    /**
     * Check if a table exists in the specified database connection.
     * 
     * @param string $tableName The name of the table to check
     * @param string $connection The database connection name (default: 'mysql')
     * @return bool True if the table exists, false otherwise
     */
    public function tableExists(string $tableName, string $connection = 'mysql'): bool
    {
        return Schema::connection($connection)->hasTable($tableName);
    }

    /**
     * Get the complete schema definition for a table.
     * 
     * Returns an array of column definitions including:
     * - name: Column name
     * - type: MySQL data type
     * - nullable: Whether the column allows NULL
     * - default: Default value (if any)
     * - auto_increment: Whether the column is auto-incrementing
     * - comment: Column comment (if any)
     * 
     * @param string $tableName The name of the table
     * @param string $connection The database connection name (default: 'mysql')
     * @return array Array of column definitions
     * @throws \InvalidArgumentException If the table does not exist
     */
    public function getTableSchema(string $tableName, string $connection = 'mysql'): array
    {
        if (!$this->tableExists($tableName, $connection)) {
            throw new \InvalidArgumentException("Table '{$tableName}' does not exist in connection '{$connection}'");
        }

        $database = config("database.connections.{$connection}.database");
        
        $columns = DB::connection($connection)
            ->select("
                SELECT 
                    COLUMN_NAME as name,
                    COLUMN_TYPE as type,
                    DATA_TYPE as data_type,
                    IS_NULLABLE as nullable,
                    COLUMN_DEFAULT as `default`,
                    EXTRA as extra,
                    COLUMN_COMMENT as comment,
                    CHARACTER_MAXIMUM_LENGTH as max_length,
                    NUMERIC_PRECISION as numeric_precision,
                    NUMERIC_SCALE as numeric_scale
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = ?
                AND TABLE_NAME = ?
                ORDER BY ORDINAL_POSITION
            ", [$database, $tableName]);

        return array_map(function ($column) {
            return [
                'name' => $column->name,
                'type' => $column->type,
                'data_type' => $column->data_type,
                'nullable' => $column->nullable === 'YES',
                'default' => $column->default,
                'auto_increment' => str_contains($column->extra ?? '', 'auto_increment'),
                'comment' => $column->comment,
                'max_length' => $column->max_length,
                'numeric_precision' => $column->numeric_precision,
                'numeric_scale' => $column->numeric_scale,
            ];
        }, $columns);
    }

    /**
     * Get column types for a table as a simple name => type mapping.
     * 
     * Returns an associative array where keys are column names
     * and values are the MySQL data types.
     * 
     * @param string $tableName The name of the table
     * @param string $connection The database connection name (default: 'mysql')
     * @return array Associative array of column_name => data_type
     * @throws \InvalidArgumentException If the table does not exist
     */
    public function getColumnTypes(string $tableName, string $connection = 'mysql'): array
    {
        $schema = $this->getTableSchema($tableName, $connection);
        
        $types = [];
        foreach ($schema as $column) {
            $types[$column['name']] = $column['data_type'];
        }
        
        return $types;
    }

    /**
     * Detect the primary key column for a table.
     * 
     * Returns the name of the primary key column, or null if no primary key exists.
     * For composite primary keys, returns the first column of the key.
     * 
     * @param string $tableName The name of the table
     * @param string $connection The database connection name (default: 'mysql')
     * @return string|null The primary key column name, or null if none exists
     * @throws \InvalidArgumentException If the table does not exist
     */
    public function getPrimaryKey(string $tableName, string $connection = 'mysql'): ?string
    {
        if (!$this->tableExists($tableName, $connection)) {
            throw new \InvalidArgumentException("Table '{$tableName}' does not exist in connection '{$connection}'");
        }

        $database = config("database.connections.{$connection}.database");
        
        $result = DB::connection($connection)
            ->select("
                SELECT COLUMN_NAME as column_name
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = ?
                AND TABLE_NAME = ?
                AND CONSTRAINT_NAME = 'PRIMARY'
                ORDER BY ORDINAL_POSITION
                LIMIT 1
            ", [$database, $tableName]);

        return !empty($result) ? $result[0]->column_name : null;
    }

    /**
     * Get all primary key columns for a table (supports composite keys).
     * 
     * @param string $tableName The name of the table
     * @param string $connection The database connection name (default: 'mysql')
     * @return array Array of primary key column names
     * @throws \InvalidArgumentException If the table does not exist
     */
    public function getPrimaryKeyColumns(string $tableName, string $connection = 'mysql'): array
    {
        if (!$this->tableExists($tableName, $connection)) {
            throw new \InvalidArgumentException("Table '{$tableName}' does not exist in connection '{$connection}'");
        }

        $database = config("database.connections.{$connection}.database");
        
        $results = DB::connection($connection)
            ->select("
                SELECT COLUMN_NAME as column_name
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = ?
                AND TABLE_NAME = ?
                AND CONSTRAINT_NAME = 'PRIMARY'
                ORDER BY ORDINAL_POSITION
            ", [$database, $tableName]);

        return array_map(fn($row) => $row->column_name, $results);
    }

    /**
     * Get column names for a table.
     * 
     * @param string $tableName The name of the table
     * @param string $connection The database connection name (default: 'mysql')
     * @return array Array of column names
     * @throws \InvalidArgumentException If the table does not exist
     */
    public function getColumnNames(string $tableName, string $connection = 'mysql'): array
    {
        $schema = $this->getTableSchema($tableName, $connection);
        return array_column($schema, 'name');
    }

    /**
     * Check if a specific column exists in a table.
     * 
     * @param string $tableName The name of the table
     * @param string $columnName The name of the column to check
     * @param string $connection The database connection name (default: 'mysql')
     * @return bool True if the column exists, false otherwise
     */
    public function columnExists(string $tableName, string $columnName, string $connection = 'mysql'): bool
    {
        if (!$this->tableExists($tableName, $connection)) {
            return false;
        }

        return Schema::connection($connection)->hasColumn($tableName, $columnName);
    }

    /**
     * Get indexes for a table.
     * 
     * @param string $tableName The name of the table
     * @param string $connection The database connection name (default: 'mysql')
     * @return array Array of index definitions
     * @throws \InvalidArgumentException If the table does not exist
     */
    public function getIndexes(string $tableName, string $connection = 'mysql'): array
    {
        if (!$this->tableExists($tableName, $connection)) {
            throw new \InvalidArgumentException("Table '{$tableName}' does not exist in connection '{$connection}'");
        }

        $database = config("database.connections.{$connection}.database");
        
        $results = DB::connection($connection)
            ->select("
                SELECT 
                    INDEX_NAME as index_name,
                    COLUMN_NAME as column_name,
                    NON_UNIQUE as non_unique,
                    SEQ_IN_INDEX as seq_in_index
                FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = ?
                AND TABLE_NAME = ?
                ORDER BY INDEX_NAME, SEQ_IN_INDEX
            ", [$database, $tableName]);

        // Group by index name
        $indexes = [];
        foreach ($results as $row) {
            $indexName = $row->index_name;
            if (!isset($indexes[$indexName])) {
                $indexes[$indexName] = [
                    'name' => $indexName,
                    'unique' => $row->non_unique == 0,
                    'columns' => [],
                ];
            }
            $indexes[$indexName]['columns'][] = $row->column_name;
        }

        return array_values($indexes);
    }
}
