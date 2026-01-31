<?php

namespace App\Services;

/**
 * GenericSyncResult holds the result of a table sync operation.
 * 
 * This class encapsulates the outcome of syncing a configured table
 * from MySQL to PostgreSQL, including success status, record counts,
 * and any error information.
 */
class GenericSyncResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $recordsSynced,
        public readonly int $recordsFailed,
        public readonly ?int $startId = null,
        public readonly ?int $endId = null,
        public readonly ?string $errorMessage = null
    ) {}

    /**
     * Check if the sync was completely successful (no failures).
     */
    public function isSuccess(): bool
    {
        return $this->success && $this->recordsFailed === 0;
    }

    /**
     * Check if the sync was partially successful (some records synced, some failed).
     */
    public function isPartial(): bool
    {
        return $this->recordsSynced > 0 && $this->recordsFailed > 0;
    }

    /**
     * Check if the sync completely failed (no records synced).
     */
    public function isFailed(): bool
    {
        return !$this->success || ($this->recordsSynced === 0 && $this->recordsFailed > 0);
    }

    /**
     * Get total records processed (synced + failed).
     */
    public function getTotalProcessed(): int
    {
        return $this->recordsSynced + $this->recordsFailed;
    }

    /**
     * Get success rate as a percentage.
     */
    public function getSuccessRate(): float
    {
        $total = $this->getTotalProcessed();
        if ($total === 0) {
            return 100.0;
        }
        return ($this->recordsSynced / $total) * 100;
    }

    /**
     * Convert to array for JSON serialization.
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'records_synced' => $this->recordsSynced,
            'records_failed' => $this->recordsFailed,
            'start_id' => $this->startId,
            'end_id' => $this->endId,
            'error_message' => $this->errorMessage,
            'total_processed' => $this->getTotalProcessed(),
            'success_rate' => $this->getSuccessRate(),
        ];
    }
}
