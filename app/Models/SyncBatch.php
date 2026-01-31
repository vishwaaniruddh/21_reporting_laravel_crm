<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SyncBatch model for MySQL
 * Tracks batches of alerts being synced to PostgreSQL
 */
class SyncBatch extends Model
{
    protected $connection = 'mysql';
    protected $table = 'sync_batches';

    protected $fillable = [
        'start_id',
        'end_id',
        'records_count',
        'status',
        'started_at',
        'completed_at',
        'verified_at',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_VERIFIED = 'verified';
    const STATUS_CLEANED = 'cleaned';

    /**
     * Get alerts in this batch
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'sync_batch_id');
    }

    /**
     * Scope to get pending batches
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to get completed but not verified batches
     */
    public function scopeCompletedNotVerified($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope to get verified batches ready for cleanup
     */
    public function scopeVerifiedForCleanup($query, int $retentionDays = 7)
    {
        return $query->where('status', self::STATUS_VERIFIED)
            ->where('verified_at', '<=', now()->subDays($retentionDays));
    }

    /**
     * Mark batch as processing
     */
    public function markProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'started_at' => now(),
        ]);
    }

    /**
     * Mark batch as completed
     */
    public function markCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark batch as failed
     */
    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Mark batch as verified
     */
    public function markVerified(): void
    {
        $this->update([
            'status' => self::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);
    }

    /**
     * Mark batch as cleaned
     */
    public function markCleaned(): void
    {
        $this->update([
            'status' => self::STATUS_CLEANED,
        ]);
    }
}
