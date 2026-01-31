<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TableSyncError model for PostgreSQL
 * Stores failed sync records for retry and troubleshooting.
 * ⚠️ NO DELETION FROM MYSQL: Model connects to PostgreSQL only
 */
class TableSyncError extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'table_sync_errors';

    protected $fillable = [
        'configuration_id',
        'source_table',
        'record_id',
        'record_data',
        'error_message',
        'retry_count',
        'last_retry_at',
        'resolved_at',
    ];

    protected $casts = [
        'configuration_id' => 'integer',
        'record_id' => 'integer',
        'record_data' => 'array',
        'retry_count' => 'integer',
        'last_retry_at' => 'datetime',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Maximum retry attempts before giving up
    const MAX_RETRY_ATTEMPTS = 5;

    /**
     * Get the configuration that owns this error.
     */
    public function configuration(): BelongsTo
    {
        return $this->belongsTo(TableSyncConfiguration::class, 'configuration_id');
    }

    /**
     * Add a record to the error queue.
     */
    public static function addToQueue(int $configurationId, string $sourceTable, int $recordId, array $recordData, string $errorMessage): self
    {
        // Check if this record already exists in the queue
        $existing = self::where('configuration_id', $configurationId)
            ->where('record_id', $recordId)
            ->whereNull('resolved_at')
            ->first();

        if ($existing) {
            // Update existing error entry
            $existing->update([
                'record_data' => $recordData,
                'error_message' => $errorMessage,
                'retry_count' => $existing->retry_count + 1,
                'last_retry_at' => now(),
            ]);
            return $existing;
        }

        // Create new error entry
        return self::create([
            'configuration_id' => $configurationId,
            'source_table' => $sourceTable,
            'record_id' => $recordId,
            'record_data' => $recordData,
            'error_message' => $errorMessage,
            'retry_count' => 0,
        ]);
    }

    /**
     * Mark this error as resolved.
     */
    public function markResolved(): bool
    {
        return $this->update([
            'resolved_at' => now(),
        ]);
    }

    /**
     * Increment retry count and update last retry timestamp.
     */
    public function incrementRetry(): bool
    {
        return $this->update([
            'retry_count' => $this->retry_count + 1,
            'last_retry_at' => now(),
        ]);
    }

    /**
     * Update error message after a retry attempt.
     */
    public function updateError(string $errorMessage): bool
    {
        return $this->update([
            'error_message' => $errorMessage,
            'retry_count' => $this->retry_count + 1,
            'last_retry_at' => now(),
        ]);
    }

    /**
     * Check if this error can be retried.
     */
    public function canRetry(): bool
    {
        return $this->retry_count < self::MAX_RETRY_ATTEMPTS && $this->resolved_at === null;
    }

    /**
     * Check if this error is resolved.
     */
    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * Check if max retries have been exceeded.
     */
    public function hasExceededMaxRetries(): bool
    {
        return $this->retry_count >= self::MAX_RETRY_ATTEMPTS;
    }

    /**
     * Scope to get unresolved errors.
     */
    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * Scope to get resolved errors.
     */
    public function scopeResolved($query)
    {
        return $query->whereNotNull('resolved_at');
    }

    /**
     * Scope to get retryable errors.
     */
    public function scopeRetryable($query)
    {
        return $query->whereNull('resolved_at')
            ->where('retry_count', '<', self::MAX_RETRY_ATTEMPTS);
    }

    /**
     * Scope to filter by configuration.
     */
    public function scopeForConfiguration($query, int $configurationId)
    {
        return $query->where('configuration_id', $configurationId);
    }

    /**
     * Scope to filter by source table.
     */
    public function scopeForTable($query, string $tableName)
    {
        return $query->where('source_table', $tableName);
    }

    /**
     * Scope to get errors that have exceeded max retries.
     */
    public function scopeExceededMaxRetries($query)
    {
        return $query->whereNull('resolved_at')
            ->where('retry_count', '>=', self::MAX_RETRY_ATTEMPTS);
    }
}
