<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * DateGroupResult
 * 
 * Represents the result of syncing a single date group to its partition table.
 * This value object encapsulates the outcome of processing all alerts for a specific date.
 * 
 * Requirements: 5.3, 8.2, 8.4
 */
class DateGroupResult
{
    public function __construct(
        public readonly Carbon $date,
        public readonly string $partitionTable,
        public readonly int $recordsInserted,
        public readonly bool $success,
        public readonly ?string $errorMessage = null
    ) {}
    
    /**
     * Check if the sync operation was successful
     * 
     * @return bool True if sync succeeded, false otherwise
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }
    
    /**
     * Check if the sync operation failed
     * 
     * @return bool True if sync failed, false otherwise
     */
    public function isFailed(): bool
    {
        return !$this->success;
    }
    
    /**
     * Check if any records were inserted
     * 
     * @return bool True if records were inserted, false otherwise
     */
    public function hasInsertedRecords(): bool
    {
        return $this->recordsInserted > 0;
    }
    
    /**
     * Get a summary string of the result
     * 
     * @return string Human-readable summary of the sync result
     */
    public function getSummary(): string
    {
        if ($this->success) {
            return sprintf(
                'Date %s synced successfully: %d records inserted into %s',
                $this->date->toDateString(),
                $this->recordsInserted,
                $this->partitionTable
            );
        }
        
        return sprintf(
            'Date %s sync failed: %s',
            $this->date->toDateString(),
            $this->errorMessage ?? 'Unknown error'
        );
    }
    
    /**
     * Convert to array representation for logging/API responses
     * 
     * @return array Associative array with all result data
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date->toDateString(),
            'partition_table' => $this->partitionTable,
            'records_inserted' => $this->recordsInserted,
            'success' => $this->success,
            'error_message' => $this->errorMessage,
        ];
    }
    
    /**
     * Create a successful result
     * 
     * @param Carbon $date The date for this group
     * @param string $partitionTable The partition table name
     * @param int $recordsInserted Number of records inserted
     * @return self A successful DateGroupResult instance
     */
    public static function success(Carbon $date, string $partitionTable, int $recordsInserted): self
    {
        return new self(
            date: $date,
            partitionTable: $partitionTable,
            recordsInserted: $recordsInserted,
            success: true,
            errorMessage: null
        );
    }
    
    /**
     * Create a failed result
     * 
     * @param Carbon $date The date for this group
     * @param string $partitionTable The partition table name
     * @param string $errorMessage Description of the failure
     * @return self A failed DateGroupResult instance
     */
    public static function failure(Carbon $date, string $partitionTable, string $errorMessage): self
    {
        return new self(
            date: $date,
            partitionTable: $partitionTable,
            recordsInserted: 0,
            success: false,
            errorMessage: $errorMessage
        );
    }
}
