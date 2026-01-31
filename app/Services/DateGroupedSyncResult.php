<?php

namespace App\Services;

/**
 * DateGroupedSyncResult
 * 
 * Represents the result of syncing a batch of alerts to date-partitioned tables.
 * This value object encapsulates the outcome of processing multiple date groups,
 * including success/failure status, record counts, and individual date group results.
 * 
 * Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 8.4
 */
class DateGroupedSyncResult
{
    public function __construct(
        public readonly int $totalRecordsProcessed,
        public readonly array $dateGroupResults,
        public readonly bool $success,
        public readonly int $lastProcessedId,
        public readonly ?string $errorMessage = null,
        public readonly ?float $duration = null
    ) {}
    
    /**
     * Check if the sync operation was successful
     * 
     * @return bool True if all date groups synced successfully, false otherwise
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }
    
    /**
     * Check if the sync operation failed
     * 
     * @return bool True if any date group failed, false otherwise
     */
    public function isFailed(): bool
    {
        return !$this->success;
    }
    
    /**
     * Check if any records were processed
     * 
     * @return bool True if records were processed, false otherwise
     */
    public function hasProcessedRecords(): bool
    {
        return $this->totalRecordsProcessed > 0;
    }
    
    /**
     * Get the number of date groups processed
     * 
     * @return int Number of date groups
     */
    public function getDateGroupCount(): int
    {
        return count($this->dateGroupResults);
    }
    
    /**
     * Get the number of successful date groups
     * 
     * @return int Number of successful date groups
     */
    public function getSuccessfulDateGroupCount(): int
    {
        return count(array_filter($this->dateGroupResults, fn($result) => $result->success));
    }
    
    /**
     * Get the number of failed date groups
     * 
     * @return int Number of failed date groups
     */
    public function getFailedDateGroupCount(): int
    {
        return count(array_filter($this->dateGroupResults, fn($result) => !$result->success));
    }
    
    /**
     * Get all successful date group results
     * 
     * @return array Array of successful DateGroupResult instances
     */
    public function getSuccessfulDateGroups(): array
    {
        return array_filter($this->dateGroupResults, fn($result) => $result->success);
    }
    
    /**
     * Get all failed date group results
     * 
     * @return array Array of failed DateGroupResult instances
     */
    public function getFailedDateGroups(): array
    {
        return array_filter($this->dateGroupResults, fn($result) => !$result->success);
    }
    
    /**
     * Check if duration information is available
     * 
     * @return bool True if duration was recorded, false otherwise
     */
    public function hasDuration(): bool
    {
        return $this->duration !== null;
    }
    
    /**
     * Get duration in milliseconds
     * 
     * @return float|null Duration in milliseconds, or null if not recorded
     */
    public function getDurationMs(): ?float
    {
        return $this->duration !== null ? round($this->duration * 1000, 2) : null;
    }
    
    /**
     * Get a summary string of the result
     * 
     * @return string Human-readable summary of the sync result
     */
    public function getSummary(): string
    {
        $summary = sprintf(
            'Batch sync completed: %d records processed across %d date groups',
            $this->totalRecordsProcessed,
            $this->getDateGroupCount()
        );
        
        if ($this->isFailed()) {
            $summary .= sprintf(
                ' (%d successful, %d failed)',
                $this->getSuccessfulDateGroupCount(),
                $this->getFailedDateGroupCount()
            );
        }
        
        if ($this->hasDuration()) {
            $summary .= sprintf(' in %.2fms', $this->getDurationMs());
        }
        
        return $summary;
    }
    
    /**
     * Convert to array representation for logging/API responses
     * 
     * @return array Associative array with all result data
     */
    public function toArray(): array
    {
        return [
            'total_records_processed' => $this->totalRecordsProcessed,
            'date_group_count' => $this->getDateGroupCount(),
            'successful_date_groups' => $this->getSuccessfulDateGroupCount(),
            'failed_date_groups' => $this->getFailedDateGroupCount(),
            'success' => $this->success,
            'last_processed_id' => $this->lastProcessedId,
            'error_message' => $this->errorMessage,
            'duration' => $this->duration,
            'duration_ms' => $this->getDurationMs(),
            'date_group_results' => array_map(fn($result) => $result->toArray(), $this->dateGroupResults),
        ];
    }
    
    /**
     * Create a successful result
     * 
     * @param int $totalRecordsProcessed Total number of records processed
     * @param array $dateGroupResults Array of DateGroupResult instances
     * @param int $lastProcessedId Last processed alert ID
     * @param float|null $duration Duration in seconds
     * @return self A successful DateGroupedSyncResult instance
     */
    public static function success(
        int $totalRecordsProcessed,
        array $dateGroupResults,
        int $lastProcessedId,
        ?float $duration = null
    ): self {
        return new self(
            totalRecordsProcessed: $totalRecordsProcessed,
            dateGroupResults: $dateGroupResults,
            success: true,
            lastProcessedId: $lastProcessedId,
            errorMessage: null,
            duration: $duration
        );
    }
    
    /**
     * Create a partial success result (some date groups failed)
     * 
     * @param int $totalRecordsProcessed Total number of records processed
     * @param array $dateGroupResults Array of DateGroupResult instances
     * @param int $lastProcessedId Last processed alert ID
     * @param string $errorMessage Description of the failures
     * @param float|null $duration Duration in seconds
     * @return self A partial success DateGroupedSyncResult instance
     */
    public static function partialSuccess(
        int $totalRecordsProcessed,
        array $dateGroupResults,
        int $lastProcessedId,
        string $errorMessage,
        ?float $duration = null
    ): self {
        return new self(
            totalRecordsProcessed: $totalRecordsProcessed,
            dateGroupResults: $dateGroupResults,
            success: false,
            lastProcessedId: $lastProcessedId,
            errorMessage: $errorMessage,
            duration: $duration
        );
    }
    
    /**
     * Create a failed result
     * 
     * @param string $errorMessage Description of the failure
     * @param int $lastProcessedId Last processed alert ID
     * @param float|null $duration Duration in seconds before failure
     * @return self A failed DateGroupedSyncResult instance
     */
    public static function failure(
        string $errorMessage,
        int $lastProcessedId = 0,
        ?float $duration = null
    ): self {
        return new self(
            totalRecordsProcessed: 0,
            dateGroupResults: [],
            success: false,
            lastProcessedId: $lastProcessedId,
            errorMessage: $errorMessage,
            duration: $duration
        );
    }
}
