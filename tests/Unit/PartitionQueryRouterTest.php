<?php

namespace Tests\Unit;

use App\Services\PartitionQueryRouter;
use App\Services\PartitionManager;
use App\Services\DateExtractor;
use App\Models\PartitionRegistry;
use Carbon\Carbon;
use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/**
 * Unit tests for PartitionQueryRouter
 * 
 * Tests the query routing functionality across date-partitioned tables.
 */
class PartitionQueryRouterTest extends TestCase
{
    use DatabaseTransactions;
    
    protected PartitionQueryRouter $router;
    protected PartitionManager $partitionManager;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->partitionManager = new PartitionManager();
        $this->router = new PartitionQueryRouter($this->partitionManager);
    }
    
    /**
     * Test getting partitions in range returns empty collection when no partitions exist
     */
    public function test_get_partitions_in_range_returns_empty_when_no_partitions(): void
    {
        $startDate = Carbon::parse('2026-01-01');
        $endDate = Carbon::parse('2026-01-03');
        
        $partitions = $this->router->getPartitionsInRange($startDate, $endDate);
        
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $partitions);
        $this->assertTrue($partitions->isEmpty());
    }
    
    /**
     * Test getting partitions in range returns registered partitions
     */
    public function test_get_partitions_in_range_returns_registered_partitions(): void
    {
        // Create test partitions
        $date1 = Carbon::parse('2026-01-08');
        $date2 = Carbon::parse('2026-01-09');
        
        // Register partitions (without actually creating tables)
        PartitionRegistry::registerPartition('alerts_2026_01_08', $date1);
        PartitionRegistry::registerPartition('alerts_2026_01_09', $date2);
        
        // Mock partition existence check to return true
        $this->partitionManager = $this->createMock(PartitionManager::class);
        $this->partitionManager->method('getPartitionsInRange')
            ->willReturn(PartitionRegistry::getPartitionsInRange($date1, $date2));
        $this->partitionManager->method('partitionTableExists')
            ->willReturn(true);
        
        $this->router = new PartitionQueryRouter($this->partitionManager);
        
        $startDate = Carbon::parse('2026-01-08');
        $endDate = Carbon::parse('2026-01-09');
        
        $partitions = $this->router->getPartitionsInRange($startDate, $endDate);
        
        $this->assertEquals(2, $partitions->count());
    }
    
    /**
     * Test building WHERE conditions from filters
     */
    public function test_build_where_conditions_with_filters(): void
    {
        $filters = [
            'alert_type' => 'critical',
            'severity' => 'high',
            'terminal_id' => 'TERM001',
            'status' => 'open',
        ];
        
        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('buildWhereConditions');
        $method->setAccessible(true);
        
        $conditions = $method->invoke($this->router, $filters);
        
        $this->assertIsArray($conditions);
        $this->assertNotEmpty($conditions);
        $this->assertStringContainsString('alerttype', implode(' ', $conditions));
        $this->assertStringContainsString('priority', implode(' ', $conditions));
        $this->assertStringContainsString('panelid', implode(' ', $conditions));
        $this->assertStringContainsString('status', implode(' ', $conditions));
    }
    
    /**
     * Test escaping strings for SQL
     */
    public function test_escape_string_handles_quotes(): void
    {
        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('escapeString');
        $method->setAccessible(true);
        
        $input = "test'value";
        $escaped = $method->invoke($this->router, $input);
        
        $this->assertEquals("test''value", $escaped);
    }
    
    /**
     * Test counting returns zero when no partitions exist
     */
    public function test_count_date_range_returns_zero_when_no_partitions(): void
    {
        $startDate = Carbon::parse('2026-01-01');
        $endDate = Carbon::parse('2026-01-03');
        
        $count = $this->router->countDateRange($startDate, $endDate);
        
        $this->assertEquals(0, $count);
    }
    
    /**
     * Test checking if partitions exist in range
     */
    public function test_has_partitions_in_range(): void
    {
        $startDate = Carbon::parse('2026-01-01');
        $endDate = Carbon::parse('2026-01-03');
        
        $hasPartitions = $this->router->hasPartitionsInRange($startDate, $endDate);
        
        $this->assertFalse($hasPartitions);
    }
    
    /**
     * Test getting missing partition dates
     */
    public function test_get_missing_partition_dates(): void
    {
        $startDate = Carbon::parse('2026-01-08');
        $endDate = Carbon::parse('2026-01-10');
        
        // Create a mock partition for the middle date
        $mockPartition = new \stdClass();
        $mockPartition->table_name = 'alerts_2026_01_09';
        $mockPartition->partition_date = '2026-01-09';
        
        // Mock the partition manager to return only one partition
        $mockManager = $this->createMock(PartitionManager::class);
        $mockManager->method('getPartitionsInRange')
            ->willReturn(collect([$mockPartition]));
        $mockManager->method('partitionTableExists')
            ->willReturn(true);
        
        $router = new PartitionQueryRouter($mockManager);
        
        $missingDates = $router->getMissingPartitionDates($startDate, $endDate);
        
        // Should find 2 missing dates: 2026-01-08 and 2026-01-10
        $this->assertEquals(2, $missingDates->count());
        $this->assertTrue($missingDates->contains(function ($date) {
            return $date->toDateString() === '2026-01-08';
        }));
        $this->assertTrue($missingDates->contains(function ($date) {
            return $date->toDateString() === '2026-01-10';
        }));
    }
    
    /**
     * Test query with pagination returns correct structure
     */
    public function test_query_with_pagination_returns_correct_structure(): void
    {
        $startDate = Carbon::parse('2026-01-01');
        $endDate = Carbon::parse('2026-01-03');
        
        $result = $this->router->queryWithPagination($startDate, $endDate, [], 50, 1);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertArrayHasKey('current_page', $result['pagination']);
        $this->assertArrayHasKey('last_page', $result['pagination']);
        $this->assertArrayHasKey('per_page', $result['pagination']);
        $this->assertArrayHasKey('total', $result['pagination']);
    }
}
