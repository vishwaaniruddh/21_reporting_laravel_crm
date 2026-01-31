<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AlertUpdateLog model for MySQL alert_pg_update_log table
 * 
 * Tracks alerts that need to be synchronized from MySQL to PostgreSQL.
 * This table is written by the Java application when alerts are created/updated.
 * The sync worker reads from this table and updates it after processing.
 * 
 * Status values:
 * - 1: Pending (needs processing)
 * - 2: Completed successfully
 * - 3: Failed (permanent failure after retries)
 * 
 * @property int $id
 * @property int $alert_id
 * @property int $status
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property string|null $error_message
 * @property int $retry_count
 */
class AlertUpdateLog extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'mysql';

    /**
     * The table associated with the model.
     */
    protected $table = 'alert_pg_update_log';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'alert_id',
        'status',
        'error_message',
        'retry_count',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'alert_id' => 'integer',
        'status' => 'integer',
        'retry_count' => 'integer',
    ];

    /**
     * Status constants
     */
    const STATUS_PENDING = 1;
    const STATUS_COMPLETED = 2;
    const STATUS_FAILED = 3;

    /**
     * Scope to get pending entries (status = 1)
     * Orders by created_at ascending (oldest first)
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->orderBy('created_at', 'asc');
    }

    /**
     * Scope to get completed entries (status = 2)
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope to get failed entries (status = 3)
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope to get entries by alert ID
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $alertId The alert ID to filter by
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForAlert($query, int $alertId)
    {
        return $query->where('alert_id', $alertId);
    }

    /**
     * Check if the entry is pending
     * 
     * @return bool True if status is pending (1)
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the entry is completed
     * 
     * @return bool True if status is completed (2)
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the entry is failed
     * 
     * @return bool True if status is failed (3)
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Mark the entry as completed
     * 
     * Sets status to completed (2) and updates the timestamp.
     * 
     * @return bool True if save succeeded, false otherwise
     */
    public function markCompleted(): bool
    {
        $this->status = self::STATUS_COMPLETED;
        $this->updated_at = now();
        return $this->save();
    }

    /**
     * Mark the entry as failed with an error message
     * 
     * Sets status to failed (3), records the error message,
     * and updates the timestamp.
     * 
     * @param string $errorMessage Description of the failure
     * @return bool True if save succeeded, false otherwise
     */
    public function markFailed(string $errorMessage): bool
    {
        $this->status = self::STATUS_FAILED;
        $this->error_message = $errorMessage;
        $this->updated_at = now();
        return $this->save();
    }

    /**
     * Increment the retry count
     * 
     * Increments the retry_count field by 1 and saves the model.
     * 
     * @return bool True if save succeeded, false otherwise
     */
    public function incrementRetryCount(): bool
    {
        $this->retry_count++;
        return $this->save();
    }
}
