<?php

namespace App\Services;

use App\Models\TableSyncConfiguration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ErrorThresholdService handles error threshold tracking and alerting for sync operations.
 * 
 * This service implements:
 * - Track consecutive failures per configuration
 * - Pause sync when threshold exceeded
 * - Log critical alerts
 * - Resume sync functionality
 * 
 * ⚠️ NO DELETION FROM MYSQL: Alerting does not modify MySQL
 * 
 * Requirements: 7.6
 */
class ErrorThresholdService
{
    /**
     * Warning threshold - number of consecutive failures before warning
     */
    protected int $warningThreshold;

    /**
     * Critical threshold - number of consecutive failures before pausing sync
     */
    protected int $criticalThreshold;

    /**
     * Cache key prefix for failure counts
     */
    protected string $failureCountPrefix = 'table_sync_failures_';

    /**
     * Cache key prefix for paused state
     */
    protected string $pausedPrefix = 'table_sync_paused_';

    /**
     * Cache key prefix for last error message
     */
    protected string $lastErrorPrefix = 'table_sync_last_error_';

    /**
     * Default pause duration in seconds (1 hour)
     */
    protected int $defaultPauseDuration = 3600;

    /**
     * Failure count TTL in seconds (24 hours)
     */
    protected int $failureCountTtl = 86400;

    public function __construct()
    {
        $this->warningThreshold = (int) config('pipeline.alerts.warning_failures', 3);
        $this->criticalThreshold = (int) config('pipeline.alerts.critical_failures', 5);
    }

    /**
     * Record a sync failure and check thresholds.
     * 
     * @param int $configId Configuration ID
     * @param string|null $errorMessage Error message to store
     * @return array Status information including failure count and actions taken
     */
    public function recordFailure(int $configId, ?string $errorMessage = null): array
    {
        $count = $this->incrementFailureCount($configId);
        
        // Store last error message
        if ($errorMessage) {
            $this->storeLastError($configId, $errorMessage);
        }

        $result = [
            'config_id' => $configId,
            'failure_count' => $count,
            'warning_threshold' => $this->warningThreshold,
            'critical_threshold' => $this->criticalThreshold,
            'warning_triggered' => false,
            'critical_triggered' => false,
            'sync_paused' => false,
        ];

        // Check warning threshold
        if ($count === $this->warningThreshold) {
            $this->triggerWarning($configId, $count, $errorMessage);
            $result['warning_triggered'] = true;
        }

        // Check critical threshold
        if ($count >= $this->criticalThreshold) {
            $this->triggerCritical($configId, $count, $errorMessage);
            $this->pauseSync($configId);
            $result['critical_triggered'] = true;
            $result['sync_paused'] = true;
        }

        return $result;
    }

    /**
     * Record a sync success and reset failure count.
     * 
     * @param int $configId Configuration ID
     */
    public function recordSuccess(int $configId): void
    {
        $previousCount = $this->getFailureCount($configId);
        
        if ($previousCount > 0) {
            Log::info('Sync succeeded after previous failures, resetting failure count', [
                'config_id' => $configId,
                'previous_failure_count' => $previousCount,
            ]);
        }

        $this->resetFailureCount($configId);
        $this->clearLastError($configId);
    }

    /**
     * Get the current failure count for a configuration.
     * 
     * @param int $configId Configuration ID
     * @return int
     */
    public function getFailureCount(int $configId): int
    {
        return (int) Cache::get($this->getFailureCountKey($configId), 0);
    }

    /**
     * Check if sync is paused for a configuration.
     * 
     * @param int $configId Configuration ID
     * @return bool
     */
    public function isSyncPaused(int $configId): bool
    {
        return Cache::has($this->getPausedKey($configId));
    }

