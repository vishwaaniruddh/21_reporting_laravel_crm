<?php

namespace App\Services;

/**
 * SyncResult represents the result of a single alert synchronization operation.
 * 
 * This value object encapsulates the outcome of syncing one alert from MySQL to PostgreSQL,
 * including success/failure status, alert ID, error information, and performance metrics.
 * 
 * Requirements: 2.3, 3.2
 */
class SyncResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $alertId,
        public readonly ?string $errorMessage = null,
        public readonly ?float $duration = null
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
        if ($this->success) {
            $summary = sprintf('Alert %d synced successfully', $this->alertId);
            if ($this->hasDuration()) {
                $summary .= sprintf(' (%.2fms)', $this->getDurationMs());
            }
            return $summary;
        }

        return sprintf(
            'Alert %d sync failed: %s',
            $this->alertId,
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
            'success' => $this->success,
            'alert_id' => $this->alertId,
            'error_message' => $this->errorMessage,
            'duration' => $this->duration,
            'duration_ms' => $this->getDurationMs(),
        ];
    }

    /**
     * Create a successful result
     * 
     * @param int $alertId The ID of the synced alert
     * @param float|null $duration Duration in seconds
     * @return self A successful SyncResult instance
     */
    public static function success(int $alertId, ?float $duration = null): self
    {
        return new self(
            success: true,
            alertId: $alertId,
            errorMessage: null,
            duration: $duration
        );
    }

    /**
     * Create a failed result
     * 
     * @param int $alertId The ID of the alert that failed to sync
     * @param string $errorMessage Description of the failure
     * @param float|null $duration Duration in seconds before failure
     * @return self A failed SyncResult instance
     */
    public static function failure(int $alertId, string $errorMessage, ?float $duration = null): self
    {
        return new self(
            success: false,
            alertId: $alertId,
            errorMessage: $errorMessage,
            duration: $duration
        );
    }
}
