<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * PartitionSyncError model for PostgreSQL
 * 
 * Tracks failed partition sync operations and provides retry mechanisms.
 * This model stores alerts that fail to sync to partition tables, allowing
 * for error analysis and automated retry logic.
 * 
 * Requirements: 8.3
 */
class PartitionSyncError extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'partition_sync_errors';

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_RETRYING = 'retrying';
    const STATUS_FAILED = 'failed';
    const STATUS_RESOLVED = 'resolved';

    // Error type constants
    const ERROR_PARTITION_CREATION = 'partition_creation_failed';
    const ERROR_INSERT_FAILED = 'insert_failed';
    const ERROR_TRANSACTION_FAILED = 'transaction_failed';
    const ERROR_VALIDATION_FAILED = 'validation_failed';
    const ERROR_UNKNOWN = 'unknown_error';

    protected $fillable = [
        'alert_id',
        'partition_date',
        'partition_table',
        'error_type',
        'error_message',
        'error_trace',
        'error_code',
        'retry_count',
        'max_retries',
        'last_retry_at',
        'next_retry_at',
        'status',
        'resolved_at',
        'resolution_notes',
        'alert_data',
        'sync_batch_id',
    ];

    protected $casts = [
        'partition_date' => 'date',
        'retry_count' => 'integer',
        'max_retries' => 'integer',
        'error_code' => 'integer',
        'last_retry_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'resolved_at' => 'datetime',
        'alert_data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Create a new error record for a failed partition operation
     * 
     * @param int $alertId The alert ID
     * @param Carbon $partitionDate The partition date
     * @param string $partitionTable The partition table name
     * @param string $errorType The error type
     * @param string $errorMessage The error message
     * @param array $alertData The alert data snapshot
     * @param int|null $syncBatchId Optional sync batch ID
     * @param string|null $errorTrace Optional error trace
     * @param int|null $errorCode Optional error code
     * @return self
     */
    public static function createError(
        int $alertId,
        Carbon $partitionDate,
        string $partitionTable,
        string $errorType,
        string $errorMessage,
        array $alertData,
        ?int $syncBatchId = null,
        ?string $errorTrace = null,
        ?int $errorCode = null
    ): self {
        return self::create([
            'alert_id' => $alertId,
            'partition_date' => $partitionDate,
            'partition_table' => $partitionTable,
            'error_type' => $errorType,
            'error_message' => $errorMessage,
            'error_trace' => $errorTrace,
            'error_code' => $errorCode,
            'retry_count' => 0,
            'max_retries' => 3,
            'status' => self::STATUS_PENDING,
            'alert_data' => $alertData,
            'sync_batch_id' => $syncBatchId,
            'next_retry_at' => now()->addMinutes(5), // First retry in 5 minutes
        ]);
    }

    /**
     * Mark this error as being retried
     * 
     * @return bool
     */
    public function markRetrying(): bool
    {
        $this->retry_count++;
        $this->last_retry_at = now();
        $this->status = self::STATUS_RETRYING;
        
        // Calculate next retry time with exponential backoff
        // 5 min, 15 min, 45 min
        $backoffMinutes = 5 * pow(3, $this->retry_count - 1);
        $this->next_retry_at = now()->addMinutes($backoffMinutes);
        
        return $this->save();
    }

    /**
     * Mark this error as failed (max retries exceeded)
     * 
     * @return bool
     */
    public function markFailed(): bool
    {
        $this->status = self::STATUS_FAILED;
        $this->next_retry_at = null;
        
        return $this->save();
    }

    /**
     * Mark this error as resolved
     * 
     * @param string|null $notes Optional resolution notes
     * @return bool
     */
    public function markResolved(?string $notes = null): bool
    {
        $this->status = self::STATUS_RESOLVED;
        $this->resolved_at = now();
        $this->resolution_notes = $notes;
        $this->next_retry_at = null;
        
        return $this->save();
    }

    /**
     * Check if this error can be retried
     * 
     * @return bool
     */
    public function canRetry(): bool
    {
        return $this->retry_count < $this->max_retries
            && in_array($this->status, [self::STATUS_PENDING, self::STATUS_RETRYING]);
    }

    /**
     * Check if this error is ready for retry
     * 
     * @return bool
     */
    public function isReadyForRetry(): bool
    {
        return $this->canRetry()
            && $this->next_retry_at !== null
            && $this->next_retry_at->isPast();
    }

    /**
     * Get all errors ready for retry
     * 
     * @return Collection
     */
    public static function getReadyForRetry(): Collection
    {
        return self::where('status', self::STATUS_PENDING)
            ->orWhere('status', self::STATUS_RETRYING)
            ->where('next_retry_at', '<=', now())
            ->whereRaw('retry_count < max_retries')
            ->orderBy('next_retry_at')
            ->get();
    }

    /**
     * Get all pending errors
     * 
     * @return Collection
     */
    public static function getPending(): Collection
    {
        return self::where('status', self::STATUS_PENDING)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Get all failed errors (max retries exceeded)
     * 
     * @return Collection
     */
    public static function getFailed(): Collection
    {
        return self::where('status', self::STATUS_FAILED)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get errors by partition date
     * 
     * @param Carbon $date
     * @return Collection
     */
    public static function getByPartitionDate(Carbon $date): Collection
    {
        return self::where('partition_date', $date->toDateString())
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Get errors by partition table
     * 
     * @param string $tableName
     * @return Collection
     */
    public static function getByPartitionTable(string $tableName): Collection
    {
        return self::where('partition_table', $tableName)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Get errors by error type
     * 
     * @param string $errorType
     * @return Collection
     */
    public static function getByErrorType(string $errorType): Collection
    {
        return self::where('error_type', $errorType)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get error statistics
     * 
     * @return array
     */
    public static function getStatistics(): array
    {
        return [
            'total' => self::count(),
            'pending' => self::where('status', self::STATUS_PENDING)->count(),
            'retrying' => self::where('status', self::STATUS_RETRYING)->count(),
            'failed' => self::where('status', self::STATUS_FAILED)->count(),
            'resolved' => self::where('status', self::STATUS_RESOLVED)->count(),
            'ready_for_retry' => self::getReadyForRetry()->count(),
        ];
    }

    /**
     * Get errors grouped by error type
     * 
     * @return Collection
     */
    public static function getGroupedByErrorType(): Collection
    {
        return self::selectRaw('error_type, count(*) as count')
            ->groupBy('error_type')
            ->orderBy('count', 'desc')
            ->get();
    }

    /**
     * Get errors grouped by partition date
     * 
     * @return Collection
     */
    public static function getGroupedByPartitionDate(): Collection
    {
        return self::selectRaw('partition_date, count(*) as count')
            ->groupBy('partition_date')
            ->orderBy('partition_date', 'desc')
            ->get();
    }

    /**
     * Scope to filter by status
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by error type
     */
    public function scopeErrorType($query, string $errorType)
    {
        return $query->where('error_type', $errorType);
    }

    /**
     * Scope to get recent errors
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }
}
