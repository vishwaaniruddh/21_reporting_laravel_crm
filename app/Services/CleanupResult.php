<?php

namespace App\Services;

/**
 * Result object for cleanup operations
 * 
 * Requirements: 4.1, 4.2, 4.3
 */
class CleanupResult
{
    public function __construct(
        public readonly int $recordsDeleted,
        public readonly int $recordsSkipped,
        public readonly int $batchesProcessed,
        public readonly bool $success,
        public readonly array $errors = [],
        public readonly ?string $errorMessage = null,
        public readonly bool $adminConfirmed = false,
    ) {}

    /**
     * Check if cleanup was successful
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Check if there were any errors
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Get summary of the cleanup operation
     */
    public function getSummary(): array
    {
        return [
            'records_deleted' => $this->recordsDeleted,
            'records_skipped' => $this->recordsSkipped,
            'batches_processed' => $this->batchesProcessed,
            'success' => $this->success,
            'error_count' => count($this->errors),
            'admin_confirmed' => $this->adminConfirmed,
        ];
    }
}
