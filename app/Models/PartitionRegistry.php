<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Carbon\Carbon as CarbonCarbon;

/**
 * PartitionRegistry model for PostgreSQL
 * Tracks metadata about date-partitioned alert tables
 */
class PartitionRegistry extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'partition_registry';

    // Disable updated_at since we only have created_at and last_synced_at
    const UPDATED_AT = null;

    protected $fillable = [
        'table_name',
        'partition_date',
        'record_count',
        'last_synced_at',
        'table_type',
    ];

    protected $casts = [
        'partition_date' => 'date',
        'record_count' => 'integer',
        'created_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'table_type' => 'string',
    ];

    /**
     * Register a new partition table
     * 
     * @param string $tableName The partition table name (e.g., 'alerts_2026_01_08')
     * @param Carbon|CarbonCarbon $partitionDate The date this partition represents
     * @param string $tableType The type of table ('alerts' or 'backalerts')
     * @return self
     */
    public static function registerPartition(string $tableName, Carbon|CarbonCarbon $partitionDate, string $tableType = 'alerts'): self
    {
        return self::firstOrCreate(
            ['table_name' => $tableName],
            [
                'partition_date' => $partitionDate,
                'record_count' => 0,
                'table_type' => $tableType,
            ]
        );
    }

    /**
     * Get partition info by table name
     * 
     * @param string $tableName
     * @return self|null
     */
    public static function getPartitionInfo(string $tableName): ?self
    {
        return self::where('table_name', $tableName)->first();
    }

    /**
     * Get partition info by date
     * 
     * @param Carbon|CarbonCarbon $date
     * @return self|null
     */
    public static function getPartitionByDate(Carbon|CarbonCarbon $date): ?self
    {
        return self::where('partition_date', $date->toDateString())->first();
    }

    /**
     * Get all partitions within a date range
     * 
     * @param Carbon|CarbonCarbon $startDate
     * @param Carbon|CarbonCarbon $endDate
     * @return Collection
     */
    public static function getPartitionsInRange(Carbon|CarbonCarbon $startDate, Carbon|CarbonCarbon $endDate): Collection
    {
        return self::whereBetween('partition_date', [
            $startDate->toDateString(),
            $endDate->toDateString()
        ])
        ->orderBy('partition_date')
        ->get();
    }

    /**
     * Get all registered partitions
     * 
     * @return Collection
     */
    public static function getAllPartitions(): Collection
    {
        return self::orderBy('partition_date', 'desc')->get();
    }

    /**
     * Update the record count for a partition
     * 
     * @param string $tableName
     * @param int $count The new record count
     * @return bool
     */
    public static function updateRecordCount(string $tableName, int $count): bool
    {
        return self::where('table_name', $tableName)->update([
            'record_count' => $count,
            'last_synced_at' => now(),
        ]) > 0;
    }

    /**
     * Increment the record count for a partition
     * 
     * @param string $tableName
     * @param int $increment The amount to increment by
     * @return bool
     */
    public static function incrementRecordCount(string $tableName, int $increment): bool
    {
        $partition = self::where('table_name', $tableName)->first();
        
        if (!$partition) {
            return false;
        }

        $partition->record_count += $increment;
        $partition->last_synced_at = now();
        
        return $partition->save();
    }

    /**
     * Check if a partition exists for a given date
     * 
     * @param Carbon|CarbonCarbon $date
     * @return bool
     */
    public static function partitionExistsForDate(Carbon|CarbonCarbon $date): bool
    {
        return self::where('partition_date', $date->toDateString())->exists();
    }

    /**
     * Get the total record count across all partitions
     * 
     * @return int
     */
    public static function getTotalRecordCount(): int
    {
        return self::sum('record_count');
    }

    /**
     * Get partitions that haven't been synced recently
     * 
     * @param int $hours Number of hours to consider as "recent"
     * @return Collection
     */
    public static function getStalePartitions(int $hours = 24): Collection
    {
        return self::where(function ($query) use ($hours) {
            $query->whereNull('last_synced_at')
                  ->orWhere('last_synced_at', '<', now()->subHours($hours));
        })
        ->orderBy('partition_date')
        ->get();
    }

    /**
     * Scope to filter partitions by date range
     */
    public function scopeDateRange($query, Carbon|CarbonCarbon $startDate, Carbon|CarbonCarbon $endDate)
    {
        return $query->whereBetween('partition_date', [
            $startDate->toDateString(),
            $endDate->toDateString()
        ]);
    }

    /**
     * Scope to get recent partitions
     */
    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('partition_date', '>=', now()->subDays($days)->toDateString());
    }

    /**
     * Scope to order by partition date
     */
    public function scopeOrderByDate($query, string $direction = 'desc')
    {
        return $query->orderBy('partition_date', $direction);
    }

    /**
     * Get partitions by table type
     * 
     * @param string $tableType 'alerts' or 'backalerts'
     * @return Collection
     */
    public static function getPartitionsByType(string $tableType): Collection
    {
        return self::where('table_type', $tableType)
            ->orderBy('partition_date', 'desc')
            ->get();
    }

    /**
     * Get combined statistics for a specific date
     * 
     * @param Carbon|CarbonCarbon $date
     * @return array
     */
    public static function getCombinedStatsForDate(Carbon|CarbonCarbon $date): array
    {
        $partitions = self::where('partition_date', $date->toDateString())->get();
        
        $stats = [
            'date' => $date->toDateString(),
            'alerts_count' => 0,
            'backalerts_count' => 0,
            'total_count' => 0,
            'alerts_table' => null,
            'backalerts_table' => null,
        ];
        
        foreach ($partitions as $partition) {
            if ($partition->table_type === 'alerts') {
                $stats['alerts_count'] = $partition->record_count;
                $stats['alerts_table'] = $partition->table_name;
            } elseif ($partition->table_type === 'backalerts') {
                $stats['backalerts_count'] = $partition->record_count;
                $stats['backalerts_table'] = $partition->table_name;
            }
        }
        
        $stats['total_count'] = $stats['alerts_count'] + $stats['backalerts_count'];
        
        return $stats;
    }

    /**
     * Get all dates with combined statistics
     * 
     * @return Collection
     */
    public static function getAllCombinedStats(): Collection
    {
        $dates = self::distinct('partition_date')
            ->orderBy('partition_date', 'desc')
            ->pluck('partition_date');
        
        return $dates->map(function ($date) {
            return self::getCombinedStatsForDate(Carbon::parse($date));
        });
    }

    /**
     * Get total record count by table type
     * 
     * @param string $tableType 'alerts' or 'backalerts'
     * @return int
     */
    public static function getTotalRecordCountByType(string $tableType): int
    {
        return self::where('table_type', $tableType)->sum('record_count');
    }
}

