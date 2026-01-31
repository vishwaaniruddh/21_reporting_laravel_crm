<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CleanupLog model for PostgreSQL
 * Tracks cleanup operations for age-based alert cleanup.
 * 
 * Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6
 */
class CleanupLog extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'cleanup_logs';

    protected $fillable = [
        'operation_type',
        'status',
        'age_threshold_hours',
        'batch_size',
        'batches_processed',
        'records_deleted',
        'records_skipped',
        'error_message',
        'configuration',
        'started_at',
        'completed_at',
        'duration_ms',
        'triggered_by',
    ];

    protected $casts = [
        'age_threshold_hours' => 'integer',
        'batch_size' => 'integer',
        'batches_processed' => 'integer',
        'records_deleted' => 'integer',
        'records_skipped' => 'integer',
        'configuration' => 'array',
        'duration_ms' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Operation type constants
    const OPERATION_AGE_BASED = 'age_based_cleanup';
    const OPERATION_MANUAL = 'manual_cleanup';
    const OPERATION_DRY_RUN = 'dry_run';

    // Status constants
    const STATUS_STARTED = 'started';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_STOPPED = 'stopped';

    /**
     * Get the batches for this cleanup log.
     */
    public function batches(): HasMany
    {
        return $this->hasMany(CleanupBatch::class, 'cleanup_log_id');
    }

    /**
     * Scope to filter logs from the last N days.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $days Number of days (default: 90)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query, int $days = 90)
    {
        return $query->where('started_at', '>=', now()->subDays($days));
    }

    /**
     * Scope to filter successful cleanup operations.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope to filter failed cleanup operations.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope to filter by operation type.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('operation_type', $type);
    }

    /**
     * Mark the cleanup operation as completed.
     * 
     * @param int $recordsDeleted
     * @param int $recordsSkipped
     * @return bool
     */
    public function markCompleted(int $recordsDeleted, int $recordsSkipped): bool
    {
        $completedAt = now();
        $durationMs = $this->started_at 
            ? (int) abs($completedAt->diffInMilliseconds($this->started_at)) 
            : null;

        return $this->update([
            'status' => self::STATUS_COMPLETED,
            'records_deleted' => $recordsDeleted,
            'records_skipped' => $recordsSkipped,
            'completed_at' => $completedAt,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Mark the cleanup operation as failed.
     * 
     * @param string $errorMessage
     * @return bool
     */
    public function markFailed(string $errorMessage): bool
    {
        $completedAt = now();
        $durationMs = $this->started_at 
            ? (int) abs($completedAt->diffInMilliseconds($this->started_at)) 
            : null;

        return $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'completed_at' => $completedAt,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Mark the cleanup operation as stopped (emergency stop).
     * 
     * @param string $reason
     * @return bool
     */
    public function markStopped(string $reason): bool
    {
        $completedAt = now();
        $durationMs = $this->started_at 
            ? (int) abs($completedAt->diffInMilliseconds($this->started_at)) 
            : null;

        return $this->update([
            'status' => self::STATUS_STOPPED,
            'error_message' => $reason,
            'completed_at' => $completedAt,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Increment the batches processed count.
     * 
     * @return bool
     */
    public function incrementBatchesProcessed(): bool
    {
        return $this->increment('batches_processed');
    }

    /**
     * Check if the cleanup is still running.
     * 
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->status === self::STATUS_STARTED;
    }

    /**
     * Check if the cleanup completed successfully.
     * 
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the cleanup failed.
     * 
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the cleanup was stopped.
     * 
     * @return bool
     */
    public function isStopped(): bool
    {
        return $this->status === self::STATUS_STOPPED;
    }
}
