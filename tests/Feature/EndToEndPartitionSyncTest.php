<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\PartitionRegistry;
use App\Services\DateExtractor;
use App\Services\DateGroupedSyncService;
use App\Services\PartitionManager;
use App\Services\PartitionQueryRouter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * End-to-End Partition Sync Test
 * 
 * Checkpoint 7: Verify end-to-end sync and query
 * 
 * This test validates the complete date-partitioned sync pipeline:
 * - Syncing alerts with multiple dates from MySQL
 * - Automatic partition creation
 * - Querying across date ranges
 * - Result completeness and correctness
 * 
 * ⚠️ CRITICAL RULES:
 * - Uses existing MySQL alerts data (READ-ONLY)
 * - NO DELETE/UPDATE operations on MySQL alerts table
 * - Keeps PostgreSQL partition data if tests pass
 * - Only cleans up on test failures if needed
 */
class EndToEndPartitionSyncTest extends TestCase
{
    private DateGroupedSyncService $syncService;
    private PartitionQueryRouter $queryRouter;
    private PartitionManager $partitionManager;
    private DateExtractor $dateExtractor;
    
    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Initialize services
        $this->dateExtractor = new DateExtractor();
        $this->partitionManager = new PartitionManager($this->dateExtractor);
        $this->syncService = new DateGroupedSyncService(
            $this->dateExtractor,
            $this->partitionManager,
            50 // Small batch size for testing
        );
        $this->queryRouter = new PartitionQueryRouter(
            $this->partitionManager,
            $this->dateExtractor
        );
        
