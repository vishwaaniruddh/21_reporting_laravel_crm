<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * BackAlert Model
 * 
 * Represents backalerts table records for PostgreSQL sync operations.
 * Similar to Alert model but for backalerts table.
 */
class BackAlert extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'backalerts';

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
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'panelid',
        'seqno',
        'zone',
        'alarm',
        'createtime',
        'receivedtime',
        'comment',
        'status',
        'sendtoclient',
        'closedBy',
        'closedtime',
        'sendip',
        'alerttype',
        'location',
        'priority',
        'AlertUserStatus',
        'level',
        'sip2',
        'c_status',
        'auto_alert',
        'critical_alerts',
        'synced_at',
        'sync_batch_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'createtime' => 'datetime',
        'receivedtime' => 'datetime',
        'closedtime' => 'datetime',
        'synced_at' => 'datetime',
        'level' => 'integer',
        'auto_alert' => 'integer',
        'sync_batch_id' => 'integer',
    ];

    /**
     * Scope a query to only include unsynced backalerts.
     */
    public function scopeUnsynced(Builder $query): Builder
    {
        return $query->whereNull('synced_at');
    }

    /**
     * Scope a query to only include synced backalerts.
     */
    public function scopeSynced(Builder $query): Builder
    {
        return $query->whereNotNull('synced_at');
    }

    /**
     * Scope a query to filter by sync batch ID.
     */
    public function scopeBySyncBatch(Builder $query, int $batchId): Builder
    {
        return $query->where('sync_batch_id', $batchId);
    }

    /**
     * Scope a query to filter by date range.
     */
    public function scopeByDateRange(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->whereBetween('receivedtime', [$startDate, $endDate]);
    }

    /**
     * Scope a query to filter by panel ID.
     */
    public function scopeByPanel(Builder $query, string $panelId): Builder
    {
        return $query->where('panelid', $panelId);
    }

    /**
     * Get the update logs for this backalert.
     */
    public function updateLogs()
    {
        return $this->hasMany(BackAlertUpdateLog::class, 'backalert_id', 'id');
    }

    /**
     * Mark this backalert as synced.
     */
    public function markAsSynced(int $batchId = null): bool
    {
        return $this->update([
            'synced_at' => now(),
            'sync_batch_id' => $batchId,
        ]);
    }

    /**
     * Check if this backalert is synced.
     */
    public function isSynced(): bool
    {
        return !is_null($this->synced_at);
    }

    /**
     * Get the partition table name for this backalert based on receivedtime.
     */
    public function getPartitionTableName(): string
    {
        $date = $this->receivedtime;
        return 'backalerts_' . $date->format('Y_m_d');
    }
}