<?php

namespace App\Services;

/**
 * ColumnMapperService handles column mapping and type conversion between MySQL and PostgreSQL.
 * 
 * This service transforms data in memory during sync operations, applying column mappings,
 * exclusions, and data type conversions. It also generates PostgreSQL DDL for target tables.
 * 
 * ⚠️ NO DELETION FROM MYSQL: This service only transforms data in memory,
 * never deletes from MySQL.
 * 
 * Requirements: 2.1, 2.2, 2.3, 2.5
 */
class ColumnMapperService
{
    /**
     * MySQL to PostgreSQL type mapping.
     * 
     * @var array<string, string>
     */
    protected array $typeMapping = [
        'tinyint' => 'SMALLINT',
        'smallint' => 'SMALLINT',
        'mediumint' => 'INTEGER',
        'int' => 'INTEGER',
        'integer' => 'INTEGER',
        'bigint' => 'BIGINT',
        'float' => 'REAL',
        'double' => 'DOUBLE PRECISION',
        'decimal' => 'DECIMAL',
        'varchar' => 'VARCHAR',
        'char' => 'CHAR',
        'text' => 'TEXT',
        'mediumtext' => 'TEXT',
        'longtext' => 'TEXT',
        'tinytext' => 'TEXT',
        'datetime' => 'TIMESTAMP',
        'timestamp' => 'TIMESTAMP',
        'date' => 'DATE',
        'time' => 'TIME',
        'year' => 'INTEGER',
        'blob' => 'BYTEA',
        'mediumblob' => 'BYTEA',
        'longblob' => 'BYTEA',
        'tinyblob' => 'BYTEA',
        'binary' => 'BYTEA',
        'varbinary' => 'BYTEA',
        'json' => 'JSONB',
        'enum' => 'VARCHAR',
        'set' => 'VARCHAR',
        'bit' => 'BIT',
    ];

    /**
     * Map columns from a source row to target columns.
     * 
     * Applies column mappings (renaming) and excludes specified columns.
     * NULL values are preserved exactly as-is.
     * 
     * @param array $sourceRow The source row data (column_name => value)
     * @param array $mappings Column mappings (source_column => target_column)
     * @param array $excluded Columns to exclude from the result
     * @return array The mapped row with renamed columns and exclusions applied
     */
    public function mapColumns(array $sourceRow, array $mappings = [], array $excluded = []): array
    {
        $result = [];

        foreach ($sourceRow as $sourceColumn => $value) {
            // Skip excluded columns
            if (in_array($sourceColumn, $excluded, true)) {
                continue;
            }

            // Apply column mapping (rename) or use original name
            $targetColumn = $mappings[$sourceColumn] ?? $sourceColumn;

            // Preserve the value exactly (including NULL)
            $result[$targetColumn] = $value;
        }

        return $result;
    }

    /**
     * Convert a value from MySQL type to PostgreSQL type.
     * 
     * Preserves semantic value during conversion:
     * - Numbers remain equal
     * - Strings remain equal
     * - Dates remain equivalent
     * - NULL values are preserved
     * 
     * @param mixed $value The value to convert
     * @param string $mysqlType The MySQL data type
     * @param string|null $postgresType The target PostgreSQL type (auto-detected if null)
     * @return mixed The converted value
     */
    public function convertType(mixed $value, string $mysqlType, ?string $postgresType = null): mixed
    {
        // NULL values are always preserved
        if ($value === null) {
            return null;
        }

        $mysqlType = strtolower($mysqlType);
        
        // Handle TINYINT(1) as BOOLEAN
        if ($this->isBooleanType($mysqlType)) {
            return $this->convertToBoolean($value);
        }

        // Handle integer types
        if ($this->isIntegerType($mysqlType)) {
            return $this->convertToInteger($value);
        }

        // Handle floating point types
        if ($this->isFloatType($mysqlType)) {
            return $this->convertToFloat($value);
        }

        // Handle decimal types
        if ($this->isDecimalType($mysqlType)) {
            return $this->convertToDecimal($value);
        }

        // Handle date/time types
        if ($this->isDateTimeType($mysqlType)) {
            return $this->convertDateTime($value, $mysqlType);
        }

        // Handle JSON type
        if ($mysqlType === 'json') {
            return $this->convertToJson($value);
        }

        // Handle binary types
        if ($this->isBinaryType($mysqlType)) {
            return $this->convertToBinary($value);
        }

        // Handle ENUM and SET types
        if ($mysqlType === 'enum' || $mysqlType === 'set') {
            return (string) $value;
        }

        // Default: return as string for text types
        return $value;
    }

