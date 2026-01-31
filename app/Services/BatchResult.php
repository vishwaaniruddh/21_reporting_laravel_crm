<?php

namespace App\Services;

/**
 * BatchResult represents the result of a batch sync operation.
 * 
 * This class encapsulates the outcome of processing a batch of alerts,
 * including success/failure status, record counts, and error information.
 */
class BatchResult
{
    public function __construct(
        public readonly int $recordsProcessed,
        public readonly int $lastProcessedId,
        public readonly bool $success,
        public readonly ?int $batchId,
        public readonly ?string $errorMessage
    ) {}

    /**
     * Check if the batch was successful
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Check if the batch failed
     */
    public function isFailed(): bool
    {
        return !$this->success;
    }

    /**
     * Check if any records were processed
     */
    public function hasProcessedRecords(): bool
    {
        return $this->recordsProcessed > 0;
    }

    /**
     * Get a summary string of the result
     */
    public function getSummary(): string
    {
        if ($this->success) {
            return sprintf(
                'Batch %s completed successfully: %d records processed, last ID: %d',
                $this->batchId ?? 'N/A',
                $this->recordsProcessed,
                $this->lastProcessedId
            );
        }

        return sprintf(
            'Batch %s failed: %s',
            $this->batchId ?? 'N/A',
            $this->errorMessage ?? 'Unknown error'
        );
    }

    /**
     * Convert to array representation
     */
    public function toArray(): array
    {
        return [
            'records_processed' => $this->recordsProcessed,
            'last_processed_id' => $this->lastProcessedId,
            'success' => $this->success,
            'batch_id' => $this->batchId,
            'error_message' => $this->errorMessage,
        ];
    }
}
