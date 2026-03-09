<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * TimestampValidator
 * 
 * Validates that timestamps are being synced correctly without timezone conversion.
 * Compares source (MySQL) and target (PostgreSQL) timestamps to ensure they match.
 * 
 * This service acts as a safeguard against timezone conversion issues during sync.
 */
class TimestampValidator
{
    /**
     * Maximum allowed time difference in seconds (1 second tolerance for rounding)
     */
    private const MAX_TIME_DIFF_SECONDS = 1;

    /**
     * Timestamp columns to validate
     */
    private const TIMESTAMP_COLUMNS = [
        'createtime',
        'receivedtime',
        'closedtime',
        'inserttime',
    ];

    /**
     * Validate timestamps before insert/update to PostgreSQL
     * 
     * Compares source timestamps with prepared target data to ensure
     * no timezone conversion has occurred.
     * 
     * @param array $sourceData Source data from MySQL
     * @param array $targetData Prepared data for PostgreSQL insert
     * @param int $alertId Alert ID for logging
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateBeforeSync(array $sourceData, array $targetData, int $alertId): array
    {
        $errors = [];
        
        foreach (self::TIMESTAMP_COLUMNS as $column) {
            // Skip if column doesn't exist in source or is null
            if (!isset($sourceData[$column]) || $sourceData[$column] === null) {
                continue;
            }
            
            // Skip if column doesn't exist in target
            if (!isset($targetData[$column])) {
                continue;
            }
            
            $sourceTime = $sourceData[$column];
            $targetTime = $targetData[$column];
            
            // Compare timestamps
            $isValid = $this->compareTimestamps($sourceTime, $targetTime);
            
            if (!$isValid) {
                $error = "Timestamp mismatch for {$column}: MySQL='{$sourceTime}', PostgreSQL='{$targetTime}'";
                $errors[] = $error;
                
                Log::warning('Timestamp validation failed', [
                    'alert_id' => $alertId,
                    'column' => $column,
                    'mysql_value' => $sourceTime,
                    'pgsql_value' => $targetTime,
                    'difference' => $this->calculateDifference($sourceTime, $targetTime),
                ]);
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Compare two timestamps for equality (with small tolerance)
     * 
     * @param string $time1 First timestamp
     * @param string $time2 Second timestamp
     * @return bool True if timestamps match (within tolerance)
     */
    private function compareTimestamps(string $time1, string $time2): bool
    {
        try {
            // Parse timestamps
            $carbon1 = Carbon::parse($time1);
            $carbon2 = Carbon::parse($time2);
            
            // Calculate difference in seconds
            $diffSeconds = abs($carbon1->diffInSeconds($carbon2));
            
            // Allow small tolerance for rounding
            return $diffSeconds <= self::MAX_TIME_DIFF_SECONDS;
            
        } catch (\Exception $e) {
            Log::error('Failed to compare timestamps', [
                'time1' => $time1,
                'time2' => $time2,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Calculate time difference in hours for logging
     * 
     * @param string $time1 First timestamp
     * @param string $time2 Second timestamp
     * @return float Difference in hours
     */
    private function calculateDifference(string $time1, string $time2): float
    {
        try {
            $carbon1 = Carbon::parse($time1);
            $carbon2 = Carbon::parse($time2);
            
            return round($carbon1->diffInHours($carbon2, true), 2);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /**
     * Log timestamp validation success
     * 
     * @param int $alertId Alert ID
     * @param array $sourceData Source data
     * @return void
     */
    public function logValidationSuccess(int $alertId, array $sourceData): void
    {
        $timestamps = [];
        foreach (self::TIMESTAMP_COLUMNS as $column) {
            if (isset($sourceData[$column]) && $sourceData[$column] !== null) {
                $timestamps[$column] = $sourceData[$column];
            }
        }
        
        Log::debug('Timestamp validation passed', [
            'alert_id' => $alertId,
            'timestamps' => $timestamps,
        ]);
    }

    /**
     * Check if timezone conversion has occurred
     * 
     * Detects common timezone conversion patterns (e.g., 5.5 hour difference for IST->UTC)
     * 
     * @param string $time1 First timestamp
     * @param string $time2 Second timestamp
     * @return array ['converted' => bool, 'hours_diff' => float]
     */
    public function detectTimezoneConversion(string $time1, string $time2): array
    {
        try {
            $carbon1 = Carbon::parse($time1);
            $carbon2 = Carbon::parse($time2);
            
            $hoursDiff = round($carbon1->diffInHours($carbon2, true), 1);
            
            // Common timezone conversion patterns
            $commonConversions = [5.5, 5.0, 6.0, 4.0]; // IST, EST, CST, etc.
            
            $isConverted = in_array($hoursDiff, $commonConversions);
            
            return [
                'converted' => $isConverted,
                'hours_diff' => $hoursDiff,
            ];
            
        } catch (\Exception $e) {
            return [
                'converted' => false,
                'hours_diff' => 0.0,
            ];
        }
    }
}
