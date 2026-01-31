<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\DateExtractor;
use App\Services\PartitionManager;
use App\Models\PartitionRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Checkpoint Test: Verify partition creation works correctly
 * 
 * This test validates:
 * - Creating partitions for various dates from MySQL alerts
 * - Schema consistency across partitions
 * - Partition registry is updated correctly
 * - Idempotent partition creation
 * 
 * NOTE: This test does NOT use RefreshDatabase to preserve partition data
 * as per checkpoint requirements.
 */
class PartitionCreationCheckpointTest extends TestCase
{
    protected DateExtractor $dateExtractor;
    protected PartitionManager $partitionManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dateExtractor = app(DateExtractor::class);
        $this->partitionManager = app(PartitionManager::class);
    }

    /**
     * Test creating partitions for various dates from MySQL alerts
     * 
     * @test
     */
    public function test_create_partitions_for_various_dates_from_mysql()
    {
        // Fetch sample alerts from MySQL with different dates
        $alerts = DB::connection('mysql')
            ->table('alerts')
            ->select('receivedtime')
            ->orderBy('receivedtime', 'desc')
            ->limit(50)
            ->get();

        $this->assertGreaterThan(0, $alerts->count(), 'MySQL alerts table should have data');

        // Extract unique dates from alerts
        $uniqueDates = $alerts->map(function ($alert) {
            return $this->dateExtractor->extractDate($alert->receivedtime);
        })->unique(function ($date) {
            return $date->format('Y-m-d');
        });

        $this->assertGreaterThan(0, $uniqueDates->count(), 'Should have at least one unique date');

        $createdPartitions = [];

        // Create partitions for each unique date
        foreach ($uniqueDates as $date) {
            $tableName = $this->partitionManager->getPartitionTableName($date);
            
            // Ensure partition exists
            $result = $this->partitionManager->ensurePartitionExists($date);
            $this->assertTrue($result, "Partition creation should succeed for date: {$date->format('Y-m-d')}");

            // Verify table was created
            $tableExists = DB::connection('pgsql')
                ->select("SELECT EXISTS (
                    SELECT FROM information_schema.tables 
                    WHERE table_schema = 'public' 
                    AND table_name = ?
                )", [$tableName]);
            
            $this->assertTrue($tableExists[0]->exists, "Partition table {$tableName} should exist");

            // Verify partition is registered
            $registry = PartitionRegistry::where('table_name', $tableName)->first();
            $this->assertNotNull($registry, "Partition {$tableName} should be registered");
            $this->assertEquals($date->format('Y-m-d'), $registry->partition_date->format('Y-m-d'));

            $createdPartitions[] = $tableName;
        }

        $this->assertGreaterThan(0, count($createdPartitions), 'Should have created at least one partition');

        echo "\n✓ Created " . count($createdPartitions) . " partitions for various dates from MySQL alerts\n";
    }

    /**
     * Test schema consistency across multiple partitions
     * 
     * @test
     */
    public function test_schema_consistency_across_partitions()
    {
        // Create partitions for different dates
        $dates = [
            now()->subDays(5),
            now()->subDays(3),
            now()->subDays(1),
            now(),
        ];

        $partitionTables = [];
        foreach ($dates as $date) {
            $this->partitionManager->ensurePartitionExists($date);
            $partitionTables[] = $this->partitionManager->getPartitionTableName($date);
        }

        // Get schema for first partition as reference
        $referenceSchema = $this->getTableSchema($partitionTables[0]);
        $this->assertNotEmpty($referenceSchema, 'Reference schema should not be empty');

        // Verify all other partitions have identical schema
        foreach (array_slice($partitionTables, 1) as $tableName) {
            $schema = $this->getTableSchema($tableName);
            
            $this->assertEquals(
                count($referenceSchema),
                count($schema),
                "Partition {$tableName} should have same number of columns as reference"
            );

            // Compare each column
            foreach ($referenceSchema as $column) {
                $matchingColumn = collect($schema)->firstWhere('column_name', $column->column_name);
                
                $this->assertNotNull(
                    $matchingColumn,
                    "Column {$column->column_name} should exist in partition {$tableName}"
                );
                
                $this->assertEquals(
                    $column->data_type,
                    $matchingColumn->data_type,
                    "Column {$column->column_name} should have same data type in partition {$tableName}"
                );
            }
        }

        echo "\n✓ Verified schema consistency across " . count($partitionTables) . " partitions\n";
    }

    /**
     * Test partition registry is updated correctly
     * 
     * @test
     */
    public function test_partition_registry_updated_correctly()
    {
        $date = now()->subDays(2);
        $tableName = $this->partitionManager->getPartitionTableName($date);

        // Create partition (or ensure it exists)
        $this->partitionManager->ensurePartitionExists($date);

        // Verify registry entry
        $registry = PartitionRegistry::where('table_name', $tableName)->first();
        
        $this->assertNotNull($registry, 'Partition should be registered');
        $this->assertEquals($tableName, $registry->table_name);
        $this->assertEquals($date->format('Y-m-d'), $registry->partition_date->format('Y-m-d'));
        $this->assertIsInt($registry->record_count, 'Record count should be an integer');
        $this->assertNotNull($registry->created_at);

        // Get current record count
        $currentCount = $registry->record_count;

        // Update record count to a specific value
        $this->partitionManager->updateRecordCount($tableName, 100);
        
        $registry->refresh();
        $this->assertEquals(100, $registry->record_count, 'Record count should be updated to 100');

        // Increment record count
        $this->partitionManager->incrementRecordCount($tableName, 50);
        
        $registry->refresh();
        $this->assertEquals(150, $registry->record_count, 'Record count should be incremented to 150');

        echo "\n✓ Verified partition registry is updated correctly\n";
    }

    /**
     * Test partition creation is idempotent
     * 
     * @test
     */
    public function test_partition_creation_is_idempotent()
    {
        $date = now()->subDays(1);
        $tableName = $this->partitionManager->getPartitionTableName($date);

        // Create partition first time
        $result1 = $this->partitionManager->ensurePartitionExists($date);
        $this->assertTrue($result1, 'First partition creation should succeed');

        // Get initial registry entry
        $registry1 = PartitionRegistry::where('table_name', $tableName)->first();
        $this->assertNotNull($registry1);
        $createdAt1 = $registry1->created_at;

        // Try to create same partition again
        $result2 = $this->partitionManager->ensurePartitionExists($date);
        $this->assertTrue($result2, 'Second partition creation should succeed (idempotent)');

        // Verify registry wasn't duplicated
        $registryCount = PartitionRegistry::where('table_name', $tableName)->count();
        $this->assertEquals(1, $registryCount, 'Should only have one registry entry');

        // Verify created_at timestamp didn't change
        $registry2 = PartitionRegistry::where('table_name', $tableName)->first();
        $this->assertEquals(
            $createdAt1->timestamp,
            $registry2->created_at->timestamp,
            'Created timestamp should not change on subsequent calls'
        );

        echo "\n✓ Verified partition creation is idempotent\n";
    }

    /**
     * Test indexes are created on partitions
     * 
     * @test
     */
    public function test_indexes_created_on_partitions()
    {
        $date = now();
        $tableName = $this->partitionManager->getPartitionTableName($date);

        // Create partition
        $this->partitionManager->ensurePartitionExists($date);

        // Get indexes for the partition
        $indexes = DB::connection('pgsql')
            ->select("
                SELECT indexname, indexdef
                FROM pg_indexes
                WHERE tablename = ?
                AND schemaname = 'public'
            ", [$tableName]);

        $this->assertNotEmpty($indexes, 'Partition should have indexes');

        // Expected indexes (based on actual schema)
        $expectedIndexes = [
            'panelid',
            'alerttype',
            'priority',
            'createtime',
            'synced_at',
            'sync_batch_id',
        ];

        foreach ($expectedIndexes as $expectedIndex) {
            $found = collect($indexes)->contains(function ($index) use ($expectedIndex, $tableName) {
                return str_contains($index->indexname, $expectedIndex);
            });

            $this->assertTrue(
                $found,
                "Index for {$expectedIndex} should exist on partition {$tableName}"
            );
        }

        echo "\n✓ Verified indexes are created on partitions\n";
    }

    /**
     * Test listing partitions
     * 
     * @test
     */
    public function test_list_partitions()
    {
        // Create multiple partitions
        $dates = [
            now()->subDays(3),
            now()->subDays(2),
            now()->subDays(1),
        ];

        foreach ($dates as $date) {
            $this->partitionManager->ensurePartitionExists($date);
        }

        // List all partitions
        $partitions = $this->partitionManager->listPartitions();

        $this->assertGreaterThanOrEqual(3, $partitions->count(), 'Should have at least 3 partitions');

        // Verify each partition has required fields
        foreach ($partitions as $partition) {
            $this->assertNotNull($partition->table_name);
            $this->assertNotNull($partition->partition_date);
            $this->assertNotNull($partition->created_at);
            $this->assertIsInt($partition->record_count);
        }

        echo "\n✓ Verified partition listing functionality\n";
    }

    /**
     * Test getting partitions in date range
     * 
     * @test
     */
    public function test_get_partitions_in_date_range()
    {
        // Create partitions for a range of dates
        $startDate = now()->subDays(5);
        $endDate = now();

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $this->partitionManager->ensurePartitionExists($date);
        }

        // Get partitions in range
        $partitions = $this->partitionManager->getPartitionsInRange($startDate, $endDate);

        $this->assertGreaterThanOrEqual(6, $partitions->count(), 'Should have at least 6 partitions in range');

        // Verify all partition names follow convention
        foreach ($partitions as $partition) {
            $this->assertMatchesRegularExpression(
                '/^alerts_\d{4}_\d{2}_\d{2}$/',
                $partition->table_name,
                "Partition name {$partition->table_name} should follow naming convention"
            );
        }

        echo "\n✓ Verified getting partitions in date range\n";
    }

    /**
     * Helper method to get table schema
     */
    protected function getTableSchema(string $tableName): array
    {
        return DB::connection('pgsql')
            ->select("
                SELECT column_name, data_type, is_nullable
                FROM information_schema.columns
                WHERE table_schema = 'public'
                AND table_name = ?
                ORDER BY ordinal_position
            ", [$tableName]);
    }
}
