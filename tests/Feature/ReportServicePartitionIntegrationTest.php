<?php

namespace Tests\Feature;

use App\Services\ReportService;
use App\Services\PartitionQueryRouter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for ReportService with PartitionQueryRouter
 * 
 * Tests the integration between ReportService and PartitionQueryRouter
 * to ensure proper routing and backward compatibility.
 */
class ReportServicePartitionIntegrationTest extends TestCase
{
    /**
     * Test that ReportService can be instantiated with PartitionQueryRouter
     */
    public function test_report_service_instantiation_with_partition_router(): void
    {
        $partitionRouter = new PartitionQueryRouter();
        $reportService = new ReportService($partitionRouter, true);
        
        $this->assertInstanceOf(ReportService::class, $reportService);
    }
    
    /**
     * Test that ReportService can be instantiated without PartitionQueryRouter
     */
    public function test_report_service_instantiation_without_partition_router(): void
    {
        $reportService = new ReportService();
        
        $this->assertInstanceOf(ReportService::class, $reportService);
    }
    
    /**
     * Test that ReportService can generate daily report
     */
    public function test_generate_daily_report(): void
    {
        $reportService = new ReportService();
        $date = Carbon::now()->subDays(1);
        
        $report = $reportService->generateDailyReport($date);
        
        $this->assertIsArray($report);
        $this->assertArrayHasKey('metadata', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('statistics', $report);
        $this->assertArrayHasKey('generated_at', $report['metadata']);
        $this->assertArrayHasKey('date_range', $report['metadata']);
    }
    
    /**
     * Test that ReportService can generate summary report
     */
    public function test_generate_summary_report(): void
    {
        $reportService = new ReportService();
        $startDate = Carbon::now()->subDays(7);
        $endDate = Carbon::now();
        
        $report = $reportService->generateSummaryReport($startDate, $endDate);
        
        $this->assertIsArray($report);
        $this->assertArrayHasKey('metadata', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('statistics', $report);
        $this->assertArrayHasKey('total_alerts', $report['summary']);
    }
    
    /**
     * Test that ReportService can get filtered alerts
     */
    public function test_get_filtered_alerts(): void
    {
        $reportService = new ReportService();
        
        $filters = [
            'date_from' => Carbon::now()->subDays(7)->toDateString(),
            'date_to' => Carbon::now()->toDateString(),
        ];
        
        $result = $reportService->getFilteredAlerts($filters, 10, 1);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertArrayHasKey('current_page', $result['pagination']);
        $this->assertArrayHasKey('total', $result['pagination']);
    }
    
    /**
     * Test that ReportService can export to CSV
     */
    public function test_export_to_csv(): void
    {
        $reportService = new ReportService();
        
        $filters = [
            'date_from' => Carbon::now()->subDays(1)->toDateString(),
            'date_to' => Carbon::now()->toDateString(),
        ];
        
        $csv = $reportService->exportToCsv($filters, 10);
        
        $this->assertIsString($csv);
        $this->assertStringContainsString('ID', $csv); // Header row
    }
    
    /**
     * Test that ReportService can get filter options
     */
    public function test_get_filter_options(): void
    {
        $reportService = new ReportService();
        
        $options = $reportService->getFilterOptions();
        
        $this->assertIsArray($options);
        $this->assertArrayHasKey('alert_types', $options);
        $this->assertArrayHasKey('priorities', $options);
        $this->assertArrayHasKey('statuses', $options);
    }
    
    /**
     * Test that ReportService handles partition router errors gracefully
     */
    public function test_partition_router_error_handling(): void
    {
        // Create a mock partition router that throws an exception
        $mockRouter = $this->createMock(PartitionQueryRouter::class);
        $mockRouter->method('hasPartitionsInRange')
            ->willThrowException(new \Exception('Test error'));
        
        $reportService = new ReportService($mockRouter, true);
        $startDate = Carbon::now()->subDays(7);
        $endDate = Carbon::now();
        
        // Should fall back to single-table query without throwing exception
        $report = $reportService->generateSummaryReport($startDate, $endDate);
        
        $this->assertIsArray($report);
        $this->assertArrayHasKey('metadata', $report);
    }
    
    /**
     * Test that ReportService includes partition usage metadata
     */
    public function test_partition_usage_metadata(): void
    {
        $reportService = new ReportService();
        $startDate = Carbon::now()->subDays(7);
        $endDate = Carbon::now();
        
        $report = $reportService->generateSummaryReport($startDate, $endDate);
        
        $this->assertIsArray($report);
        $this->assertArrayHasKey('metadata', $report);
        $this->assertArrayHasKey('used_partitions', $report['metadata']);
        $this->assertIsBool($report['metadata']['used_partitions']);
    }
}
