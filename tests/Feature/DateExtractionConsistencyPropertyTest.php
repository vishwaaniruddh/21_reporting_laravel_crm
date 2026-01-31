<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\DateExtractor;
use App\Models\Alert;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-Based Test: Date Extraction Consistency
 * 
 * Feature: date-partitioned-alerts-sync, Property 1: Date Extraction Consistency
 * Validates: Requirements 1.1, 1.2, 7.3
 * 
 * Property: For any alert record with a receivedtime value, extracting the date 
 * and formatting it as a partition name should always produce the same result 
 * for the same input timestamp.
 * 
 * This test uses existing MySQL alerts data (no test data creation).
 * Max 20 iterations as specified in task requirements.
 */
class DateExtractionConsistencyPropertyTest extends TestCase
{
    private DateExtractor $extractor;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new DateExtractor('UTC');
    }
    
    /**
     * Property Test: Date extraction is consistent for the same receivedtime
     * 
     * For any receivedtime value from existing MySQL alerts:
     * 1. Extract date twice from the same receivedtime
     * 2. Format both dates as partition names
     * 3. Both partition names should be identical
     * 4. Partition name should match the format alerts_YYYY_MM_DD
     * 
     * @test
     */
    public function test_date_extraction_consistency_property(): void
    {
        // Fetch up to 20 alerts from MySQL with non-null receivedtime
        $alerts = Alert::on('mysql')
            ->whereNotNull('receivedtime')
            ->limit(20)
            ->get();
        
        // Skip test if no alerts available
        if ($alerts->isEmpty()) {
            $this->markTestSkipped('No alerts with receivedtime found in MySQL database');
        }
        
        $iterationCount = 0;
        
        foreach ($alerts as $alert) {
            $iterationCount++;
            
            // Get the receivedtime as a string
            $receivedtime = $alert->receivedtime;
            
            // Property: Extract date twice from the same receivedtime
            $date1 = $this->extractor->extractDate($receivedtime);
            $date2 = $this->extractor->extractDate($receivedtime);
            
            // Property: Format both dates as partition names
            $partition1 = $this->extractor->formatPartitionName($date1);
            $partition2 = $this->extractor->formatPartitionName($date2);
            
            // Assertion 1: Both partition names should be identical (consistency)
            $this->assertEquals(
                $partition1,
                $partition2,
                "Date extraction should be consistent for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            
            // Assertion 2: Partition name should match the required format
            $this->assertMatchesRegularExpression(
                '/^alerts_\d{4}_\d{2}_\d{2}$/',
                $partition1,
                "Partition name should match format alerts_YYYY_MM_DD for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            
            // Assertion 3: Verify zero-padding in month and day
            $parts = explode('_', $partition1);
            $this->assertCount(4, $parts, "Partition name should have 4 parts separated by underscores");
            $this->assertEquals('alerts', $parts[0], "First part should be 'alerts'");
            $this->assertEquals(4, strlen($parts[1]), "Year should be 4 digits");
            $this->assertEquals(2, strlen($parts[2]), "Month should be 2 digits (zero-padded)");
            $this->assertEquals(2, strlen($parts[3]), "Day should be 2 digits (zero-padded)");
            
            // Assertion 4: Verify the extracted date matches the original receivedtime date
            $originalDate = \Carbon\Carbon::parse($receivedtime, 'UTC');
            $this->assertEquals(
                $originalDate->format('Y-m-d'),
                $date1->format('Y-m-d'),
                "Extracted date should match the original receivedtime date (Alert ID: {$alert->id})"
            );
        }
        
        // Verify we tested at least some records
        $this->assertGreaterThan(
            0,
            $iterationCount,
            "Should have tested at least one alert record"
        );
        
        // Log the number of iterations for transparency
        echo "\nTested date extraction consistency across {$iterationCount} alert records from MySQL\n";
    }
    
    /**
     * Property Test: Timezone handling is consistent
     * 
     * For any receivedtime value, the timezone conversion should be consistent
     * and the partition name should be based on the UTC date.
     * 
     * @test
     */
    public function test_timezone_consistency_property(): void
    {
        // Fetch up to 20 alerts from MySQL with non-null receivedtime
        $alerts = Alert::on('mysql')
            ->whereNotNull('receivedtime')
            ->limit(20)
            ->get();
        
        // Skip test if no alerts available
        if ($alerts->isEmpty()) {
            $this->markTestSkipped('No alerts with receivedtime found in MySQL database');
        }
        
        $iterationCount = 0;
        
        foreach ($alerts as $alert) {
            $iterationCount++;
            
            $receivedtime = $alert->receivedtime;
            
            // Extract date with UTC timezone
            $date = $this->extractor->extractDate($receivedtime);
            
            // Property: Timezone should always be UTC
            $this->assertEquals(
                'UTC',
                $date->getTimezone()->getName(),
                "Extracted date should be in UTC timezone for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            
            // Property: Multiple extractions should produce same timezone
            $date2 = $this->extractor->extractDate($receivedtime);
            $this->assertEquals(
                $date->getTimezone()->getName(),
                $date2->getTimezone()->getName(),
                "Timezone should be consistent across multiple extractions (Alert ID: {$alert->id})"
            );
        }
        
        echo "\nTested timezone consistency across {$iterationCount} alert records from MySQL\n";
    }
    
    /**
     * Property Test: Round-trip consistency (parse then format)
     * 
     * For any partition name generated from a receivedtime, parsing it back
     * should yield a date that produces the same partition name.
     * 
     * @test
     */
    public function test_round_trip_consistency_property(): void
    {
        // Fetch up to 20 alerts from MySQL with non-null receivedtime
        $alerts = Alert::on('mysql')
            ->whereNotNull('receivedtime')
            ->limit(20)
            ->get();
        
        // Skip test if no alerts available
        if ($alerts->isEmpty()) {
            $this->markTestSkipped('No alerts with receivedtime found in MySQL database');
        }
        
        $iterationCount = 0;
        
        foreach ($alerts as $alert) {
            $iterationCount++;
            
            $receivedtime = $alert->receivedtime;
            
            // Extract date and format as partition name
            $date = $this->extractor->extractDate($receivedtime);
            $partitionName = $this->extractor->formatPartitionName($date);
            
            // Parse the partition name back to a date
            $parsedDate = $this->extractor->parsePartitionName($partitionName);
            
            // Format the parsed date as a partition name again
            $roundTripPartitionName = $this->extractor->formatPartitionName($parsedDate);
            
            // Property: Round-trip should produce the same partition name
            $this->assertEquals(
                $partitionName,
                $roundTripPartitionName,
                "Round-trip (format -> parse -> format) should be consistent for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            
            // Property: The dates should represent the same day
            $this->assertEquals(
                $date->format('Y-m-d'),
                $parsedDate->format('Y-m-d'),
                "Parsed date should represent the same day as original date (Alert ID: {$alert->id})"
            );
        }
        
        echo "\nTested round-trip consistency across {$iterationCount} alert records from MySQL\n";
    }
}