        Log::info('=== Starting End-to-End Partition Sync Test ===');
    }
    
    /**
     * Test complete end-to-end sync and query workflow
     * 
     * This test:
     * 1. Syncs alerts with multiple dates from MySQL
     * 2. Verifies partitions are created automatically
     * 3. Tests querying across date ranges
     * 4. Verifies results are complete and correct
     * 
     * @test
     */
    public function test_end_to_end_sync_and_query_workflow()
    {
        Log::info('--- Test: End-to-End Sync and Query Workflow ---');
        
        // Step 1: Check if there are unsynced alerts in MySQL
        $unsyncedCount = Alert::unsynced()->count();
        
        if ($unsyncedCount === 0) {
            Log::warning('No unsynced alerts found in MySQL - skipping test');
            $this->markTestSkipped('No unsynced alerts available for testing');
            return;
        }
        
        Log::info("Found {$unsyncedCount} unsynced alerts in MySQL");
        
        // Step 2: Get a sample of alerts to understand date distribution
        $sampleAlerts = Alert::unsynced()
            ->orderBy('receivedtime', 'asc')
            ->limit(100)
            ->get();
        
        $dateDistribution = $this->analyzeDateDistribution($sampleAlerts);
        Log::info('Date distribution in sample', ['distribution' => $dateDistribution]);
        
        // Step 3: Sync a batch of alerts
        Log::info('Starting sync batch...');
        $syncResult = $this->syncService->syncBatch(50);
        
        // Verify sync was successful
        $this->assertTrue($syncResult->success, 'Sync batch should succeed');
        $this->assertGreaterThan(0, $syncResult->totalRecordsProcessed, 'Should process at least one record');
        $this->assertNotEmpty($syncResult->dateGroupResults, 'Should have date group results');
        
        Log::info('Sync completed', [
            'records_processed' => $syncResult->totalRecordsProcessed,
            'date_groups' => count($syncResult->dateGroupResults),
            'duration' => $syncResult->duration
        ]);
        
        // Step 4: Verify partitions were created
        $createdPartitions = [];
        foreach ($syncResult->dateGroupResults as $dateGroupResult) {
            if ($dateGroupResult->success) {
                $createdPartitions[] = $dateGroupResult->partitionTable;
                
                // Verify partition exists in database
                $exists = $this->partitionManager->partitionTableExists($dateGroupResult->partitionTable);
                $this->assertTrue($exists, "Partition {$dateGroupResult->partitionTable} should exist");
                
                // Verify partition is registered
                $registry = $this->partitionManager->getPartitionInfo($dateGroupResult->partitionTable);
                $this->assertNotNull($registry, "Partition {$dateGroupResult->partitionTable} should be registered");
                
                Log::info('Verified partition', [
                    'table' => $dateGroupResult->partitionTable,
                    'records' => $dateGroupResult->recordsInserted,
                    'registry_count' => $registry->record_count
                ]);
            }
        }
        
        $this->assertNotEmpty($createdPartitions, 'Should have created at least one partition');
        
        // Step 5: Verify data was inserted into partitions
        // Note: Partitions may already have data from previous test runs (we keep data if tests pass)
        // So we verify that records exist, not exact counts
        foreach ($syncResult->dateGroupResults as $dateGroupResult) {
            if ($dateGroupResult->success) {
                $count = DB::connection('pgsql')
                    ->table($dateGroupResult->partitionTable)
                    ->count();
                
                // Verify partition has at least the records we just inserted
                $this->assertGreaterThanOrEqual(
                    $dateGroupResult->recordsInserted,
                    $count,
                    "Partition {$dateGroupResult->partitionTable} should have at least {$dateGroupResult->recordsInserted} records"
                );
                
                Log::info('Verified partition data', [
                    'table' => $dateGroupResult->partitionTable,
                    'records_inserted_this_run' => $dateGroupResult->recordsInserted,
                    'total_records_in_partition' => $count
                ]);
            }
        }
        
        // Step 6: Test cross-partition querying
        $this->verifyCrossPartitionQuerying($syncResult);
        
        // Step 7: Verify query result completeness
        $this->verifyQueryCompleteness($syncResult);
        
        // Step 8: Test query with filters
        $this->verifyQueryWithFilters($syncResult);
        
        Log::info('=== End-to-End Test Completed Successfully ===');
    }
    
    /**
     * Verify cross-partition querying works correctly
     */
    private function verifyCrossPartitionQuerying($syncResult): void
    {
        Log::info('--- Verifying Cross-Partition Querying ---');
        
        // Get date range from synced data
        $dates = collect($syncResult->dateGroupResults)
            ->filter(fn($r) => $r->success)
            ->map(fn($r) => $r->date)
            ->sort();
        
        if ($dates->isEmpty()) {
            Log::warning('No successful date groups to query');
            return;
        }
        
        $startDate = $dates->first();
        $endDate = $dates->last();
        
        Log::info('Querying date range', [
            'start' => $startDate->toDateString(),
            'end' => $endDate->toDateString()
        ]);
        
        // Query across all partitions
        $results = $this->queryRouter->queryDateRange($startDate, $endDate);
        
        // Verify we got results
        $this->assertNotEmpty($results, 'Should get results from cross-partition query');
        
        // Verify result count includes at least the records we just synced
        // Note: May include records from previous test runs (we keep data if tests pass)
        $expectedMinCount = collect($syncResult->dateGroupResults)
            ->filter(fn($r) => $r->success)
            ->sum('recordsInserted');
        
        $this->assertGreaterThanOrEqual(
            $expectedMinCount,
            $results->count(),
            'Query should return at least the records we just synced'
        );
        
        Log::info('Cross-partition query successful', [
            'min_expected_count' => $expectedMinCount,
            'actual_count' => $results->count()
        ]);
        
        // Verify result structure
        if ($results->isNotEmpty()) {
            $firstResult = $results->first();
            $this->assertObjectHasProperty('id', $firstResult, 'Result should have id');
            $this->assertObjectHasProperty('panelid', $firstResult, 'Result should have panelid');
            $this->assertObjectHasProperty('receivedtime', $firstResult, 'Result should have receivedtime');
            $this->assertObjectHasProperty('synced_at', $firstResult, 'Result should have synced_at');
            
            Log::info('Verified result structure', [
                'sample_id' => $firstResult->id,
                'sample_panelid' => $firstResult->panelid
            ]);
        }
    }
    
    /**
     * Verify query completeness - all records are returned
     */
    private function verifyQueryCompleteness($syncResult): void
    {
        Log::info('--- Verifying Query Completeness ---');
        
        // For each date group, query that specific date and verify we get results
        foreach ($syncResult->dateGroupResults as $dateGroupResult) {
            if (!$dateGroupResult->success) {
                continue;
            }
            
            $date = $dateGroupResult->date;
            
            // Query single day
            $results = $this->queryRouter->queryDateRange($date, $date);
            
            // Verify we get at least the records we just inserted
            // Note: May include records from previous test runs
            $this->assertGreaterThanOrEqual(
                $dateGroupResult->recordsInserted,
                $results->count(),
                "Query for {$date->toDateString()} should return at least {$dateGroupResult->recordsInserted} records"
            );
            
            Log::info('Verified single-day query', [
                'date' => $date->toDateString(),
                'min_expected' => $dateGroupResult->recordsInserted,
                'actual' => $results->count()
            ]);
        }
    }
    
    /**
     * Verify querying with filters works correctly
     */
    private function verifyQueryWithFilters($syncResult): void
    {
        Log::info('--- Verifying Query with Filters ---');
        
        // Get date range
        $dates = collect($syncResult->dateGroupResults)
            ->filter(fn($r) => $r->success)
            ->map(fn($r) => $r->date)
            ->sort();
        
        if ($dates->isEmpty()) {
            Log::warning('No successful date groups to query with filters');
            return;
        }
        
        $startDate = $dates->first();
        $endDate = $dates->last();
        
        // Query without filters
        $allResults = $this->queryRouter->queryDateRange($startDate, $endDate);
        
        if ($allResults->isEmpty()) {
            Log::warning('No results to test filters with');
            return;
        }
        
        // Get a sample record to test filters
        $sampleRecord = $allResults->first();
        
        // Test filtering by panelid (terminal_id)
        if (!empty($sampleRecord->panelid)) {
            $filteredResults = $this->queryRouter->queryDateRange(
                $startDate,
                $endDate,
                ['terminal_id' => $sampleRecord->panelid]
            );
            
            // Verify all results have the correct panelid
            foreach ($filteredResults as $result) {
                $this->assertEquals(
                    $sampleRecord->panelid,
                    $result->panelid,
                    'Filtered results should match panelid filter'
                );
            }
            
            Log::info('Verified panelid filter', [
                'panelid' => $sampleRecord->panelid,
                'filtered_count' => $filteredResults->count(),
                'total_count' => $allResults->count()
            ]);
        }
        
        // Test filtering by alerttype
        if (!empty($sampleRecord->alerttype)) {
            $filteredResults = $this->queryRouter->queryDateRange(
                $startDate,
                $endDate,
                ['alert_type' => $sampleRecord->alerttype]
            );
            
            // Verify all results have the correct alerttype
            foreach ($filteredResults as $result) {
                $this->assertEquals(
                    $sampleRecord->alerttype,
                    $result->alerttype,
                    'Filtered results should match alerttype filter'
                );
            }
            
            Log::info('Verified alerttype filter', [
                'alerttype' => $sampleRecord->alerttype,
                'filtered_count' => $filteredResults->count(),
                'total_count' => $allResults->count()
            ]);
        }
    }
    
    /**
     * Analyze date distribution in alerts
     */
    private function analyzeDateDistribution($alerts): array
    {
        $distribution = [];
        
        foreach ($alerts as $alert) {
            try {
                $date = $this->dateExtractor->extractDate($alert->receivedtime);
                $dateKey = $date->toDateString();
                
                if (!isset($distribution[$dateKey])) {
                    $distribution[$dateKey] = 0;
                }
                
                $distribution[$dateKey]++;
            } catch (\Exception $e) {
                // Skip invalid dates
                continue;
            }
        }
        
        return $distribution;
    }
    
    /**
     * Test querying with pagination
     * 
     * @test
     */
    public function test_cross_partition_query_with_pagination()
    {
        Log::info('--- Test: Cross-Partition Query with Pagination ---');
        
        // Sync a batch first
        $syncResult = $this->syncService->syncBatch(50);
        
        if (!$syncResult->success || $syncResult->totalRecordsProcessed === 0) {
            $this->markTestSkipped('No data synced for pagination test');
            return;
        }
        
        // Get date range
        $dates = collect($syncResult->dateGroupResults)
            ->filter(fn($r) => $r->success)
            ->map(fn($r) => $r->date)
            ->sort();
        
        $startDate = $dates->first();
        $endDate = $dates->last();
        
        // Test pagination
        $perPage = 10;
        $page1 = $this->queryRouter->queryWithPagination($startDate, $endDate, [], $perPage, 1);
        
        $this->assertArrayHasKey('data', $page1);
        $this->assertArrayHasKey('pagination', $page1);
        $this->assertEquals(1, $page1['pagination']['current_page']);
        $this->assertLessThanOrEqual($perPage, count($page1['data']));
        
        Log::info('Pagination test successful', [
            'per_page' => $perPage,
            'total' => $page1['pagination']['total'],
            'last_page' => $page1['pagination']['last_page']
        ]);
    }
    
    /**
     * Test aggregated statistics across partitions
     * 
     * @test
     */
    public function test_aggregated_statistics_across_partitions()
    {
        Log::info('--- Test: Aggregated Statistics Across Partitions ---');
        
        // Sync a batch first
        $syncResult = $this->syncService->syncBatch(50);
        
        if (!$syncResult->success || $syncResult->totalRecordsProcessed === 0) {
            $this->markTestSkipped('No data synced for statistics test');
            return;
        }
        
        // Get date range
        $dates = collect($syncResult->dateGroupResults)
            ->filter(fn($r) => $r->success)
            ->map(fn($r) => $r->date)
            ->sort();
        
        $startDate = $dates->first();
        $endDate = $dates->last();
        
        // Get aggregated statistics
        $stats = $this->queryRouter->getAggregatedStatistics($startDate, $endDate);
        
        $this->assertArrayHasKey('by_type', $stats);
        $this->assertArrayHasKey('by_priority', $stats);
        $this->assertArrayHasKey('by_status', $stats);
        
        Log::info('Statistics test successful', [
            'by_type_count' => count($stats['by_type']),
            'by_priority_count' => count($stats['by_priority']),
            'by_status_count' => count($stats['by_status'])
        ]);
    }
    
    /**
     * Test handling of missing partitions in date range
     * 
     * @test
     */
    public function test_query_handles_missing_partitions_gracefully()
    {
        Log::info('--- Test: Query Handles Missing Partitions Gracefully ---');
        
        // Query a date range that likely has no partitions
        $futureStart = Carbon::now()->addYears(10);
        $futureEnd = $futureStart->copy()->addDays(5);
        
        // Should not throw exception
        $results = $this->queryRouter->queryDateRange($futureStart, $futureEnd);
        
        $this->assertEmpty($results, 'Query with no partitions should return empty results');
        
        Log::info('Missing partitions handled gracefully');
    }
    
    /**
     * Verify MySQL alerts table remains untouched (no deletes)
     * 
     * @test
     */
    public function test_mysql_alerts_table_remains_read_only()
    {
        Log::info('--- Test: MySQL Alerts Table Remains Read-Only ---');
        
        // Get initial count of all alerts (including synced)
        $initialCount = Alert::count();
        
        // Sync a batch
        $syncResult = $this->syncService->syncBatch(50);
        
        // Get final count
        $finalCount = Alert::count();
        
        // Verify count hasn't decreased (no deletes)
        // Note: Count may increase if new alerts are added during test (live system)
        $this->assertGreaterThanOrEqual(
            $initialCount,
            $finalCount,
            'MySQL alerts table count should not decrease (no deletes allowed)'
        );
        
        Log::info('Verified MySQL table integrity', [
            'initial_count' => $initialCount,
            'final_count' => $finalCount,
            'difference' => $finalCount - $initialCount
        ]);
    }
}
