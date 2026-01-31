<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * BackAlertUpdateLog Model
 * 
 * Represents backalert_pg_update_log table records.
 * Tracks changes to backalerts for PostgreSQL sync operations.
 * Similar to AlertUpdateLog model but for backalerts.
 */
class BackAlertUpdateLog extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'backalert_pg_update_log';

    /**
     * The database connection name for the model.
     */
    protected $connection = 'mysql';

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = true;

    /**
     * Status constants
     */
    const STATUS_PENDING = 1;
    const STATUS_COMPLETED = 2;
    const STATUS_FAILED = 3;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'backalert_id',
        'status',
        'error_message',
        'retry_count',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'backalert_id' => 'integer',
        'status' => 'integer',
        'retry_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Scope a query to only include pending entries.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope a query to only include completed entries.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope a query to only include failed entries.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope a query to order by creation date.
     */
    public function scopeOldestFirst(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'asc');
    }

    /**
     * Scope a query to limit retry count.
     */
    public function scopeWithinRetryLimit(Builder $query, int $maxRetries = 3): Builder
    {
        return $query->where('retry_count', '<', $maxRetries);
    }

    /**
     * Get the associated backalert.
     */
    public function backAlert()
    {
        return $this->belongsTo(BackAlert::class, 'backalert_id', 'id');
    }

    /**
     * Mark this log entry as completed.
     */
    public function markAsCompleted(): bool
    {
        return $this->update([
            'status' => self::STATUS_COMPLETED,
            'error_message' => null,
        ]);
    }

    /**
     * Mark this log entry as failed.
     */
    public function markAsFailed(string $errorMessage): bool
    {
        return $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'retry_count' => $this->retry_count + 1,
        ]);
    }

    /**
     * Reset this log entry for retry.
     */
    public function resetForRetry(): bool
    {
        return $this->update([
            'status' => self::STATUS_PENDING,
            'error_message' => null,
            'retry_count' => $this->retry_count + 1,
        ]);
    }

    /**
     * Check if this entry can be retried.
     */
    public function canRetry(int $maxRetries = 3): bool
    {
        return $this->retry_count < $maxRetries;
    }

    /**
     * Check if this entry is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if this entry is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if this entry is failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Get status name.
     */
    public function getStatusName(): string
    {
        return match($this->status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            default => 'Unknown',
        };
    }
}