<?php

namespace App\Services;

/**
 * VerificationResult represents the result of a batch verification operation.
 * 
 * Requirements: 3.1, 3.2
 */
class VerificationResult
{
    public function __construct(
        public readonly int $sourceCount,
        public readonly int $targetCount,
        public readonly array $missingIds,
        public readonly bool $verified,
        public readonly ?int $batchId = null,
        public readonly ?string $errorMessage = null
    ) {}

    /**
     * Check if verification passed
     */
    public function isVerified(): bool
    {
        return $this->verified;
    }

    /**
     * Check if counts match between source and target
     */
    public function countsMatch(): bool
    {
        return $this->sourceCount === $this->targetCount;
    }

    /**
     * Check if there are missing records
     */
    public function hasMissingRecords(): bool
    {
        return !empty($this->missingIds);
    }

    /**
     * Get the count of missing records
     */
    public function getMissingCount(): int
    {
        return count($this->missingIds);
    }

    /**
     * Get the match percentage
     */
    public function getMatchPercentage(): float
    {
        if ($this->sourceCount === 0) {
            return 100.0;
        }
        
        return round(($this->targetCount / $this->sourceCount) * 100, 2);
    }

    /**
     * Convert to array for logging/API responses
     */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'source_count' => $this->sourceCount,
            'target_count' => $this->targetCount,
            'missing_ids' => $this->missingIds,
            'missing_count' => $this->getMissingCount(),
            'verified' => $this->verified,
            'match_percentage' => $this->getMatchPercentage(),
            'error_message' => $this->errorMessage,
        ];
    }
}
