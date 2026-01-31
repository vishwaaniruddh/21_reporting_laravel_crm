<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CleanupBatch model for PostgreSQL
 * Tracks individual batch operations within a cleanup run.
 * 
 * Requirements: 5.4, 5.5
 */
class CleanupBatch extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'cleanup_batches';

    protected $fillable = [
        'cleanup_log_id',
        'batch_number',
        'records_identified',
        'records_verified',
        'records_deleted',
        'records_skipped',
        'skipped_record_ids',
        'skip_reason',
        'processed_at',
        'duration_ms',
    ];

    protected $casts = [
        'cleanup_log_id' => 'integer',
        'batch_number' => 'integer',
        'records_identified' => 'integer',
        'records_verified' => 'integer',
        'records_deleted' => 'integer',
        'records_skipped' => 'integer',
        'skipped_record_ids' => 'array',
        'duration_ms' => 'integer',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the cleanup log that owns this batch.
     */
    public function cleanupLog(): BelongsTo
    {
        return $this->belongsTo(CleanupLog::class, 'cleanup_log_id');
    }

    /**
     * Scope to filter batches for a specific cleanup log.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $cleanupLogId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForCleanupLog($query, int $cleanupLogId)
    {
        return $query->where('cleanup_log_id', $cleanupLogId);
    }

    /**
     * Scope to filter batches with skipped records.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithSkippedRecords($query)
    {
        return $query->where('records_skipped', '>', 0);
    }

    /**
     * Scope to filter batches processed within a date range.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param \Carbon\Carbon $startDate
     * @param \Carbon\Carbon $endDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('processed_at', [$startDate, $endDate]);
    }

    /**
     * Check if this batch had any skipped records.
     * 
     * @return bool
     */
    public function hasSkippedRecords(): bool
    {
        return $this->records_skipped > 0;
    }

    /**
     * Get the success rate for this batch (percentage of records deleted).
     * 
     * @return float
     */
    public function getSuccessRate(): float
    {
        if ($this->records_identified === 0) {
            return 0.0;
        }

        return ($this->records_deleted / $this->records_identified) * 100;
    }

    /**
     * Get the verification rate for this batch (percentage of records verified).
     * 
     * @return float
     */
    public function getVerificationRate(): float
    {
        if ($this->records_identified === 0) {
            return 0.0;
        }

        return ($this->records_verified / $this->records_identified) * 100;
    }
}