    /**
     * Generate target table schema for PostgreSQL based on source MySQL schema.
     * 
     * @param array $sourceSchema Array of column definitions from SchemaDetectorService
     * @param array $mappings Column mappings (source_column => target_column)
     * @param array $excluded Columns to exclude
     * @return array Array of PostgreSQL column definitions
     */
    public function generateTargetSchema(array $sourceSchema, array $mappings = [], array $excluded = []): array
    {
        $targetSchema = [];

        foreach ($sourceSchema as $column) {
            $sourceName = $column['name'];

            // Skip excluded columns
            if (in_array($sourceName, $excluded, true)) {
                continue;
            }

            // Apply column mapping
            $targetName = $mappings[$sourceName] ?? $sourceName;

            // Convert MySQL type to PostgreSQL type
            $postgresType = $this->convertMySqlTypeToPostgres($column);

            $targetSchema[] = [
                'name' => $targetName,
                'type' => $postgresType,
                'nullable' => $column['nullable'] ?? true,
                'default' => $this->convertDefaultValue($column['default'] ?? null, $column['data_type']),
                'auto_increment' => $column['auto_increment'] ?? false,
            ];
        }

        return $targetSchema;
    }

    /**
     * Generate PostgreSQL CREATE TABLE DDL statement.
     * 
     * @param string $tableName The target table name
     * @param array $targetSchema The target schema from generateTargetSchema()
     * @param string|null $primaryKey The primary key column name
     * @return string The CREATE TABLE SQL statement
     */
    public function generateCreateTableDDL(string $tableName, array $targetSchema, ?string $primaryKey = null): string
    {
        $columns = [];

        foreach ($targetSchema as $column) {
            $columnDef = sprintf(
                '"%s" %s',
                $column['name'],
                $column['type']
            );

            // Add NOT NULL constraint
            if (!$column['nullable']) {
                $columnDef .= ' NOT NULL';
            }

            // Add default value
            if ($column['default'] !== null && !$column['auto_increment']) {
                $columnDef .= ' DEFAULT ' . $this->formatDefaultForDDL($column['default'], $column['type']);
            }

            $columns[] = $columnDef;
        }

        // Add primary key constraint
        if ($primaryKey !== null) {
            $columns[] = sprintf('PRIMARY KEY ("%s")', $primaryKey);
        }

        return sprintf(
            'CREATE TABLE IF NOT EXISTS "%s" (%s)',
            $tableName,
            implode(', ', $columns)
        );
    }

    /**
     * Get the PostgreSQL type for a MySQL type.
     * 
     * @param string $mysqlType The MySQL data type
     * @return string The corresponding PostgreSQL type
     */
    public function getPostgresType(string $mysqlType): string
    {
        $mysqlType = strtolower(trim($mysqlType));
        
        // Handle TINYINT(1) as BOOLEAN
        if (preg_match('/^tinyint\s*\(\s*1\s*\)$/i', $mysqlType)) {
            return 'BOOLEAN';
        }

        // Extract base type (remove size specifications)
        $baseType = preg_replace('/\s*\([^)]*\)/', '', $mysqlType);
        $baseType = preg_replace('/\s+unsigned$/i', '', $baseType);
        $baseType = trim($baseType);

        return $this->typeMapping[$baseType] ?? 'TEXT';
    }

    /**
     * Convert MySQL column definition to PostgreSQL type with size.
     * 
     * @param array $column Column definition from SchemaDetectorService
     * @return string PostgreSQL type with size if applicable
     */
    protected function convertMySqlTypeToPostgres(array $column): string
    {
        $dataType = strtolower($column['data_type']);
        $fullType = strtolower($column['type'] ?? $dataType);

        // Handle TINYINT(1) as BOOLEAN
        if (preg_match('/^tinyint\s*\(\s*1\s*\)$/i', $fullType)) {
            return 'BOOLEAN';
        }

        $postgresType = $this->typeMapping[$dataType] ?? 'TEXT';

        // Handle types that need size specifications
        if (in_array($dataType, ['varchar', 'char'])) {
            $maxLength = $column['max_length'] ?? 255;
            return sprintf('%s(%d)', $postgresType, $maxLength);
        }

        if ($dataType === 'decimal') {
            $precision = $column['numeric_precision'] ?? 10;
            $scale = $column['numeric_scale'] ?? 0;
            return sprintf('DECIMAL(%d,%d)', $precision, $scale);
        }

        return $postgresType;
    }

    /**
     * Check if MySQL type represents a boolean (TINYINT(1)).
     */
    protected function isBooleanType(string $mysqlType): bool
    {
        return preg_match('/^tinyint\s*\(\s*1\s*\)$/i', $mysqlType) === 1;
    }

    /**
     * Check if MySQL type is an integer type.
     */
    protected function isIntegerType(string $mysqlType): bool
    {
        $intTypes = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'];
        $baseType = preg_replace('/\s*\([^)]*\)/', '', $mysqlType);
        $baseType = preg_replace('/\s+unsigned$/i', '', $baseType);
        return in_array(trim($baseType), $intTypes, true);
    }

    /**
     * Check if MySQL type is a floating point type.
     */
    protected function isFloatType(string $mysqlType): bool
    {
        $floatTypes = ['float', 'double', 'real'];
        $baseType = preg_replace('/\s*\([^)]*\)/', '', $mysqlType);
        return in_array(trim($baseType), $floatTypes, true);
    }

