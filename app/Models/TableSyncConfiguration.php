<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TableSyncConfiguration model for PostgreSQL
 * Stores sync configuration for each table to be synchronized.
 * ⚠️ NO DELETION FROM MYSQL: Model connects to PostgreSQL only
 */
class TableSyncConfiguration extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'table_sync_configurations';

    protected $fillable = [
        'name',
        'source_table',
        'target_table',
        'primary_key_column',
        'sync_marker_column',
        'column_mappings',
        'excluded_columns',
        'batch_size',
        'schedule',
        'is_enabled',
        'last_sync_at',
        'last_sync_status',
    ];

    protected $casts = [
        'column_mappings' => 'array',
        'excluded_columns' => 'array',
        'batch_size' => 'integer',
        'is_enabled' => 'boolean',
        'last_sync_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'primary_key_column' => 'id',
        'sync_marker_column' => 'synced_at',
        'column_mappings' => '{}',
        'excluded_columns' => '[]',
        'batch_size' => 10000,
        'is_enabled' => true,
    ];

    // Status constants
    const STATUS_IDLE = 'idle';
    const STATUS_RUNNING = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    /**
     * Get the sync logs for this configuration.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(TableSyncLog::class, 'configuration_id');
    }

    /**
     * Get the sync errors for this configuration.
     */
    public function errors(): HasMany
    {
        return $this->hasMany(TableSyncError::class, 'configuration_id');
    }

    /**
     * Scope to get only enabled configurations.
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope to get only disabled configurations.
     */
    public function scopeDisabled($query)
    {
        return $query->where('is_enabled', false);
    }

    /**
     * Scope to get configurations with a schedule.
     */
    public function scopeScheduled($query)
    {
        return $query->whereNotNull('schedule');
    }

    /**
     * Scope to get configurations by source table.
     */
    public function scopeForSourceTable($query, string $tableName)
    {
        return $query->where('source_table', $tableName);
    }

    /**
     * Get the target table name (defaults to source table if not set).
     */
    public function getEffectiveTargetTable(): string
    {
        return $this->target_table ?: $this->source_table;
    }

    /**
     * Check if this configuration has custom column mappings.
     */
    public function hasColumnMappings(): bool
    {
        return !empty($this->column_mappings);
    }

    /**
     * Check if this configuration has excluded columns.
     */
    public function hasExcludedColumns(): bool
    {
        return !empty($this->excluded_columns);
    }

    /**
     * Get the most recent sync log.
     */
    public function getLatestLog(): ?TableSyncLog
    {
        return $this->logs()->latest('started_at')->first();
    }

    /**
     * Get unresolved errors count.
     */
    public function getUnresolvedErrorsCount(): int
    {
        return $this->errors()->whereNull('resolved_at')->count();
    }

    /**
     * Update the last sync status.
     */
    public function updateSyncStatus(string $status, ?\DateTime $syncedAt = null): bool
    {
        $data = ['last_sync_status' => $status];
        
        if ($syncedAt !== null) {
            $data['last_sync_at'] = $syncedAt;
        }
        
        return $this->update($data);
    }
}
