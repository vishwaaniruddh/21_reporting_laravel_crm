<?php

namespace App\Services;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * DateExtractor
 * 
 * Handles date extraction from MySQL receivedtime column and partition naming.
 * This service is responsible for:
 * - Extracting dates from timestamp strings
 * - Formatting dates as YYYY_MM_DD for partition table names
 * - Handling timezone conversions consistently (UTC)
 * - Validating and sanitizing partition names
 * 
 * Requirements: 1.1, 1.2, 7.1, 7.2, 7.3, 7.5
 */
class DateExtractor
{
    /**
     * Partition table name prefix
     */
    private const PARTITION_PREFIX = 'alerts_';
    
    /**
     * Partition date format (YYYY_MM_DD)
     */
    private const PARTITION_DATE_FORMAT = 'Y_m_d';
    
    /**
     * Regex pattern for validating partition table names
     */
    private const PARTITION_NAME_PATTERN = '/^alerts_\d{4}_\d{2}_\d{2}$/';
    
    /**
     * Application timezone (UTC by default)
     */
    private string $timezone;
    
    /**
     * Create a new DateExtractor instance
     * 
     * @param string|null $timezone Optional timezone override (defaults to app timezone)
     */
    public function __construct(?string $timezone = null)
    {
        $this->timezone = $timezone ?? config('app.timezone', 'UTC');
    }
    