    /**
     * Pause sync for a configuration.
     * 
     * @param int $configId Configuration ID
     * @param int|null $duration Duration in seconds (default: 1 hour)
     */
    public function pauseSync(int $configId, ?int $duration = null): void
    {
        $duration = $duration ?? $this->defaultPauseDuration;
        
        Cache::put($this->getPausedKey($configId), [
            'paused_at' => now()->toIso8601String(),
            'duration_seconds' => $duration,
            'failure_count' => $this->getFailureCount($configId),
            'last_error' => $this->getLastError($configId),
        ], $duration);

        Log::critical('Sync paused due to consecutive failures exceeding threshold', [
            'config_id' => $configId,
            'failure_count' => $this->getFailureCount($configId),
            'critical_threshold' => $this->criticalThreshold,
            'pause_duration_seconds' => $duration,
        ]);

        // Update configuration status if possible
        $this->updateConfigurationStatus($configId, 'paused');
    }

    /**
     * Resume sync for a configuration.
     * 
     * @param int $configId Configuration ID
     * @param bool $resetFailures Whether to reset failure count (default: true)
     */
    public function resumeSync(int $configId, bool $resetFailures = true): void
    {
        Cache::forget($this->getPausedKey($configId));
        
        if ($resetFailures) {
            $this->resetFailureCount($configId);
            $this->clearLastError($configId);
        }

        Log::info('Sync resumed', [
            'config_id' => $configId,
            'failures_reset' => $resetFailures,
        ]);

        // Update configuration status if possible
        $this->updateConfigurationStatus($configId, TableSyncConfiguration::STATUS_IDLE);
    }

    /**
     * Get pause status information for a configuration.
     * 
     * @param int $configId Configuration ID
     * @return array|null Pause information or null if not paused
     */
    public function getPauseStatus(int $configId): ?array
    {
        return Cache::get($this->getPausedKey($configId));
    }

    /**
     * Get the last error message for a configuration.
     * 
     * @param int $configId Configuration ID
     * @return string|null
     */
    public function getLastError(int $configId): ?string
    {
        return Cache::get($this->getLastErrorKey($configId));
    }

    /**
     * Get error threshold status for a configuration.
     * 
     * @param int $configId Configuration ID
     * @return array
     */
    public function getStatus(int $configId): array
    {
        $failureCount = $this->getFailureCount($configId);
        $isPaused = $this->isSyncPaused($configId);
        $pauseStatus = $this->getPauseStatus($configId);

        return [
            'config_id' => $configId,
            'failure_count' => $failureCount,
            'warning_threshold' => $this->warningThreshold,
            'critical_threshold' => $this->criticalThreshold,
            'is_at_warning' => $failureCount >= $this->warningThreshold && $failureCount < $this->criticalThreshold,
            'is_at_critical' => $failureCount >= $this->criticalThreshold,
            'is_paused' => $isPaused,
            'pause_info' => $pauseStatus,
            'last_error' => $this->getLastError($configId),
        ];
    }

    /**
     * Get status for multiple configurations.
     * 
     * @param array $configIds Array of configuration IDs
     * @return array
     */
    public function getStatusForMultiple(array $configIds): array
    {
        $statuses = [];
        foreach ($configIds as $configId) {
            $statuses[$configId] = $this->getStatus($configId);
        }
        return $statuses;
    }

