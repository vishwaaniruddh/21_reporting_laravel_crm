<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\DateExtractor;
use Carbon\Carbon;

/**
 * Unit tests for DateExtractor service
 * 
 * Tests verify:
 * - Date extraction from receivedtime strings
 * - Partition name formatting (YYYY_MM_DD)
 * - Timezone handling consistency
 * - Partition name validation and sanitization
 * 
 * Requirements: 1.1, 1.2, 7.1, 7.2, 7.3, 7.5
 */
class DateExtractorTest extends TestCase
{
    private DateExtractor $extractor;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new DateExtractor('UTC');
    }
    
    /**
     * Test: Extract date from valid timestamp string
     */
    public function test_extract_date_from_valid_timestamp(): void
    {
        $receivedtime = '2026-01-08 14:30:45';
        $date = $this->extractor->extractDate($receivedtime);
        
        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertEquals('2026', $date->format('Y'));
        $this->assertEquals('01', $date->format('m'));
        $this->assertEquals('08', $date->format('d'));
    }
    
    /**
     * Test: Format partition name with zero-padded values
     */
    public function test_format_partition_name_with_zero_padding(): void
    {
        $date = Carbon::create(2026, 1, 8, 0, 0, 0, 'UTC');
        $partitionName = $this->extractor->formatPartitionName($date);
        
        $this->assertEquals('alerts_2026_01_08', $partitionName);
    }
    
    /**
     * Test: Format partition name for double-digit month and day
     */
    public function test_format_partition_name_double_digits(): void
    {
        $date = Carbon::create(2026, 12, 25, 0, 0, 0, 'UTC');
        $partitionName = $this->extractor->formatPartitionName($date);
        
        $this->assertEquals('alerts_2026_12_25', $partitionName);
    }
    
    /**
     * Test: Get partition table name directly from receivedtime
     */
    public function test_get_partition_table_name_from_receivedtime(): void
    {
        $receivedtime = '2026-01-08 14:30:45';
        $partitionName = $this->extractor->getPartitionTableName($receivedtime);
        
        $this->assertEquals('alerts_2026_01_08', $partitionName);
    }
    
    /**
     * Test: Parse partition name back to date
     */
    public function test_parse_partition_name_to_date(): void
    {
        $partitionName = 'alerts_2026_01_08';
        $date = $this->extractor->parsePartitionName($partitionName);
        
        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertEquals('2026-01-08', $date->format('Y-m-d'));
    }
    
    /**
     * Test: Validate correct partition name format
     */
    public function test_validate_correct_partition_name(): void
    {
        $validNames = [
            'alerts_2026_01_08',
            'alerts_2025_12_31',
            'alerts_2024_02_29', // Leap year
        ];
        
        foreach ($validNames as $name) {
            $this->assertTrue(
                $this->extractor->isValidPartitionName($name),
                "Expected '{$name}' to be valid"
            );
        }
    }
    
    /**
     * Test: Reject invalid partition name formats
     */
    public function test_reject_invalid_partition_names(): void
    {
        $invalidNames = [
            'alerts_2026_1_8',      // Missing zero-padding
            'alerts_2026-01-08',    // Wrong separator
            'alerts_26_01_08',      // Two-digit year
            'alerts_2026_13_01',    // Invalid month
            'alerts_2026_01_32',    // Invalid day
            'alerts_2026_01_08; DROP TABLE alerts;', // SQL injection attempt
            'alerts_2026_01_08\'',  // Quote character
        ];
        
        foreach ($invalidNames as $name) {
            $this->assertFalse(
                $this->extractor->isValidPartitionName($name),
                "Expected '{$name}' to be invalid"
            );
        }
    }
    
    /**
     * Test: Sanitize partition name removes dangerous characters
     */
    public function test_sanitize_partition_name(): void
    {
        $input = 'alerts_2026_01_08; DROP TABLE';
        $sanitized = $this->extractor->sanitizePartitionName($input);
        
        $this->assertEquals('alerts_2026_01_08DROPTABLE', $sanitized);
        $this->assertStringNotContainsString(';', $sanitized);
        $this->assertStringNotContainsString(' ', $sanitized);
    }
    
    /**
     * Test: Date extraction is consistent for same input
     */
    public function test_date_extraction_consistency(): void
    {
        $receivedtime = '2026-01-08 14:30:45';
        
        $date1 = $this->extractor->extractDate($receivedtime);
        $date2 = $this->extractor->extractDate($receivedtime);
        
        $partition1 = $this->extractor->formatPartitionName($date1);
        $partition2 = $this->extractor->formatPartitionName($date2);
        
        $this->assertEquals($partition1, $partition2);
    }
    
    /**
     * Test: Timezone handling is consistent
     */
    public function test_timezone_handling_consistency(): void
    {
        $extractor = new DateExtractor('UTC');
        
        // Same moment in time, different representations
        $utcTime = '2026-01-08 14:30:45';
        $date = $extractor->extractDate($utcTime);
        
        $this->assertEquals('UTC', $date->getTimezone()->getName());
        $this->assertEquals('alerts_2026_01_08', $extractor->formatPartitionName($date));
    }
    
    /**
     * Test: Get partition prefix
     */
    public function test_get_partition_prefix(): void
    {
        $prefix = $this->extractor->getPartitionPrefix();
        $this->assertEquals('alerts_', $prefix);
    }
    
    /**
     * Test: Get partition date format
     */
    public function test_get_partition_date_format(): void
    {
        $format = $this->extractor->getPartitionDateFormat();
        $this->assertEquals('Y_m_d', $format);
    }
    
    /**
     * Test: Get configured timezone
     */
    public function test_get_timezone(): void
    {
        $timezone = $this->extractor->getTimezone();
        $this->assertEquals('UTC', $timezone);
    }
}
