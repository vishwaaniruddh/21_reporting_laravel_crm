<?php

namespace Tests\Unit;

use App\Models\Alert;
use App\Services\DateExtractor;
use App\Services\DateGroupedSyncService;
use App\Services\PartitionManager;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Unit tests for DateGroupedSyncService
 * 
 * Tests the core functionality of date-grouped sync operations:
 * - Grouping alerts by date
 * - Batch fetching
 * - Service configuration
 */
class DateGroupedSyncServiceTest extends TestCase
{
    
    private DateGroupedSyncService $service;
    private DateExtractor $dateExtractor;
    private PartitionManager $partitionManager;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->dateExtractor = new DateExtractor();
        $this->partitionManager = new PartitionManager($this->dateExtractor);
        $this->service = new DateGroupedSyncService(
            $this->dateExtractor,
            $this->partitionManager
        );
    }
    
    /**
     * Test that service can be instantiated with default dependencies
     */
    public function test_service_instantiation_with_defaults(): void
    {
        $service = new DateGroupedSyncService();
        
        $this->assertInstanceOf(DateGroupedSyncService::class, $service);
        $this->assertIsInt($service->getBatchSize());
        $this->assertGreaterThan(0, $service->getBatchSize());
    }
    
    /**
     * Test that service can be instantiated with custom dependencies
     */
    public function test_service_instantiation_with_custom_dependencies(): void
    {
        $dateExtractor = new DateExtractor();
        $partitionManager = new PartitionManager($dateExtractor);
        $batchSize = 5000;
        
        $service = new DateGroupedSyncService($dateExtractor, $partitionManager, $batchSize);
        
        $this->assertInstanceOf(DateGroupedSyncService::class, $service);
        $this->assertEquals($batchSize, $service->getBatchSize());
    }
    
    /**
     * Test batch size configuration
     */
    public function test_batch_size_configuration(): void
    {
        $this->service->setBatchSize(2000);
        $this->assertEquals(2000, $this->service->getBatchSize());
        
        // Test clamping to minimum
        $this->service->setBatchSize(0);
        $this->assertEquals(1, $this->service->getBatchSize());
        
        // Test clamping to maximum
        $this->service->setBatchSize(100000);
        $this->assertEquals(50000, $this->service->getBatchSize());
    }
    
    /**
     * Test grouping alerts by date
     */
    public function test_group_alerts_by_date(): void
    {
        // Create mock alerts with different dates
        $alerts = collect([
            $this->createMockAlert(1, '2026-01-08 10:00:00'),
            $this->createMockAlert(2, '2026-01-08 11:00:00'),
            $this->createMockAlert(3, '2026-01-09 10:00:00'),
            $this->createMockAlert(4, '2026-01-09 11:00:00'),
            $this->createMockAlert(5, '2026-01-10 10:00:00'),
        ]);
        
        $dateGroups = $this->service->groupAlertsByDate($alerts);
        
        // Should have 3 date groups
        $this->assertCount(3, $dateGroups);
        
        // Check 2026-01-08 group
        $this->assertArrayHasKey('2026-01-08', $dateGroups);
        $this->assertCount(2, $dateGroups['2026-01-08']);
        $this->assertEquals(1, $dateGroups['2026-01-08'][0]->id);
        $this->assertEquals(2, $dateGroups['2026-01-08'][1]->id);
        
        // Check 2026-01-09 group
        $this->assertArrayHasKey('2026-01-09', $dateGroups);
        $this->assertCount(2, $dateGroups['2026-01-09']);
        $this->assertEquals(3, $dateGroups['2026-01-09'][0]->id);
        $this->assertEquals(4, $dateGroups['2026-01-09'][1]->id);
        
        // Check 2026-01-10 group
        $this->assertArrayHasKey('2026-01-10', $dateGroups);
        $this->assertCount(1, $dateGroups['2026-01-10']);
        $this->assertEquals(5, $dateGroups['2026-01-10'][0]->id);
    }
    
    /**
     * Test grouping alerts with same date
     */
    public function test_group_alerts_with_same_date(): void
    {
        $alerts = collect([
            $this->createMockAlert(1, '2026-01-08 10:00:00'),
            $this->createMockAlert(2, '2026-01-08 11:00:00'),
            $this->createMockAlert(3, '2026-01-08 12:00:00'),
        ]);
        
        $dateGroups = $this->service->groupAlertsByDate($alerts);
        
        // Should have 1 date group
        $this->assertCount(1, $dateGroups);
        $this->assertArrayHasKey('2026-01-08', $dateGroups);
        $this->assertCount(3, $dateGroups['2026-01-08']);
    }
    
    /**
     * Test grouping empty collection
     */
    public function test_group_empty_collection(): void
    {
        $alerts = collect([]);
        
        $dateGroups = $this->service->groupAlertsByDate($alerts);
        
        $this->assertIsArray($dateGroups);
        $this->assertEmpty($dateGroups);
    }
    
    /**
     * Test grouping alerts with different times on same date
     */
    public function test_group_alerts_different_times_same_date(): void
    {
        $alerts = collect([
            $this->createMockAlert(1, '2026-01-08 00:00:00'),
            $this->createMockAlert(2, '2026-01-08 12:00:00'),
            $this->createMockAlert(3, '2026-01-08 23:59:59'),
        ]);
        
        $dateGroups = $this->service->groupAlertsByDate($alerts);
        
        // All should be in same date group
        $this->assertCount(1, $dateGroups);
        $this->assertArrayHasKey('2026-01-08', $dateGroups);
        $this->assertCount(3, $dateGroups['2026-01-08']);
    }
    
    /**
     * Test unsynced count methods
     */
    public function test_unsynced_count_methods(): void
    {
        // These methods query the database, so we just test they return integers
        $count = $this->service->getUnsyncedCount();
        $this->assertIsInt($count);
        
        $hasRecords = $this->service->hasRecordsToSync();
        $this->assertIsBool($hasRecords);
    }
    
    /**
     * Helper method to create a mock alert object
     */
    private function createMockAlert(int $id, string $receivedtime): object
    {
        return (object) [
            'id' => $id,
            'panelid' => 'PANEL' . $id,
            'seqno' => (string) $id,
            'zone' => 'Zone ' . $id,
            'alarm' => 'Test Alarm ' . $id,
            'createtime' => $receivedtime,
            'receivedtime' => $receivedtime,
            'comment' => 'Test comment',
            'status' => 'active',
            'sendtoclient' => 'yes',
            'closedBy' => null,
            'closedtime' => null,
            'sendip' => '192.168.1.' . $id,
            'alerttype' => 'test',
            'location' => 'Test Location',
            'priority' => 'high',
            'AlertUserStatus' => 'new',
            'level' => '1',
            'sip2' => null,
            'c_status' => 'open',
            'auto_alert' => 'no',
            'critical_alerts' => 'no',
            'Readstatus' => 'unread',
        ];
    }
}
