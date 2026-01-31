<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SyncTracking Model
 * 
 * Tracks which records from MySQL have been synced to PostgreSQL.
 * This is a dedicated tracking table that doesn't modify source or target tables.
 * 
 * @property int $id
 * @property int $configuration_id
 * @property string $source_table
 * @property string $record_id
 * @property \Carbon\Carbon $synced_at
 * @property int|null $sync_log_id
 */
class SyncTracking extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'pgsql';

    /**
     * The table associated with the model.
     */
    protected $table = 'sync_tracking';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'configuration_id',
        'source_table',
        'record_id',
        'synced_at',
        'sync_log_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'synced_at' => 'datetime',
    ];

    /**
     * Get the configuration that owns this tracking record.
     */
    public function configuration(): BelongsTo
    {
        return $this->belongsTo(TableSyncConfiguration::class, 'configuration_id');
    }

    /**
     * Get the sync log associated with this tracking record.
     */
    public function syncLog(): BelongsTo
    {
        return $this->belongsTo(TableSyncLog::class, 'sync_log_id');
    }

    /**
     * Check if a record has been synced.
     */
    public static function isSynced(int $configId, string $sourceTable, string $recordId): bool
    {
        return static::where('configuration_id', $configId)
            ->where('source_table', $sourceTable)
            ->where('record_id', $recordId)
            ->exists();
    }

    /**
     * Mark a record as synced.
     */
    public static function markSynced(
        int $configId,
        string $sourceTable,
        string $recordId,
        ?int $syncLogId = null
    ): static {
        return static::updateOrCreate(
            [
                'configuration_id' => $configId,
                'source_table' => $sourceTable,
                'record_id' => $recordId,
            ],
            [
                'synced_at' => now(),
                'sync_log_id' => $syncLogId,
            ]
        );
    }

    /**
     * Bulk mark records as synced.
     */
    public static function bulkMarkSynced(
        int $configId,
        string $sourceTable,
        array $recordIds,
        ?int $syncLogId = null
    ): int {
        $now = now();
        $data = [];

        foreach ($recordIds as $recordId) {
            $data[] = [
                'configuration_id' => $configId,
                'source_table' => $sourceTable,
                'record_id' => (string) $recordId,
                'synced_at' => $now,
                'sync_log_id' => $syncLogId,
            ];
        }

        // Use upsert to handle duplicates
        return static::upsert(
            $data,
            ['configuration_id', 'source_table', 'record_id'],
            ['synced_at', 'sync_log_id']
        );
    }

    /**
     * Get synced record IDs for a configuration.
     */
    public static function getSyncedIds(int $configId, string $sourceTable): array
    {
        return static::where('configuration_id', $configId)
            ->where('source_table', $sourceTable)
            ->pluck('record_id')
            ->toArray();
    }

    /**
     * Get count of synced records for a configuration.
     */
    public static function getSyncedCount(int $configId, string $sourceTable): int
    {
        return static::where('configuration_id', $configId)
            ->where('source_table', $sourceTable)
            ->count();
    }

    /**
     * Remove tracking for a configuration (when config is deleted).
     */
    public static function removeForConfiguration(int $configId): int
    {
        return static::where('configuration_id', $configId)->delete();
    }
}