    /**
     * Increment the failure count for a configuration.
     * 
     * @param int $configId Configuration ID
     * @return int New failure count
     */
    protected function incrementFailureCount(int $configId): int
    {
        $key = $this->getFailureCountKey($configId);
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, $this->failureCountTtl);
        return $count;
    }

    /**
     * Reset the failure count for a configuration.
     * 
     * @param int $configId Configuration ID
     */
    protected function resetFailureCount(int $configId): void
    {
        Cache::forget($this->getFailureCountKey($configId));
    }

    /**
     * Store the last error message for a configuration.
     * 
     * @param int $configId Configuration ID
     * @param string $errorMessage Error message
     */
    protected function storeLastError(int $configId, string $errorMessage): void
    {
        Cache::put($this->getLastErrorKey($configId), $errorMessage, $this->failureCountTtl);
    }

    /**
     * Clear the last error message for a configuration.
     * 
     * @param int $configId Configuration ID
     */
    protected function clearLastError(int $configId): void
    {
        Cache::forget($this->getLastErrorKey($configId));
    }

    /**
     * Trigger a warning alert.
     * 
     * @param int $configId Configuration ID
     * @param int $failureCount Current failure count
     * @param string|null $errorMessage Last error message
     */
    protected function triggerWarning(int $configId, int $failureCount, ?string $errorMessage): void
    {
        Log::warning('Sync failure warning threshold reached', [
            'config_id' => $configId,
            'failure_count' => $failureCount,
            'warning_threshold' => $this->warningThreshold,
            'last_error' => $errorMessage,
        ]);

        // Here you could add additional alerting mechanisms:
        // - Send email notification
        // - Send Slack/Teams notification
        // - Trigger webhook
        // - Create alert record in database
    }

    /**
     * Trigger a critical alert.
     * 
     * @param int $configId Configuration ID
     * @param int $failureCount Current failure count
     * @param string|null $errorMessage Last error message
     */
    protected function triggerCritical(int $configId, int $failureCount, ?string $errorMessage): void
    {
        Log::critical('Sync failure critical threshold reached - sync will be paused', [
            'config_id' => $configId,
            'failure_count' => $failureCount,
            'critical_threshold' => $this->criticalThreshold,
            'last_error' => $errorMessage,
        ]);

        // Here you could add additional alerting mechanisms:
        // - Send urgent email notification
        // - Send Slack/Teams notification with @channel
        // - Trigger PagerDuty/OpsGenie alert
        // - Create critical alert record in database
    }

    /**
     * Update configuration status in database.
     * 
     * @param int $configId Configuration ID
     * @param string $status New status
     */
    protected function updateConfigurationStatus(int $configId, string $status): void
    {
        try {
            $config = TableSyncConfiguration::find($configId);
            if ($config) {
                $config->updateSyncStatus($status);
            }
        } catch (\Exception $e) {
            Log::error('Failed to update configuration status', [
                'config_id' => $configId,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the cache key for failure count.
     * 
     * @param int $configId Configuration ID
     * @return string
     */
    protected function getFailureCountKey(int $configId): string
    {
        return $this->failureCountPrefix . $configId;
    }

    /**
     * Get the cache key for paused state.
     * 
     * @param int $configId Configuration ID
     * @return string
     */
    protected function getPausedKey(int $configId): string
    {
        return $this->pausedPrefix . $configId;
    }

    /**
     * Get the cache key for last error.
     * 
     * @param int $configId Configuration ID
     * @return string
     */
    protected function getLastErrorKey(int $configId): string
    {
        return $this->lastErrorPrefix . $configId;
    }

    /**
     * Set the warning threshold.
     * 
     * @param int $threshold
     * @return self
     */
    public function setWarningThreshold(int $threshold): self
    {
        $this->warningThreshold = max(1, $threshold);
        return $this;
    }

    /**
     * Get the warning threshold.
     * 
     * @return int
     */
    public function getWarningThreshold(): int
    {
        return $this->warningThreshold;
    }

    /**
     * Set the critical threshold.
     * 
     * @param int $threshold
     * @return self
     */
    public function setCriticalThreshold(int $threshold): self
    {
        $this->criticalThreshold = max(1, $threshold);
        return $this;
    }

    /**
     * Get the critical threshold.
     * 
     * @return int
     */
    public function getCriticalThreshold(): int
    {
        return $this->criticalThreshold;
    }

    /**
     * Set the default pause duration.
     * 
     * @param int $seconds Duration in seconds
     * @return self
     */
    public function setDefaultPauseDuration(int $seconds): self
    {
        $this->defaultPauseDuration = max(60, $seconds);
        return $this;
    }

    /**
     * Get the default pause duration.
     * 
     * @return int
     */
    public function getDefaultPauseDuration(): int
    {
        return $this->defaultPauseDuration;
    }
}
