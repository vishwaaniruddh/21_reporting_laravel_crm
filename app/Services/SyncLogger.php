<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Exception;

/**
 * SyncLogger provides structured logging for the update synchronization worker.
 * 
 * This service logs:
 * - Processing cycle start/completion with metrics
 * - Individual alert sync operations with duration and status
 * - Errors with full context
 * - Worker lifecycle events
 * 
 * All logs use Laravel's Log facade with structured data for easy parsing
 * and monitoring.
 * 
 * Requirements: 7.1, 7.2, 7.3, 7.4, 7.5
 */
class SyncLogger
{
    /**
     * Log the start of a processing cycle.
     * 
     * @param int $pendingCount Number of pending entries to process
     * @return void
     */
    public function logCycleStart(int $pendingCount): void
    {
        // Only log if there are pending items (reduced logging)
        if ($pendingCount > 0) {
            // Log::debug('Update sync cycle started', [
            //     'pending_count' => $pendingCount,
            //     'timestamp' => now()->toIso8601String(),
            // ]);
        }
    }

    /**
     * Log the completion of a processing cycle.
     * 
     * @param int $processed Number of entries successfully processed
     * @param int $failed Number of entries that failed
     * @param float $duration Duration in seconds
     * @return void
     */
    public function logCycleComplete(int $processed, int $failed, float $duration): void
    {
        // Only log if there were failures or significant processing
        if ($failed > 0) {
            $total = $processed + $failed;
            $successRate = $total > 0 ? round(($processed / $total) * 100, 2) : 0;
            $avgTimePerEntry = $total > 0 ? round($duration / $total, 3) : 0;

            Log::warning('Update sync cycle completed with failures', [
                'processed' => $processed,
                'failed' => $failed,
                'total' => $total,
                'success_rate' => $successRate,
                'duration_seconds' => round($duration, 3),
                'avg_time_per_entry_seconds' => $avgTimePerEntry,
                'timestamp' => now()->toIso8601String(),
            ]);
        }
    }

    /**
     * Log an individual alert sync operation.
     * 
     * @param int $alertId The alert ID that was synced
     * @param bool $success Whether the sync was successful
     * @param float $duration Duration in seconds
     * @param string|null $error Error message if failed
     * @return void
     */
    public function logAlertSync(int $alertId, bool $success, float $duration, ?string $error = null): void
    {
        // Only log failures, not successes
        if (!$success) {
            Log::error("Alert sync failed", [
                'alert_id' => $alertId,
                'success' => false,
                'duration_seconds' => round($duration, 3),
                'error' => $error,
                'timestamp' => now()->toIso8601String(),
            ]);
        }
    }

    /**
     * Log an error with full context.
     * 
     * @param string $context Description of what was being done when error occurred
     * @param Exception $e The exception that was thrown
     * @param array $data Additional context data
     * @return void
     */
    public function logError(string $context, Exception $e, array $data = []): void
    {
        Log::error("Update sync error: {$context}", array_merge([
            'error_message' => $e->getMessage(),
            'error_class' => get_class($e),
            'error_code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'timestamp' => now()->toIso8601String(),
        ], $data));
    }

    /**
     * Log a warning message.
     * 
     * @param string $message Warning message
     * @param array $context Additional context data
     * @return void
     */
    public function logWarning(string $message, array $context = []): void
    {
        Log::warning("Update sync warning: {$message}", array_merge([
            'timestamp' => now()->toIso8601String(),
        ], $context));
    }

    /**
     * Log an informational message.
     * 
     * @param string $message Info message
     * @param array $context Additional context data
     * @return void
     */
    public function logInfo(string $message, array $context = []): void
    {
        // Changed to debug level to reduce log volume
        // Only errors and warnings will be logged at info level or above
        Log::debug("Update sync: {$message}", array_merge([
            'timestamp' => now()->toIso8601String(),
        ], $context));
    }
}
