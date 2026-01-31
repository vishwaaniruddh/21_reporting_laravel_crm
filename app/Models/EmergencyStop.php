<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * EmergencyStop model for PostgreSQL
 * Manages emergency stop flags for cleanup services.
 * 
 * Requirements: 11.1, 11.2, 11.3, 11.5
 */
class EmergencyStop extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'emergency_stops';

    protected $fillable = [
        'service_name',
        'is_stopped',
        'reason',
        'stopped_by',
        'stopped_at',
        'cleared_at',
    ];

    protected $casts = [
        'is_stopped' => 'boolean',
        'stopped_at' => 'datetime',
        'cleared_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Service name constants
    const SERVICE_AGE_BASED_CLEANUP = 'age_based_cleanup';

    /**
     * Get or create an emergency stop record for a service.
     * 
     * @param string $serviceName
     * @return self
     */
    public static function forService(string $serviceName): self
    {
        return self::firstOrCreate(
            ['service_name' => $serviceName],
            ['is_stopped' => false]
        );
    }

    /**
     * Check if the service is currently stopped.
     * 
     * @param string $serviceName
     * @return bool
     */
    public static function isStopped(string $serviceName): bool
    {
        $stop = self::where('service_name', $serviceName)->first();
        
        return $stop ? $stop->is_stopped : false;
    }

    /**
     * Set the emergency stop flag for a service.
     * 
     * @param string $serviceName
     * @param string $reason
     * @param string|null $stoppedBy
     * @return bool
     */
    public static function setStop(string $serviceName, string $reason, ?string $stoppedBy = null): bool
    {
        $stop = self::forService($serviceName);
        
        return $stop->update([
            'is_stopped' => true,
            'reason' => $reason,
            'stopped_by' => $stoppedBy,
            'stopped_at' => now(),
            'cleared_at' => null,
        ]);
    }

    /**
     * Clear the emergency stop flag for a service.
     * 
     * @param string $serviceName
     * @return bool
     */
    public static function clearStop(string $serviceName): bool
    {
        $stop = self::where('service_name', $serviceName)->first();
        
        if (!$stop) {
            return true; // Already cleared (doesn't exist)
        }
        
        return $stop->update([
            'is_stopped' => false,
            'reason' => null,
            'stopped_by' => null,
            'cleared_at' => now(),
        ]);
    }

    /**
     * Activate the emergency stop for this service instance.
     * 
     * @param string $reason
     * @param string|null $stoppedBy
     * @return bool
     */
    public function activate(string $reason, ?string $stoppedBy = null): bool
    {
        return $this->update([
            'is_stopped' => true,
            'reason' => $reason,
            'stopped_by' => $stoppedBy,
            'stopped_at' => now(),
            'cleared_at' => null,
        ]);
    }

    /**
     * Deactivate the emergency stop for this service instance.
     * 
     * @return bool
     */
    public function deactivate(): bool
    {
        return $this->update([
            'is_stopped' => false,
            'reason' => null,
            'stopped_by' => null,
            'cleared_at' => now(),
        ]);
    }

    /**
     * Check if this service is currently stopped.
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_stopped;
    }

    /**
     * Scope to filter active emergency stops.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_stopped', true);
    }

    /**
     * Scope to filter inactive emergency stops.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInactive($query)
    {
        return $query->where('is_stopped', false);
    }

    /**
     * Scope to filter by service name.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $serviceName
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForServiceName($query, string $serviceName)
    {
        return $query->where('service_name', $serviceName);
    }
}
