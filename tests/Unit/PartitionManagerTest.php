<?php

namespace Tests\Unit;

use App\Services\PartitionManager;
use App\Services\DateExtractor;
use App\Models\PartitionRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for PartitionManager service
 * 
 * Tests partition table creation, schema consistency, and metadata tracking.
 */
class PartitionManagerTest extends TestCase
{
    use DatabaseTransactions;
    
    protected PartitionManager $partitionManager;
    protected DateExtractor $dateExtractor;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->dateExtractor = new DateExtractor();
        $this->partitionManager = new PartitionManager($this->dateExtractor);
        
        // Use PostgreSQL connection for tests
        $this->connection = 'pgsql';
    }
    
    protected function tearDown(): void
    {
        // Clean up any test partition tables
        $this->cleanupTestPartitions();
        
        parent::tearDown();
    }
    
    /**
     * Clean up test partition tables
     */
    private function cleanupTestPartitions(): void
    {
        try {
            $partitions = PartitionRegistry::all();
            
            foreach ($partitions as $partition) {
                if (str_starts_with($partition->table_name, 'alerts_')) {
                    DB::connection($this->connection)
                        ->statement("DROP TABLE IF EXISTS {$partition->table_name}");
                }
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }
    
    /**
     * Test that partition table name is generated correctly
     */
    public function test_get_partition_table_name()
    {
        $date = Carbon::parse('2026-01-08');
        $tableName = $this->partitionManager->getPartitionTableName($date);
        
        $this->assertEquals('alerts_2026_01_08', $tableName);
    }
    
    /**
     * Test that partition table can be created
     */
    public function test_create_partition()
    {
        $date = Carbon::parse('2026-01-08');
        $tableName = $this->partitionManager->getPartitionTableName($date);
        
        // Create the partition
        $result = $this->partitionManager->createPartition($date);
        
        $this->assertTrue($result);
        
        // Verify table exists in database
        $this->assertTrue($this->partitionManager->partitionTableExists($tableName));
        
        // Verify partition is registered
        $partitionInfo = $this->partitionManager->getPartitionInfo($tableName);
        $this->assertNotNull($partitionInfo);
        $this->assertEquals($tableName, $partitionInfo->table_name);
        $this->assertEquals($date->toDateString(), $partitionInfo->partition_date->toDateString());
    }
    
    /**
     * Test that ensurePartitionExists is idempotent
     */
    public function test_ensure_partition_exists_is_idempotent()
    {
        $date = Carbon::parse('2026-01-09');
        $tableName = $this->partitionManager->getPartitionTableName($date);
        
        // First call should create the partition
        $result1 = $this->partitionManager->ensurePartitionExists($date);
        $this->assertTrue($result1);
        
        // Second call should return true without error
        $result2 = $this->partitionManager->ensurePartitionExists($date);
        $this->assertTrue($result2);
        
        // Verify only one registry entry exists
        $count = PartitionRegistry::where('table_name', $tableName)->count();
        $this->assertEquals(1, $count);
    }
    
    /**
     * Test that partition schema matches template
     */
    public function test_partition_schema_matches_template()
    {
        $date = Carbon::parse('2026-01-10');
        $tableName = $this->partitionManager->getPartitionTableName($date);
        
        // Create the partition
        $this->partitionManager->createPartition($date);
        
        // Get the schema template
        $template = $this->partitionManager->getPartitionSchema();
        
        // Verify table has all expected columns
        $columns = DB::connection($this->connection)
            ->select(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = ?
                 ORDER BY ordinal_position",
                [$tableName]
            );
        
        $columnNames = array_map(fn($col) => $col->column_name, $columns);
        $templateColumnNames = array_map(fn($col) => $col['name'], $template);
        
        $this->assertEquals($templateColumnNames, $columnNames);
    }
    
    /**
     * Test that indexes are created on partition
     */
    public function test_indexes_are_created_on_partition()
    {
        $date = Carbon::parse('2026-01-11');
        $tableName = $this->partitionManager->getPartitionTableName($date);
        
        // Create the partition
        $this->partitionManager->createPartition($date);
        
        // Verify indexes exist
        $indexes = DB::connection($this->connection)
            ->select(
                "SELECT indexname FROM pg_indexes
                 WHERE schemaname = 'public' AND tablename = ?",
                [$tableName]
            );
        
        $indexNames = array_map(fn($idx) => $idx->indexname, $indexes);
        
        // Should have primary key index plus the defined indexes
        $this->assertGreaterThan(0, count($indexNames));
        
        // Check for specific indexes
        $this->assertContains("idx_{$tableName}_panelid", $indexNames);
        $this->assertContains("idx_{$tableName}_alerttype", $indexNames);
        $this->assertContains("idx_{$tableName}_priority", $indexNames);
    }
    
    /**
     * Test listing partitions
     */
    public function test_list_partitions()
    {
        // Create multiple partitions
        $dates = [
            Carbon::parse('2026-01-12'),
            Carbon::parse('2026-01-13'),
            Carbon::parse('2026-01-14'),
        ];
        
        foreach ($dates as $date) {
            $this->partitionManager->createPartition($date);
        }
        
        // List partitions
        $partitions = $this->partitionManager->listPartitions();
        
        $this->assertGreaterThanOrEqual(3, $partitions->count());
        
        // Verify dates are present
        $partitionDates = $partitions->pluck('partition_date')->map(fn($d) => $d->toDateString())->toArray();
        
        foreach ($dates as $date) {
            $this->assertContains($date->toDateString(), $partitionDates);
        }
    }
    
    /**
     * Test getting partitions in date range
     */
    public function test_get_partitions_in_range()
    {
        // Create partitions for multiple dates
        $dates = [
            Carbon::parse('2026-01-15'),
            Carbon::parse('2026-01-16'),
            Carbon::parse('2026-01-17'),
            Carbon::parse('2026-01-18'),
            Carbon::parse('2026-01-19'),
        ];
        
        foreach ($dates as $date) {
            $this->partitionManager->createPartition($date);
        }
        
        // Get partitions in range
        $startDate = Carbon::parse('2026-01-16');
        $endDate = Carbon::parse('2026-01-18');
        
        $partitions = $this->partitionManager->getPartitionsInRange($startDate, $endDate);
        
        $this->assertEquals(3, $partitions->count());
        
        $partitionDates = $partitions->pluck('partition_date')->map(fn($d) => $d->toDateString())->toArray();
        
        $this->assertContains('2026-01-16', $partitionDates);
        $this->assertContains('2026-01-17', $partitionDates);
        $this->assertContains('2026-01-18', $partitionDates);
    }
    
    /**
     * Test updating record count
     */
    public function test_update_record_count()
    {
        $date = Carbon::parse('2026-01-20');
        $tableName = $this->partitionManager->getPartitionTableName($date);
        
        // Create the partition
        $this->partitionManager->createPartition($date);
        
        // Update record count
        $result = $this->partitionManager->updateRecordCount($tableName, 100);
        $this->assertTrue($result);
        
        // Verify count was updated
        $partitionInfo = $this->partitionManager->getPartitionInfo($tableName);
        $this->assertEquals(100, $partitionInfo->record_count);
        $this->assertNotNull($partitionInfo->last_synced_at);
    }
    
    /**
     * Test incrementing record count
     */
    public function test_increment_record_count()
    {
        $date = Carbon::parse('2026-01-21');
        $tableName = $this->partitionManager->getPartitionTableName($date);
        
        // Create the partition
        $this->partitionManager->createPartition($date);
        
        // Set initial count
        $this->partitionManager->updateRecordCount($tableName, 50);
        
        // Increment count
        $result = $this->partitionManager->incrementRecordCount($tableName, 25);
        $this->assertTrue($result);
        
        // Verify count was incremented
        $partitionInfo = $this->partitionManager->getPartitionInfo($tableName);
        $this->assertEquals(75, $partitionInfo->record_count);
    }
    
    /**
     * Test schema consistency validation
     */
    public function test_validate_schema_consistency()
    {
        // Create multiple partitions
        $dates = [
            Carbon::parse('2026-01-22'),
            Carbon::parse('2026-01-23'),
            Carbon::parse('2026-01-24'),
        ];
        
        foreach ($dates as $date) {
            $this->partitionManager->createPartition($date);
        }
        
        // Validate schema consistency
        $result = $this->partitionManager->validateSchemaConsistency();
        
        $this->assertTrue($result);
    }
}

