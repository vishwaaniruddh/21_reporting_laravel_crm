<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * VerificationReport represents a comprehensive verification report for a date range.
 * 
 * Requirements: 3.4
 */
class VerificationReport
{
    public function __construct(
        public readonly mixed $startDate,
        public readonly mixed $endDate,
        public readonly int $totalBatches,
        public readonly int $verifiedBatches,
        public readonly int $failedBatches,
        public readonly int $pendingBatches,
        public readonly int $totalSourceRecords,
        public readonly int $totalTargetRecords,
        public readonly int $totalMissingRecords,
        public readonly float $matchPercentage,
        public readonly array $batchDetails,
        public readonly Carbon $generatedAt,
        public readonly int $durationMs
    ) {}

    /**
     * Check if all batches are verified
     */
    public function isFullyVerified(): bool
    {
        return $this->failedBatches === 0 && $this->pendingBatches === 0;
    }

    /**
     * Check if there are any failures
     */
    public function hasFailures(): bool
    {
        return $this->failedBatches > 0;
    }

    /**
     * Check if there are pending verifications
     */
    public function hasPending(): bool
    {
        return $this->pendingBatches > 0;
    }

    /**
     * Get verification success rate
     */
    public function getSuccessRate(): float
    {
        if ($this->totalBatches === 0) {
            return 100.0;
        }
        
        return round(($this->verifiedBatches / $this->totalBatches) * 100, 2);
    }

    /**
     * Get summary statistics
     */
    public function getSummary(): array
    {
        return [
            'date_range' => [
                'start' => $this->formatDate($this->startDate),
                'end' => $this->formatDate($this->endDate),
            ],
            'batches' => [
                'total' => $this->totalBatches,
                'verified' => $this->verifiedBatches,
                'failed' => $this->failedBatches,
                'pending' => $this->pendingBatches,
                'success_rate' => $this->getSuccessRate(),
            ],
            'records' => [
                'source_total' => $this->totalSourceRecords,
                'target_total' => $this->totalTargetRecords,
                'missing' => $this->totalMissingRecords,
                'match_percentage' => $this->matchPercentage,
            ],
            'generated_at' => $this->generatedAt->toDateTimeString(),
            'duration_ms' => $this->durationMs,
        ];
    }

    /**
     * Convert to array for API responses
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->getSummary(),
            'batch_details' => $this->batchDetails,
        ];
    }

    /**
     * Format date for output
     */
    protected function formatDate($date): string
    {
        if ($date instanceof Carbon) {
            return $date->toDateTimeString();
        }
        
        return (string) $date;
    }
}
