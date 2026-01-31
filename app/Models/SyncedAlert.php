<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SyncedAlert model for PostgreSQL
 * Contains alerts synced from MySQL for reporting purposes
 * 
 * Mirrors the MySQL alerts table structure
 */
class SyncedAlert extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'alerts';
    
    // ID comes from MySQL, not auto-generated
    public $incrementing = false;
    
    // Disable timestamps since the source table uses createtime
    public $timestamps = false;

    protected $fillable = [
        'id',
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
        'Readstatus',
        'synced_at',
        'sync_batch_id',
    ];

    protected $casts = [
        'createtime' => 'datetime',
        'receivedtime' => 'datetime',
        'closedtime' => 'datetime',
        'synced_at' => 'datetime',
    ];

    /**
     * Scope to filter by date range (using createtime)
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('createtime', [$startDate, $endDate]);
    }

    /**
     * Scope to filter by alert type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('alerttype', $type);
    }

    /**
     * Scope to filter by priority (severity equivalent)
     */
    public function scopeOfPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope to filter by panel (terminal equivalent)
     */
    public function scopeForPanel($query, string $panelId)
    {
        return $query->where('panelid', $panelId);
    }

    /**
     * Scope to get records by batch
     */
    public function scopeByBatch($query, int $batchId)
    {
        return $query->where('sync_batch_id', $batchId);
    }
}
