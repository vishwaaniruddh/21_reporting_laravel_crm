<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alert model for MySQL source database
 * Contains ATM monitoring alerts that need to be synced to PostgreSQL
 * 
 * Actual MySQL table columns:
 * id, panelid, seqno, zone, alarm, createtime, receivedtime, comment, status,
 * sendtoclient, closedBy, closedtime, sendip, alerttype, location, priority,
 * AlertUserStatus, level, sip2, c_status, auto_alert, critical_alerts, Readstatus,
 * synced_at, sync_batch_id
 */
class Alert extends Model
{
    protected $connection = 'mysql';
    protected $table = 'alerts';
    
    // Disable timestamps since the table uses createtime instead of created_at/updated_at
    public $timestamps = false;

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
     * Scope to get unsynced records
     */
    public function scopeUnsynced($query)
    {
        return $query->whereNull('synced_at');
    }

    /**
     * Scope to get synced and verified records (ready for cleanup)
     */
    public function scopeSyncedAndVerified($query)
    {
        return $query->whereNotNull('synced_at')
            ->whereHas('syncBatch', fn($q) => $q->where('status', 'verified'));
    }

    /**
     * Scope to get records by batch
     */
    public function scopeByBatch($query, int $batchId)
    {
        return $query->where('sync_batch_id', $batchId);
    }

    /**
     * Get the sync batch this alert belongs to
     */
    public function syncBatch(): BelongsTo
    {
        return $this->belongsTo(SyncBatch::class, 'sync_batch_id');
    }
}
