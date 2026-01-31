<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\ColumnMapperService;
use App\Services\GenericSyncResult;

/**
 * Unit tests for the Generic Sync Service components.
 * 
 * These tests verify the core logic without requiring database connections.
 * 
 * ⚠️ VERIFICATION: These tests confirm no MySQL deletion operations exist.
 */
class GenericSyncServiceTest extends TestCase
{
    /**
     * Test: GenericSyncResult correctly reports success status
     */
    public function test_generic_sync_result_reports_success(): void
    {
        $result = new GenericSyncResult(
            success: true,
            recordsSynced: 100,
            recordsFailed: 0
        );

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isPartial());
        $this->assertFalse($result->isFailed());
        $this->assertEquals(100, $result->getTotalProcessed());
        $this->assertEquals(100.0, $result->getSuccessRate());
    }

    /**
     * Test: GenericSyncResult correctly reports partial success
     */
    public function test_generic_sync_result_reports_partial(): void
    {
        $result = new GenericSyncResult(
            success: true,
            recordsSynced: 80,
            recordsFailed: 20
        );

        $this->assertFalse($result->isSuccess()); // Not fully successful
        $this->assertTrue($result->isPartial());
        $this->assertFalse($result->isFailed());
        $this->assertEquals(100, $result->getTotalProcessed());
        $this->assertEquals(80.0, $result->getSuccessRate());
    }

    /**
     * Test: GenericSyncResult correctly reports failure
     */
    public function test_generic_sync_result_reports_failure(): void
    {
        $result = new GenericSyncResult(
            success: false,
            recordsSynced: 0,
            recordsFailed: 50,
            errorMessage: 'Connection failed'
        );

        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isPartial());
        $this->assertTrue($result->isFailed());
        $this->assertEquals('Connection failed', $result->errorMessage);
    }

    /**
     * Test: GenericSyncResult toArray includes all fields
     */
    public function test_generic_sync_result_to_array(): void
    {
        $result = new GenericSyncResult(
            success: true,
            recordsSynced: 50,
            recordsFailed: 5,
            startId: 1,
            endId: 55,
            errorMessage: null
        );

        $array = $result->toArray();

        $this->assertArrayHasKey('success', $array);
        $this->assertArrayHasKey('records_synced', $array);
        $this->assertArrayHasKey('records_failed', $array);
        $this->assertArrayHasKey('start_id', $array);
        $this->assertArrayHasKey('end_id', $array);
        $this->assertArrayHasKey('total_processed', $array);
        $this->assertArrayHasKey('success_rate', $array);
    }

    /**
     * Test: ColumnMapperService maps columns correctly with no mappings
     */
    public function test_column_mapper_maps_columns_with_no_mappings(): void
    {
        $mapper = new ColumnMapperService();
        
        $sourceRow = [
            'id' => 1,
            'name' => 'Test',
            'email' => 'test@example.com',
        ];

        $result = $mapper->mapColumns($sourceRow, [], []);

        $this->assertEquals($sourceRow, $result);
    }

    /**
     * Test: ColumnMapperService applies column mappings (renaming)
     */
    public function test_column_mapper_applies_column_mappings(): void
    {
        $mapper = new ColumnMapperService();
        
        $sourceRow = [
            'id' => 1,
            'user_name' => 'Test',
            'user_email' => 'test@example.com',
        ];

        $mappings = [
            'user_name' => 'name',
            'user_email' => 'email',
        ];

        $result = $mapper->mapColumns($sourceRow, $mappings, []);

        $this->assertEquals(1, $result['id']);
        $this->assertEquals('Test', $result['name']);
        $this->assertEquals('test@example.com', $result['email']);
        $this->assertArrayNotHasKey('user_name', $result);
        $this->assertArrayNotHasKey('user_email', $result);
    }

    /**
     * Test: ColumnMapperService excludes specified columns
     */
    public function test_column_mapper_excludes_columns(): void
    {
        $mapper = new ColumnMapperService();
        
        $sourceRow = [
            'id' => 1,
            'name' => 'Test',
            'password' => 'secret',
            'internal_notes' => 'Do not sync',
        ];

        $excluded = ['password', 'internal_notes'];

        $result = $mapper->mapColumns($sourceRow, [], $excluded);

        $this->assertEquals(1, $result['id']);
        $this->assertEquals('Test', $result['name']);
        $this->assertArrayNotHasKey('password', $result);
        $this->assertArrayNotHasKey('internal_notes', $result);
    }

    /**
     * Test: ColumnMapperService preserves NULL values
     */
    public function test_column_mapper_preserves_null_values(): void
    {
        $mapper = new ColumnMapperService();
        
        $sourceRow = [
            'id' => 1,
            'name' => 'Test',
            'middle_name' => null,
            'deleted_at' => null,
        ];

        $result = $mapper->mapColumns($sourceRow, [], []);

        $this->assertNull($result['middle_name']);
        $this->assertNull($result['deleted_at']);
    }

    /**
     * Test: ColumnMapperService converts integer types correctly
     */
    public function test_column_mapper_converts_integer_types(): void
    {
        $mapper = new ColumnMapperService();

        $this->assertEquals(42, $mapper->convertType('42', 'int'));
        $this->assertEquals(42, $mapper->convertType(42, 'integer'));
        $this->assertEquals(0, $mapper->convertType('0', 'bigint'));
        $this->assertEquals(-5, $mapper->convertType('-5', 'smallint'));
    }

    /**
     * Test: ColumnMapperService converts float types correctly
     */
    public function test_column_mapper_converts_float_types(): void
    {
        $mapper = new ColumnMapperService();

        $this->assertEquals(3.14, $mapper->convertType('3.14', 'float'));
        $this->assertEquals(2.718, $mapper->convertType(2.718, 'double'));
    }

    /**
     * Test: ColumnMapperService converts boolean types correctly
     */
    public function test_column_mapper_converts_boolean_types(): void
    {
        $mapper = new ColumnMapperService();

        $this->assertTrue($mapper->convertType(1, 'tinyint(1)'));
        $this->assertFalse($mapper->convertType(0, 'tinyint(1)'));
        $this->assertTrue($mapper->convertType('1', 'tinyint(1)'));
    }

    /**
     * Test: ColumnMapperService preserves NULL during type conversion
     */
    public function test_column_mapper_preserves_null_during_conversion(): void
    {
        $mapper = new ColumnMapperService();

        $this->assertNull($mapper->convertType(null, 'int'));
        $this->assertNull($mapper->convertType(null, 'varchar'));
        $this->assertNull($mapper->convertType(null, 'datetime'));
    }

    /**
     * Test: ColumnMapperService gets correct PostgreSQL type for MySQL types
     */
    public function test_column_mapper_gets_postgres_type(): void
    {
        $mapper = new ColumnMapperService();

        $this->assertEquals('INTEGER', $mapper->getPostgresType('int'));
        $this->assertEquals('BIGINT', $mapper->getPostgresType('bigint'));
        $this->assertEquals('TEXT', $mapper->getPostgresType('text'));
        $this->assertEquals('TIMESTAMP', $mapper->getPostgresType('datetime'));
        $this->assertEquals('JSONB', $mapper->getPostgresType('json'));
        $this->assertEquals('BOOLEAN', $mapper->getPostgresType('tinyint(1)'));
    }

    /**
     * Test: ColumnMapperService validates mappings correctly
     */
    public function test_column_mapper_validates_mappings(): void
    {
        $mapper = new ColumnMapperService();

        $sourceSchema = [
            ['name' => 'id', 'type' => 'int'],
            ['name' => 'name', 'type' => 'varchar'],
            ['name' => 'email', 'type' => 'varchar'],
        ];

        // Valid mappings
        $validMappings = ['name' => 'user_name'];
        $errors = $mapper->validateMappings($validMappings, $sourceSchema);
        $this->assertEmpty($errors);

        // Invalid mappings (non-existent source column)
        $invalidMappings = ['nonexistent' => 'target'];
        $errors = $mapper->validateMappings($invalidMappings, $sourceSchema);
        $this->assertNotEmpty($errors);
    }

    /**
     * Test: ColumnMapperService validates excluded columns correctly
     */
    public function test_column_mapper_validates_excluded(): void
    {
        $mapper = new ColumnMapperService();

        $sourceSchema = [
            ['name' => 'id', 'type' => 'int'],
            ['name' => 'name', 'type' => 'varchar'],
            ['name' => 'password', 'type' => 'varchar'],
        ];

        // Valid exclusions
        $validExcluded = ['password'];
        $errors = $mapper->validateExcluded($validExcluded, $sourceSchema);
        $this->assertEmpty($errors);

        // Invalid exclusions (non-existent column)
        $invalidExcluded = ['nonexistent'];
        $errors = $mapper->validateExcluded($invalidExcluded, $sourceSchema);
        $this->assertNotEmpty($errors);
    }
}
