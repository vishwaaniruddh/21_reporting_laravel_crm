<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SyncLog model for PostgreSQL
 * Tracks all sync, verify, and cleanup operations
 */
class SyncLog extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'sync_logs';

    protected $fillable = [
        'batch_id',
        'operation',
        'records_affected',
        'status',
        'duration_ms',
        'error_message',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    const OPERATION_SYNC = 'sync';
    const OPERATION_VERIFY = 'verify';
    const OPERATION_CLEANUP = 'cleanup';

    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';
    const STATUS_PARTIAL = 'partial';

    /**
     * Log a sync operation
     */
    public static function logSync(int $batchId, int $recordsAffected, string $status, ?int $durationMs = null, ?string $errorMessage = null): self
    {
        return self::create([
            'batch_id' => $batchId,
            'operation' => self::OPERATION_SYNC,
            'records_affected' => $recordsAffected,
            'status' => $status,
            'duration_ms' => $durationMs,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Log a verify operation
     */
    public static function logVerify(int $batchId, int $recordsAffected, string $status, ?int $durationMs = null, ?string $errorMessage = null): self
    {
        return self::create([
            'batch_id' => $batchId,
            'operation' => self::OPERATION_VERIFY,
            'records_affected' => $recordsAffected,
            'status' => $status,
            'duration_ms' => $durationMs,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Log a cleanup operation
     */
    public static function logCleanup(int $batchId, int $recordsAffected, string $status, ?int $durationMs = null, ?string $errorMessage = null): self
    {
        return self::create([
            'batch_id' => $batchId,
            'operation' => self::OPERATION_CLEANUP,
            'records_affected' => $recordsAffected,
            'status' => $status,
            'duration_ms' => $durationMs,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Scope to filter by operation type
     */
    public function scopeOfOperation($query, string $operation)
    {
        return $query->where('operation', $operation);
    }

    /**
     * Scope to get recent logs
     */
    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