    /**
     * Check if MySQL type is a decimal type.
     */
    protected function isDecimalType(string $mysqlType): bool
    {
        $decimalTypes = ['decimal', 'numeric', 'dec', 'fixed'];
        $baseType = preg_replace('/\s*\([^)]*\)/', '', $mysqlType);
        return in_array(trim($baseType), $decimalTypes, true);
    }

    /**
     * Check if MySQL type is a date/time type.
     */
    protected function isDateTimeType(string $mysqlType): bool
    {
        $dateTypes = ['datetime', 'timestamp', 'date', 'time', 'year'];
        return in_array($mysqlType, $dateTypes, true);
    }

    /**
     * Check if MySQL type is a binary type.
     */
    protected function isBinaryType(string $mysqlType): bool
    {
        $binaryTypes = ['blob', 'mediumblob', 'longblob', 'tinyblob', 'binary', 'varbinary'];
        $baseType = preg_replace('/\s*\([^)]*\)/', '', $mysqlType);
        return in_array(trim($baseType), $binaryTypes, true);
    }

    /**
     * Convert value to boolean.
     */
    protected function convertToBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return (bool) $value;
    }

    /**
     * Convert value to integer.
     */
    protected function convertToInteger(mixed $value): int
    {
        return (int) $value;
    }

    /**
     * Convert value to float.
     */
    protected function convertToFloat(mixed $value): float
    {
        return (float) $value;
    }

    /**
     * Convert value to decimal string (preserves precision).
     */
    protected function convertToDecimal(mixed $value): string
    {
        // Return as string to preserve decimal precision
        return (string) $value;
    }

    /**
     * Convert date/time value.
     */
    protected function convertDateTime(mixed $value, string $mysqlType): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        // Handle special MySQL values
        if ($value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        return $value;
    }

    /**
     * Convert value to JSON.
     */
    protected function convertToJson(mixed $value): mixed
    {
        if (is_string($value)) {
            // Validate JSON string
            json_decode($value);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
            // If not valid JSON, encode it
            return json_encode($value);
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }

        return json_encode($value);
    }

    /**
     * Convert value to binary (for BYTEA).
     */
    protected function convertToBinary(mixed $value): mixed
    {
        // PostgreSQL expects binary data as-is or as hex string
        return $value;
    }

    /**
     * Convert MySQL default value to PostgreSQL compatible value.
     */
    protected function convertDefaultValue(mixed $default, string $mysqlType): mixed
    {
        if ($default === null) {
            return null;
        }

        // Handle CURRENT_TIMESTAMP
        if (strtoupper($default) === 'CURRENT_TIMESTAMP') {
            return 'CURRENT_TIMESTAMP';
        }

        // Handle NULL string
        if (strtoupper($default) === 'NULL') {
            return null;
        }

        return $default;
    }

    /**
     * Format default value for DDL statement.
     */
    protected function formatDefaultForDDL(mixed $default, string $postgresType): string
    {
        if ($default === 'CURRENT_TIMESTAMP') {
            return 'CURRENT_TIMESTAMP';
        }

        $postgresType = strtoupper($postgresType);

        // Boolean defaults
        if (str_contains($postgresType, 'BOOLEAN')) {
            return $default ? 'TRUE' : 'FALSE';
        }

        // Numeric defaults
        if (preg_match('/^(INTEGER|BIGINT|SMALLINT|REAL|DOUBLE|DECIMAL|NUMERIC)/', $postgresType)) {
            return (string) $default;
        }

        // String defaults - need quoting
        return sprintf("'%s'", str_replace("'", "''", (string) $default));
    }

    /**
     * Validate column mappings against source schema.
     * 
     * @param array $mappings Column mappings to validate
     * @param array $sourceSchema Source table schema
     * @return array Array of validation errors (empty if valid)
     */
    public function validateMappings(array $mappings, array $sourceSchema): array
    {
        $errors = [];
        $sourceColumns = array_column($sourceSchema, 'name');

        foreach ($mappings as $sourceColumn => $targetColumn) {
            if (!in_array($sourceColumn, $sourceColumns, true)) {
                $errors[] = "Source column '{$sourceColumn}' does not exist in the source table";
            }

            if (empty($targetColumn)) {
                $errors[] = "Target column name cannot be empty for source column '{$sourceColumn}'";
            }
        }

        return $errors;
    }

    /**
     * Validate excluded columns against source schema.
     * 
     * @param array $excluded Columns to exclude
     * @param array $sourceSchema Source table schema
     * @return array Array of validation errors (empty if valid)
     */
    public function validateExcluded(array $excluded, array $sourceSchema): array
    {
        $errors = [];
        $sourceColumns = array_column($sourceSchema, 'name');

        foreach ($excluded as $column) {
            if (!in_array($column, $sourceColumns, true)) {
                $errors[] = "Excluded column '{$column}' does not exist in the source table";
            }
        }

        return $errors;
    }
}