    /**
     * Extract date from MySQL receivedtime column
     * 
     * Converts the receivedtime string to a Carbon instance in the application timezone.
     * Handles various timestamp formats and ensures consistent timezone handling.
     * 
     * Requirements: 1.1, 7.3
     * 
     * @param string $receivedtime The timestamp string from MySQL
     * @return Carbon The extracted date in application timezone
     * @throws Exception If the timestamp cannot be parsed
     */
    public function extractDate(string $receivedtime): Carbon
    {
        try {
            // Parse the timestamp and convert to application timezone
            $date = Carbon::parse($receivedtime, $this->timezone);
            
            // Ensure we're working in the correct timezone
            $date->setTimezone($this->timezone);
            
            return $date;
            
        } catch (Exception $e) {
            Log::error('Failed to extract date from receivedtime', [
                'receivedtime' => $receivedtime,
                'timezone' => $this->timezone,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception(
                "Failed to extract date from receivedtime '{$receivedtime}': {$e->getMessage()}",
                0,
                $e
            );
        }
    }
    
    /**
     * Format date as partition table name
     * 
     * Converts a Carbon date instance to a partition table name in the format:
     * alerts_YYYY_MM_DD (e.g., alerts_2026_01_08)
     * backalerts_YYYY_MM_DD (e.g., backalerts_2026_01_08)
     * 
     * Uses zero-padded month and day values as required.
     * 
     * Requirements: 1.2, 7.1, 7.2
     * 
     * @param Carbon $date The date to format
     * @param string $tablePrefix The table prefix (default: 'alerts')
     * @return string The partition table name (e.g., "alerts_2026_01_08" or "backalerts_2026_01_08")
     */
    public function formatPartitionName(Carbon $date, string $tablePrefix = 'alerts'): string
    {
        // Format date as YYYY_MM_DD with zero-padding
        $formattedDate = $date->format(self::PARTITION_DATE_FORMAT);
        
        // Combine prefix with formatted date
        $partitionName = $tablePrefix . '_' . $formattedDate;
        
        // Validate the generated name
        if (!$this->isValidPartitionName($partitionName, $tablePrefix)) {
            Log::error('Generated invalid partition name', [
                'partition_name' => $partitionName,
                'table_prefix' => $tablePrefix,
                'date' => $date->toDateTimeString()
            ]);
            
            throw new Exception("Generated invalid partition name: {$partitionName}");
        }
        
        return $partitionName;
    }
    
    /**
     * Parse partition table name to extract date
     * 
     * Converts a partition table name back to a Carbon date instance.
     * Useful for querying and partition management operations.
     * 
     * @param string $tableName The partition table name (e.g., "alerts_2026_01_08" or "backalerts_2026_01_08")
     * @return Carbon The extracted date
     * @throws Exception If the table name is invalid or cannot be parsed
     */
    public function parsePartitionName(string $tableName): Carbon
    {
        // Detect table prefix
        $tablePrefix = $this->detectTablePrefix($tableName);
        
        // Validate the table name format
        if (!$this->isValidPartitionName($tableName, $tablePrefix)) {
            throw new Exception("Invalid partition table name format: {$tableName}");
        }
        
        try {
            // Remove prefix to get date part
            $prefixLength = strlen($tablePrefix) + 1; // +1 for underscore
            $datePart = substr($tableName, $prefixLength);
            
            // Replace underscores with hyphens for Carbon parsing
            $dateString = str_replace('_', '-', $datePart);
            
            // Parse the date
            $date = Carbon::createFromFormat('Y-m-d', $dateString, $this->timezone);
            
            // Set to start of day for consistency
            $date->startOfDay();
            
            return $date;
            
        } catch (Exception $e) {
            Log::error('Failed to parse partition name', [
                'table_name' => $tableName,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception(
                "Failed to parse partition name '{$tableName}': {$e->getMessage()}",
                0,
                $e
            );
        }
    }
    
    /**
     * Validate partition table name format
     * 
     * Ensures the partition name follows the required format:
     * - Starts with valid table prefix (alerts, backalerts, etc.)
     * - Followed by YYYY_MM_DD with zero-padded values
     * - No SQL injection characters or invalid patterns
     * 
     * Requirements: 7.5
     * 
     * @param string $partitionName The partition table name to validate
     * @param string $expectedPrefix The expected table prefix (default: 'alerts')
     * @return bool True if valid, false otherwise
     */
    public function isValidPartitionName(string $partitionName, string $expectedPrefix = 'alerts'): bool
    {
        // Build dynamic pattern for the expected prefix
        $pattern = '/^' . preg_quote($expectedPrefix, '/') . '_\d{4}_\d{2}_\d{2}$/';
        
        // Check against regex pattern
        if (!preg_match($pattern, $partitionName)) {
            return false;
        }
        
        // Additional validation: ensure no SQL injection characters
        if ($this->containsSqlInjectionPatterns($partitionName)) {
            return false;
        }
        
        // Validate that the date part is a valid date
        try {
            $prefixLength = strlen($expectedPrefix) + 1; // +1 for underscore
            $datePart = substr($partitionName, $prefixLength);
            $dateString = str_replace('_', '-', $datePart);
            
            // Use strict parsing to reject invalid dates
            $date = Carbon::createFromFormat('Y-m-d', $dateString);
            
            // Verify the parsed date matches the input (catches overflow like month 13)
            if ($date->format('Y-m-d') !== $dateString) {
                return false;
            }
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Sanitize partition table name
     * 
     * Removes any potentially dangerous characters from a partition name.
     * This is a defensive measure to prevent SQL injection.
     * 
     * Requirements: 7.5
     * 
     * @param string $partitionName The partition name to sanitize
     * @return string The sanitized partition name
     */
    public function sanitizePartitionName(string $partitionName): string
    {
        // Remove any characters that aren't alphanumeric or underscore
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $partitionName);
        
        return $sanitized ?? $partitionName;
    }
    
    /**
     * Check if a string contains SQL injection patterns
     * 
     * Detects common SQL injection patterns to prevent malicious input.
     * 
     * @param string $input The string to check
     * @return bool True if SQL injection patterns detected, false otherwise
     */
    private function containsSqlInjectionPatterns(string $input): bool
    {
        // Common SQL injection patterns
        $patterns = [
            '/[\';"]/',           // Quotes
            '/--/',               // SQL comments
            '/\/\*/',             // Multi-line comments
            '/\*\//',             // Multi-line comments
            '/\bOR\b/i',          // OR keyword
            '/\bAND\b/i',         // AND keyword
            '/\bUNION\b/i',       // UNION keyword
            '/\bSELECT\b/i',      // SELECT keyword
            '/\bINSERT\b/i',      // INSERT keyword
            '/\bUPDATE\b/i',      // UPDATE keyword
            '/\bDELETE\b/i',      // DELETE keyword
            '/\bDROP\b/i',        // DROP keyword
            '/\bEXEC\b/i',        // EXEC keyword
            '/\bEXECUTE\b/i',     // EXECUTE keyword
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get partition table name from receivedtime string
     * 
     * Convenience method that combines extractDate and formatPartitionName.
     * This is the most common use case for the service.
     * 
     * Requirements: 1.1, 1.2, 7.1, 7.2, 7.3
     * 
     * @param string $receivedtime The timestamp string from MySQL
     * @param string $tablePrefix The table prefix (default: 'alerts')
     * @return string The partition table name (e.g., "alerts_2026_01_08" or "backalerts_2026_01_08")
     * @throws Exception If the timestamp cannot be parsed or name is invalid
     */
    public function getPartitionTableName(string $receivedtime, string $tablePrefix = 'alerts'): string
    {
        $date = $this->extractDate($receivedtime);
        return $this->formatPartitionName($date, $tablePrefix);
    }
    
    /**
     * Detect table prefix from partition table name
     * 
     * @param string $tableName The partition table name
     * @return string The detected table prefix
     * @throws Exception If no valid prefix is detected
     */
    private function detectTablePrefix(string $tableName): string
    {
        $supportedPrefixes = ['alerts', 'backalerts'];
        
        foreach ($supportedPrefixes as $prefix) {
            if (strpos($tableName, $prefix . '_') === 0) {
                return $prefix;
            }
        }
        
        throw new Exception("Unable to detect table prefix from table name: {$tableName}");
    }
    
    /**
     * Get the partition table name prefix
     * 
     * @return string The partition prefix ("alerts_")
     */
    public function getPartitionPrefix(): string
    {
        return self::PARTITION_PREFIX;
    }
    
    /**
     * Get the partition date format
     * 
     * @return string The date format string ("Y_m_d")
     */
    public function getPartitionDateFormat(): string
    {
        return self::PARTITION_DATE_FORMAT;
    }
    
    /**
     * Get the configured timezone
     * 
     * @return string The timezone string (e.g., "UTC")
     */
    public function getTimezone(): string
    {
        return $this->timezone;
    }
}
