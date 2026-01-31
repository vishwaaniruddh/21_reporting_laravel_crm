<?php

namespace App\Services;

use App\Models\PartitionSyncError;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * PartitionFailureAlertService
 * 
 * Monitors partition sync failures and sends alerts when thresholds are exceeded.
 * This service provides:
 * - Threshold-based alerting for partition failures
 * - Failure rate monitoring
 * - Alert notifications with detailed context
 * 
 * Requirements: 8.5
 */
class PartitionFailureAlertService
{
    /**
     * Default failure threshold (number of failures)
     */
    private int $failureThreshold;
    
    /**
     * Time window for threshold calculation (in minutes)
     */
    private int $timeWindowMinutes;
    
    /**
     * Minimum time between alerts (in minutes) to prevent alert spam
     */
    private int $alertCooldownMinutes;

    
    /**
     * Last alert timestamp (to implement cooldown)
     */
    private ?Carbon $lastAlertTime = null;
    
    /**
     * Create a new PartitionFailureAlertService instance
     * 
     * @param int|null $failureThreshold Optional failure threshold override
     * @param int|null $timeWindowMinutes Optional time window override
     * @param int|null $alertCooldownMinutes Optional cooldown override
     */
    public function __construct(
        ?int $failureThreshold = null,
        ?int $timeWindowMinutes = null,
        ?int $alertCooldownMinutes = null
    ) {
        $this->failureThreshold = $failureThreshold ?? (int) config('pipeline.partition_failure_threshold', 10);
        $this->timeWindowMinutes = $timeWindowMinutes ?? (int) config('pipeline.partition_failure_window', 60);
        $this->alertCooldownMinutes = $alertCooldownMinutes ?? (int) config('pipeline.partition_alert_cooldown', 30);
    }
    
    /**
     * Check if failure threshold is exceeded and send alert if needed
     * 
     * Monitors recent partition failures and sends an alert when the threshold
     * is exceeded within the time window.
     * 
     * Requirements: 8.5
     * 
     * @return bool True if alert was sent
     */
    public function checkAndAlert(): bool
    {
        // Check if we're in cooldown period
        if ($this->isInCooldown()) {
            Log::debug('Alert cooldown active, skipping check', [
                'last_alert_time' => $this->lastAlertTime?->toDateTimeString(),
                'cooldown_minutes' => $this->alertCooldownMinutes
            ]);
            return false;
        }
        
        // Get recent failures within time window
        $recentFailures = $this->getRecentFailures();
        $failureCount = $recentFailures->count();
        
        // Check if threshold exceeded
        if ($failureCount >= $this->failureThreshold) {
            $this->sendAlert($recentFailures);
            $this->lastAlertTime = now();
            return true;
        }
        
        return false;
    }

    
    /**
     * Get recent failures within the time window
     * 
     * @return Collection Collection of PartitionSyncError records
     */
    private function getRecentFailures(): Collection
    {
        $since = now()->subMinutes($this->timeWindowMinutes);
        
        return PartitionSyncError::where('created_at', '>=', $since)
            ->whereIn('status', [
                PartitionSyncError::STATUS_PENDING,
                PartitionSyncError::STATUS_RETRYING,
                PartitionSyncError::STATUS_FAILED
            ])
            ->orderBy('created_at', 'desc')
            ->get();
    }
    
    /**
     * Check if we're in the alert cooldown period
     * 
     * @return bool True if in cooldown
     */
    private function isInCooldown(): bool
    {
        if ($this->lastAlertTime === null) {
            return false;
        }
        
        $cooldownEnd = $this->lastAlertTime->copy()->addMinutes($this->alertCooldownMinutes);
        return now()->isBefore($cooldownEnd);
    }
    
    /**
     * Send alert for partition failures
     * 
     * Logs detailed alert information including partition names, error details,
     * and failure statistics. In production, this would also send notifications
     * via email, Slack, or other alerting systems.
     * 
     * Requirements: 8.5
     * 
     * @param Collection $failures Collection of PartitionSyncError records
     * @return void
     */
    private function sendAlert(Collection $failures): void
    {
        $failureCount = $failures->count();
        $affectedPartitions = $failures->pluck('partition_table')->unique();
        $errorTypes = $failures->groupBy('error_type')->map->count();
        
        $alertData = [
            'severity' => 'critical',
            'title' => 'Partition Sync Failure Threshold Exceeded',
            'failure_count' => $failureCount,
            'threshold' => $this->failureThreshold,
            'time_window_minutes' => $this->timeWindowMinutes,
            'affected_partitions' => $affectedPartitions->toArray(),
            'partition_count' => $affectedPartitions->count(),
            'error_types' => $errorTypes->toArray(),
            'timestamp' => now()->toDateTimeString(),
        ];

        
        // Get sample errors for context
        $sampleErrors = $failures->take(5)->map(function ($error) {
            return [
                'partition_table' => $error->partition_table,
                'partition_date' => $error->partition_date->toDateString(),
                'error_type' => $error->error_type,
                'error_message' => substr($error->error_message, 0, 200),
                'retry_count' => $error->retry_count,
                'status' => $error->status,
            ];
        })->toArray();
        
        $alertData['sample_errors'] = $sampleErrors;
        
        // Log critical alert
        Log::critical('PARTITION SYNC FAILURE ALERT', $alertData);
        
        // TODO: In production, send notifications via:
        // - Email to administrators
        // - Slack/Teams webhook
        // - PagerDuty or similar alerting service
        // - SMS for critical failures
        
        // Example notification message
        $message = sprintf(
            "ALERT: %d partition sync failures detected in the last %d minutes (threshold: %d). " .
            "Affected partitions: %s. Error types: %s",
            $failureCount,
            $this->timeWindowMinutes,
            $this->failureThreshold,
            implode(', ', $affectedPartitions->take(5)->toArray()),
            implode(', ', array_keys($errorTypes->toArray()))
        );
        
        Log::critical('Alert message', ['message' => $message]);
    }
    
    /**
     * Get failure statistics for monitoring
     * 
     * @return array Statistics about partition failures
     */
    public function getFailureStatistics(): array
    {
        $recentFailures = $this->getRecentFailures();
        
        return [
            'recent_failure_count' => $recentFailures->count(),
            'threshold' => $this->failureThreshold,
            'time_window_minutes' => $this->timeWindowMinutes,
            'threshold_exceeded' => $recentFailures->count() >= $this->failureThreshold,
            'in_cooldown' => $this->isInCooldown(),
            'last_alert_time' => $this->lastAlertTime?->toDateTimeString(),
            'affected_partitions' => $recentFailures->pluck('partition_table')->unique()->count(),
            'error_types' => $recentFailures->groupBy('error_type')->map->count()->toArray(),
        ];
    }
}
