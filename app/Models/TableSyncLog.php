<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TableSyncLog model for PostgreSQL
 * Tracks sync operations for configured tables.
 * ⚠️ NO DELETION FROM MYSQL: Model connects to PostgreSQL only
 */
class TableSyncLog extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'table_sync_logs';

    protected $fillable = [
        'configuration_id',
        'source_table',
        'records_synced',
        'records_failed',
        'start_id',
        'end_id',
        'status',
        'error_message',
        'duration_ms',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'configuration_id' => 'integer',
        'records_synced' => 'integer',
        'records_failed' => 'integer',
        'start_id' => 'integer',
        'end_id' => 'integer',
        'duration_ms' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Status constants
    const STATUS_RUNNING = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_PARTIAL = 'partial';

    /**
     * Get the configuration that owns this log.
     */
    public function configuration(): BelongsTo
    {
        return $this->belongsTo(TableSyncConfiguration::class, 'configuration_id');
    }

    /**
     * Start a new sync log entry.
     */
    public static function startSync(int $configurationId, string $sourceTable): self
    {
        return self::create([
            'configuration_id' => $configurationId,
            'source_table' => $sourceTable,
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
            'records_synced' => 0,
            'records_failed' => 0,
        ]);
    }

    /**
     * Complete the sync log with success.
     */
    public function completeSuccess(int $recordsSynced, ?int $startId = null, ?int $endId = null): bool
    {
        $completedAt = now();
        $durationMs = $this->started_at ? (int) abs($completedAt->diffInMilliseconds($this->started_at)) : null;

        return $this->update([
            'status' => self::STATUS_COMPLETED,
            'records_synced' => $recordsSynced,
            'start_id' => $startId,
            'end_id' => $endId,
            'duration_ms' => $durationMs,
            'completed_at' => $completedAt,
        ]);
    }

    /**
     * Complete the sync log with failure.
     */
    public function completeFailed(string $errorMessage, int $recordsSynced = 0, int $recordsFailed = 0, ?int $startId = null, ?int $endId = null): bool
    {
        $completedAt = now();
        $durationMs = $this->started_at ? (int) abs($completedAt->diffInMilliseconds($this->started_at)) : null;

        return $this->update([
            'status' => self::STATUS_FAILED,
            'records_synced' => $recordsSynced,
            'records_failed' => $recordsFailed,
            'start_id' => $startId,
            'end_id' => $endId,
            'error_message' => $errorMessage,
            'duration_ms' => $durationMs,
            'completed_at' => $completedAt,
        ]);
    }

    /**
     * Complete the sync log with partial success.
     */
    public function completePartial(int $recordsSynced, int $recordsFailed, ?string $errorMessage = null, ?int $startId = null, ?int $endId = null): bool
    {
        $completedAt = now();
        $durationMs = $this->started_at ? (int) abs($completedAt->diffInMilliseconds($this->started_at)) : null;

        return $this->update([
            'status' => self::STATUS_PARTIAL,
            'records_synced' => $recordsSynced,
            'records_failed' => $recordsFailed,
            'start_id' => $startId,
            'end_id' => $endId,
            'error_message' => $errorMessage,
            'duration_ms' => $durationMs,
            'completed_at' => $completedAt,
        ]);
    }

    /**
     * Scope to filter by status.
     */
    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by source table.
     */
    public function scopeForTable($query, string $tableName)
    {
        return $query->where('source_table', $tableName);
    }

    /**
     * Scope to filter by configuration.
     */
    public function scopeForConfiguration($query, int $configurationId)
    {
        return $query->where('configuration_id', $configurationId);
    }

    /**
     * Scope to get logs within a date range.
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('started_at', [$startDate, $endDate]);
    }

    /**
     * Scope to get recent logs.
     */
    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('started_at', '>=', now()->subDays($days));
    }

    /**
     * Check if the sync is still running.
     */
    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * Check if the sync completed successfully.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the sync failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
