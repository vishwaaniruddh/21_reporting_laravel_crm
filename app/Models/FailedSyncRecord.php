<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FailedSyncRecord model for PostgreSQL
 * 
 * Stores records that repeatedly fail to sync from MySQL to PostgreSQL.
 * This serves as an error queue for admin review and retry.
 * 
 * Requirements: 7.5
 */
class FailedSyncRecord extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'failed_sync_records';

    protected $fillable = [
        'alert_id',
        'batch_id',
        'alert_data',
        'error_message',
        'retry_count',
        'last_retry_at',
        'status',
        'admin_notes',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'alert_data' => 'array',
        'last_retry_at' => 'datetime',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_RETRYING = 'retrying';
    const STATUS_RESOLVED = 'resolved';
    const STATUS_IGNORED = 'ignored';

    // Maximum retry attempts before requiring manual intervention
    const MAX_AUTO_RETRIES = 3;

    /**
     * Scope to get pending records
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to get records eligible for auto-retry
     */
    public function scopeEligibleForRetry($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('retry_count', '<', self::MAX_AUTO_RETRIES);
    }

    /**
     * Scope to get records requiring manual review
     */
    public function scopeRequiresManualReview($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('retry_count', '>=', self::MAX_AUTO_RETRIES);
    }

    /**
     * Scope to get unresolved records
     */
    public function scopeUnresolved($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_RETRYING]);
    }

    /**
     * Add a failed record to the error queue
     */
    public static function addToQueue(
        int $alertId,
        ?int $batchId,
        array $alertData,
        string $errorMessage
    ): self {
        return self::updateOrCreate(
            [
                'alert_id' => $alertId,
                'batch_id' => $batchId,
            ],
            [
                'alert_data' => $alertData,
                'error_message' => substr($errorMessage, 0, 1000),
                'status' => self::STATUS_PENDING,
                'last_retry_at' => now(),
            ]
        );
    }

    /**
     * Increment retry count
     */
    public function incrementRetry(string $errorMessage): void
    {
        $this->update([
            'retry_count' => $this->retry_count + 1,
            'error_message' => substr($errorMessage, 0, 1000),
            'last_retry_at' => now(),
        ]);
    }

    /**
     * Mark as resolved
     */
    public function markResolved(?int $userId = null, ?string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_RESOLVED,
            'resolved_by' => $userId,
            'resolved_at' => now(),
            'admin_notes' => $notes,
        ]);
    }

    /**
     * Mark as ignored (admin decided not to retry)
     */
    public function markIgnored(?int $userId = null, ?string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_IGNORED,
            'resolved_by' => $userId,
            'resolved_at' => now(),
            'admin_notes' => $notes,
        ]);
    }

    /**
     * Mark as retrying
     */
    public function markRetrying(): void
    {
        $this->update([
            'status' => self::STATUS_RETRYING,
        ]);
    }

    /**
     * Reset to pending for retry
     */
    public function resetForRetry(): void
    {
        $this->update([
            'status' => self::STATUS_PENDING,
        ]);
    }

    /**
     * Check if record can be auto-retried
     */
    public function canAutoRetry(): bool
    {
        return $this->status === self::STATUS_PENDING 
            && $this->retry_count < self::MAX_AUTO_RETRIES;
    }

    /**
     * Check if record requires manual review
     */
    public function requiresManualReview(): bool
    {
        return $this->status === self::STATUS_PENDING 
            && $this->retry_count >= self::MAX_AUTO_RETRIES;
    }

    /**
     * Get the resolver user
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
