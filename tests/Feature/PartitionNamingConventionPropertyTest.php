<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\DateExtractor;
use App\Models\Alert;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-Based Test: Partition Naming Convention Compliance
 * 
 * Feature: date-partitioned-alerts-sync, Property 7: Partition Naming Convention Compliance
 * Validates: Requirements 7.1, 7.2, 7.5
 * 
 * Property: For any partition table created by the system, the table name should 
 * match the format alerts_YYYY_MM_DD with zero-padded month and day values.
 * 
 * This test uses existing MySQL alerts data (no test data creation).
 * Max 20 iterations as specified in task requirements.
 */
class PartitionNamingConventionPropertyTest extends TestCase
{
    private DateExtractor $extractor;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new DateExtractor('UTC');
    }
    
    /**
     * Property Test: Partition names follow the required format
     * 
     * For any receivedtime value from existing MySQL alerts:
     * 1. Extract date and generate partition name
     * 2. Verify format matches alerts_YYYY_MM_DD
     * 3. Verify year is 4 digits
     * 4. Verify month is 2 digits (zero-padded)
     * 5. Verify day is 2 digits (zero-padded)
     * 6. Verify no SQL injection patterns
     * 
     * Requirements: 7.1, 7.2, 7.5
     * 
     * @test
     */
    public function test_partition_naming_convention_compliance_property(): void
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
            
            // Extract date and generate partition name
            $date = $this->extractor->extractDate($receivedtime);
            $partitionName = $this->extractor->formatPartitionName($date);
            
            // Property 1: Partition name must match the format alerts_YYYY_MM_DD
            // Requirement 7.1
            $this->assertMatchesRegularExpression(
                '/^alerts_\d{4}_\d{2}_\d{2}$/',
                $partitionName,
                "Partition name must match format alerts_YYYY_MM_DD for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            
            // Property 2: Partition name must start with "alerts_"
            // Requirement 7.1
            $this->assertStringStartsWith(
                'alerts_',
                $partitionName,
                "Partition name must start with 'alerts_' for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            
            // Property 3: Verify structure has exactly 4 parts separated by underscores
            $parts = explode('_', $partitionName);
            $this->assertCount(
                4,
                $parts,
                "Partition name must have 4 parts (alerts, YYYY, MM, DD) for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            
            // Property 4: First part must be "alerts"
            $this->assertEquals(
                'alerts',
                $parts[0],
                "First part must be 'alerts' for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            
            // Property 5: Year must be exactly 4 digits
            // Requirement 7.1
            $this->assertEquals(
                4,
                strlen($parts[1]),
                "Year must be 4 digits for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            $this->assertMatchesRegularExpression(
                '/^\d{4}$/',
                $parts[1],
                "Year must be numeric 4 digits for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            
            // Property 6: Month must be exactly 2 digits (zero-padded)
            // Requirement 7.2
            $this->assertEquals(
                2,
                strlen($parts[2]),
                "Month must be 2 digits (zero-padded) for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            $this->assertMatchesRegularExpression(
                '/^(0[1-9]|1[0-2])$/',
                $parts[2],
                "Month must be zero-padded 01-12 for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            
            // Property 7: Day must be exactly 2 digits (zero-padded)
            // Requirement 7.2
            $this->assertEquals(
                2,
                strlen($parts[3]),
                "Day must be 2 digits (zero-padded) for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            $this->assertMatchesRegularExpression(
                '/^(0[1-9]|[12][0-9]|3[01])$/',
                $parts[3],
                "Day must be zero-padded 01-31 for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            
            // Property 8: Partition name must be valid according to validator
            // Requirement 7.5
            $this->assertTrue(
                $this->extractor->isValidPartitionName($partitionName),
                "Partition name must pass validation for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            
            // Property 9: Partition name must not contain SQL injection patterns
            // Requirement 7.5
            $this->assertDoesNotMatchRegularExpression(
                '/[;\'"\\-]/',
                $partitionName,
                "Partition name must not contain SQL injection characters for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            
            // Property 10: Partition name must only contain alphanumeric and underscore
            // Requirement 7.5
            $this->assertMatchesRegularExpression(
                '/^[a-zA-Z0-9_]+$/',
                $partitionName,
                "Partition name must only contain alphanumeric and underscore for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            
            // Property 11: Verify the date components match the original receivedtime
            $originalDate = \Carbon\Carbon::parse($receivedtime, 'UTC');
            $this->assertEquals(
                $originalDate->format('Y'),
                $parts[1],
                "Year in partition name must match receivedtime year (Alert ID: {$alert->id})"
            );
            $this->assertEquals(
                $originalDate->format('m'),
                $parts[2],
                "Month in partition name must match receivedtime month (Alert ID: {$alert->id})"
            );
            $this->assertEquals(
                $originalDate->format('d'),
                $parts[3],
                "Day in partition name must match receivedtime day (Alert ID: {$alert->id})"
            );
        }
        
        // Verify we tested at least some records
        $this->assertGreaterThan(
            0,
            $iterationCount,
            "Should have tested at least one alert record"
        );
        
        // Log the number of iterations for transparency
        echo "\nTested partition naming convention compliance across {$iterationCount} alert records from MySQL\n";
    }
    
    /**
     * Property Test: Zero-padding is consistent across all dates
     * 
     * For any receivedtime value, single-digit months and days must be zero-padded.
     * This specifically tests Requirement 7.2.
     * 
     * @test
     */
    public function test_zero_padding_consistency_property(): void
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
            $date = $this->extractor->extractDate($receivedtime);
            $partitionName = $this->extractor->formatPartitionName($date);
            
            // Extract date components
            $parts = explode('_', $partitionName);
            $month = $parts[2];
            $day = $parts[3];
            
            // Property: Month must always be 2 digits
            $this->assertEquals(
                2,
                strlen($month),
                "Month must always be 2 digits (zero-padded if needed) for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            
            // Property: Day must always be 2 digits
            $this->assertEquals(
                2,
                strlen($day),
                "Day must always be 2 digits (zero-padded if needed) for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            
            // Property: If month is single digit in original date, it must be zero-padded
            $originalMonth = (int)$date->format('n'); // n = month without leading zeros
            if ($originalMonth < 10) {
                $this->assertStringStartsWith(
                    '0',
                    $month,
                    "Single-digit month must be zero-padded for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
                );
            }
            
            // Property: If day is single digit in original date, it must be zero-padded
            $originalDay = (int)$date->format('j'); // j = day without leading zeros
            if ($originalDay < 10) {
                $this->assertStringStartsWith(
                    '0',
                    $day,
                    "Single-digit day must be zero-padded for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
                );
            }
        }
        
        echo "\nTested zero-padding consistency across {$iterationCount} alert records from MySQL\n";
    }
    
    /**
     * Property Test: Partition name validation prevents SQL injection
     * 
     * For any partition name generated from real data, it must pass validation
     * and not contain any SQL injection patterns.
     * This specifically tests Requirement 7.5.
     * 
     * @test
     */
    public function test_sql_injection_prevention_property(): void
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
        
        // SQL injection patterns to check for
        $dangerousPatterns = [
            '/[;\'"\\\\]/',           // Quotes and semicolons
            '/--/',                   // SQL comments
            '/\/\*/',                 // Multi-line comments
            '/\bOR\b/i',              // OR keyword
            '/\bAND\b/i',             // AND keyword
            '/\bUNION\b/i',           // UNION keyword
            '/\bSELECT\b/i',          // SELECT keyword
            '/\bINSERT\b/i',          // INSERT keyword
            '/\bUPDATE\b/i',          // UPDATE keyword
            '/\bDELETE\b/i',          // DELETE keyword
            '/\bDROP\b/i',            // DROP keyword
        ];
        
        foreach ($alerts as $alert) {
            $iterationCount++;
            
            $receivedtime = $alert->receivedtime;
            $date = $this->extractor->extractDate($receivedtime);
            $partitionName = $this->extractor->formatPartitionName($date);
            
            // Property: Partition name must not contain any SQL injection patterns
            foreach ($dangerousPatterns as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $partitionName,
                    "Partition name must not contain SQL injection pattern {$pattern} for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
                );
            }
            
            // Property: Partition name must pass validation
            $this->assertTrue(
                $this->extractor->isValidPartitionName($partitionName),
                "Partition name must pass validation to prevent SQL injection for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            
            // Property: Sanitized version should equal original (no dangerous chars to remove)
            $sanitized = $this->extractor->sanitizePartitionName($partitionName);
            $this->assertEquals(
                $partitionName,
                $sanitized,
                "Partition name should not need sanitization (should be clean) for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
        }
        
        echo "\nTested SQL injection prevention across {$iterationCount} alert records from MySQL\n";
    }
    
    /**
     * Property Test: Partition names are consistent with timezone handling
     * 
     * For any receivedtime value, the partition name should be based on the UTC date.
     * This tests that timezone conversion is handled consistently (Requirement 7.4).
     * 
     * @test
     */
    public function test_timezone_based_naming_consistency_property(): void
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
            $partitionName = $this->extractor->formatPartitionName($date);
            
            // Property: The partition name should be based on UTC date
            $utcDate = \Carbon\Carbon::parse($receivedtime, 'UTC');
            $expectedPartitionName = 'alerts_' . $utcDate->format('Y_m_d');
            
            $this->assertEquals(
                $expectedPartitionName,
                $partitionName,
                "Partition name should be based on UTC date for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
            
            // Property: Timezone should be UTC
            $this->assertEquals(
                'UTC',
                $date->getTimezone()->getName(),
                "Extracted date should be in UTC timezone for receivedtime: {$receivedtime} (Alert ID: {$alert->id})"
            );
        }
        
        echo "\nTested timezone-based naming consistency across {$iterationCount} alert records from MySQL\n";
    }
}
