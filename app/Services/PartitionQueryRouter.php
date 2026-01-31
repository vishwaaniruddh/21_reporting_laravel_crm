<?php

namespace App\Services;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PartitionQueryRouter
 * 
 * Routes queries across multiple date-partitioned alert tables.
 * This service is responsible for:
 * - Accepting date range parameters for queries
 * - Identifying all partition tables in date range
 * - Building UNION ALL queries across partitions
 * - Handling missing partitions gracefully
 * - Aggregating results from multiple partitions
 * - Supporting filtering by alert_type, severity, terminal_id
 * - Supporting ordering and pagination
 * - Optimizing query performance with partition pruning
 * - Returning results in consistent format
 * 
 * Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 10.2, 10.3, 10.4
 */
class PartitionQueryRouter
{
    /**
     * PartitionManager for partition discovery
     */
    private PartitionManager $partitionManager;
    
    /**
     * DateExtractor for partition naming
     */
    private DateExtractor $dateExtractor;
    
    /**
     * PostgreSQL connection name
     */
    private string $connection = 'pgsql';
    
    /**
     * Create a new PartitionQueryRouter instance
     * 
     * @param PartitionManager|null $partitionManager Optional PartitionManager instance
     * @param DateExtractor|null $dateExtractor Optional DateExtractor instance
     */
    public function __construct(
        ?PartitionManager $partitionManager = null,
        ?DateExtractor $dateExtractor = null
    ) {
        $this->partitionManager = $partitionManager ?? new PartitionManager();
        $this->dateExtractor = $dateExtractor ?? new DateExtractor();
    }
    
    /**
     * Query alerts across date range with optional filters
     * 
     * This is the main entry point for cross-partition queries.
     * It identifies all relevant partitions, builds a UNION ALL query,
     * and returns aggregated results.
     * 
     * Requirements: 6.1, 6.2, 6.3, 6.4, 10.2, 10.3
     * 
     * @param Carbon $startDate Start date (inclusive)
     * @param Carbon $endDate End date (inclusive)
     * @param array $filters Optional filters (alert_type, severity, terminal_id, status, etc.)
     * @param array $options Optional query options (order_by, order_direction, limit, offset)
     * @param array $tablePrefixes Optional table prefixes to query (default: ['alerts'])
     * @return Collection Collection of alert records
     */
    public function queryDateRange(
        Carbon $startDate,
        Carbon $endDate,
        array $filters = [],
        array $options = [],
        array $tablePrefixes = ['alerts']
    ): Collection {
        try {
            // Get all partitions in the date range for all specified table prefixes
            $allPartitions = collect();
            
            foreach ($tablePrefixes as $prefix) {
                $partitions = $this->getPartitionsInRange($startDate, $endDate, $prefix);
                $allPartitions = $allPartitions->merge($partitions);
            }
            
            if ($allPartitions->isEmpty()) {
                Log::info('No partitions found in date range', [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'table_prefixes' => $tablePrefixes
                ]);
                
                return collect([]);
            }
            
            // Build and execute the union query
            $sql = $this->buildUnionQuery($allPartitions, $filters, $options);
            
            Log::debug('Executing cross-partition query', [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'partition_count' => $allPartitions->count(),
                'table_prefixes' => $tablePrefixes,
                'filters' => $filters
            ]);
            
            // Execute the query
            $results = DB::connection($this->connection)->select($sql);
            
            return collect($results);
            
        } catch (Exception $e) {
            Log::error('Failed to query date range', [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'table_prefixes' => $tablePrefixes,
                'filters' => $filters,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception(
                "Failed to query date range: {$e->getMessage()}",
                0,
                $e
            );
        }
    }
    
    /**
     * Get all partition tables within a date range
     * 
     * Identifies partition tables that exist for dates within the specified range.
     * Missing partitions are skipped gracefully without errors.
     * 
     * Requirements: 6.2, 6.5
     * 
     * @param Carbon $startDate Start date (inclusive)
     * @param Carbon $endDate End date (inclusive)
     * @param string $tablePrefix Table prefix (e.g., 'alerts', 'backalerts')
     * @return Collection Collection of partition table names
     */
    public function getPartitionsInRange(Carbon $startDate, Carbon $endDate, string $tablePrefix = 'alerts'): Collection
    {
        // Get registered partitions from the registry for this table prefix
        $registeredPartitions = $this->partitionManager->getPartitionsInRange($startDate, $endDate, $tablePrefix);
        
        // Filter to only include partitions that actually exist
        $existingPartitions = $registeredPartitions->filter(function ($partition) {
            return $this->partitionManager->partitionTableExists($partition->table_name);
        });
        
        if ($existingPartitions->count() < $registeredPartitions->count()) {
            $missing = $registeredPartitions->count() - $existingPartitions->count();
            Log::warning('Some registered partitions do not exist', [
                'table_prefix' => $tablePrefix,
                'registered' => $registeredPartitions->count(),
                'existing' => $existingPartitions->count(),
                'missing' => $missing
            ]);
        }
        
        return $existingPartitions;
    }
    
    /**
     * Build UNION ALL query across multiple partitions
     * 
     * Constructs a SQL query that unions results from multiple partition tables.
     * Applies filters and ordering consistently across all partitions.
     * 
     * Requirements: 6.3, 10.2, 10.3, 10.4
     * 
     * @param Collection $partitions Collection of PartitionRegistry models
     * @param array $filters Filter criteria
     * @param array $options Query options (order_by, order_direction, limit, offset)
     * @return string The complete SQL query
     */
    public function buildUnionQuery(
        Collection $partitions,
        array $filters = [],
        array $options = []
    ): string {
        if ($partitions->isEmpty()) {
            throw new Exception('Cannot build union query with no partitions');
        }
        
        // Build individual SELECT queries for each partition
        $selectQueries = [];
        
        foreach ($partitions as $partition) {
            $selectQueries[] = $this->buildPartitionSelect($partition->table_name, $filters);
        }
        
        // Combine with UNION ALL
        $unionQuery = implode("\nUNION ALL\n", $selectQueries);
        
        // Wrap in subquery for ordering and pagination
        $sql = "SELECT * FROM (\n{$unionQuery}\n) AS combined_results";
        
        // Add WHERE clause for date range filtering (additional safety)
        $whereConditions = $this->buildWhereConditions($filters);
        if (!empty($whereConditions)) {
            $sql .= "\nWHERE " . implode(' AND ', $whereConditions);
        }
        
        // Add ORDER BY clause
        $orderBy = $options['order_by'] ?? 'receivedtime';
        $orderDirection = strtoupper($options['order_direction'] ?? 'DESC');
        
        // Validate order direction
        if (!in_array($orderDirection, ['ASC', 'DESC'])) {
            $orderDirection = 'DESC';
        }
        
        // Quote column name to preserve case
        $sql .= "\nORDER BY \"{$orderBy}\" {$orderDirection}";
        
        // Add LIMIT and OFFSET for pagination
        if (isset($options['limit'])) {
            $limit = (int) $options['limit'];
            $sql .= "\nLIMIT {$limit}";
            
            if (isset($options['offset'])) {
                $offset = (int) $options['offset'];
                $sql .= " OFFSET {$offset}";
            }
        }
        
        return $sql;
    }
    
    /**
     * Build SELECT query for a single partition
     * 
     * @param string $tableName The partition table name
     * @param array $filters Filter criteria
     * @return string The SELECT query for this partition
     */
    private function buildPartitionSelect(string $tableName, array $filters): string
    {
        // Determine if this is a backalerts table to handle schema differences
        $isBackAlerts = strpos($tableName, 'backalerts_') === 0;
        
        if ($isBackAlerts) {
            // BackAlerts table - map columns to match alerts schema
            $sql = "SELECT 
                id,
                panelid,
                seqno,
                zone,
                alarm,
                createtime,
                receivedtime,
                comment,
                status,
                sendtoclient,
                closedby as \"closedBy\",
                closedtime,
                sendip,
                alerttype,
                location,
                priority,
                alertuserstatus as \"AlertUserStatus\",
                level::varchar as level,
                sip2,
                c_status,
                auto_alert::varchar as auto_alert,
                critical_alerts,
                NULL as \"Readstatus\",
                synced_at,
                sync_batch_id::bigint as sync_batch_id
            FROM {$tableName}";
        } else {
            // Alerts table - select all columns as-is
            $sql = "SELECT * FROM {$tableName}";
        }
        
        // Add WHERE conditions
        $whereConditions = $this->buildWhereConditions($filters);
        
        if (!empty($whereConditions)) {
            $sql .= "\nWHERE " . implode(' AND ', $whereConditions);
        }
        
        return $sql;
    }
    
    /**
     * Build WHERE conditions from filters
     * 
     * Constructs SQL WHERE conditions based on provided filters.
     * Handles proper escaping and quoting for PostgreSQL.
     * 
     * Requirements: 10.2, 10.3
     * 
     * @param array $filters Filter criteria
     * @return array Array of WHERE condition strings
     */
    private function buildWhereConditions(array $filters): array
    {
        $conditions = [];
        
        // Alert type filter (maps to 'alerttype' column)
        if (!empty($filters['alert_type'])) {
            $alertType = $this->escapeString($filters['alert_type']);
            $conditions[] = "\"alerttype\" = '{$alertType}'";
        }
        
        // Severity/Priority filter (maps to 'priority' column)
        if (!empty($filters['severity']) || !empty($filters['priority'])) {
            $severity = $this->escapeString($filters['severity'] ?? $filters['priority']);
            $conditions[] = "\"priority\" = '{$severity}'";
        }
        
        // Terminal ID filter (maps to 'panelid' column)
        if (!empty($filters['terminal_id']) || !empty($filters['panel_id'])) {
            $terminalId = $this->escapeString($filters['terminal_id'] ?? $filters['panel_id']);
            $conditions[] = "\"panelid\" = '{$terminalId}'";
        }
        
        // Panel IDs filter (array of panel IDs - IN clause)
        if (!empty($filters['panel_ids']) && is_array($filters['panel_ids'])) {
            $escapedIds = array_map(function($id) {
                return "'" . $this->escapeString($id) . "'";
            }, $filters['panel_ids']);
            $inClause = implode(', ', $escapedIds);
            $conditions[] = "\"panelid\" IN ({$inClause})";
        }
        
        // Status filter
        if (!empty($filters['status'])) {
            $status = $this->escapeString($filters['status']);
            $conditions[] = "\"status\" = '{$status}'";
        }
        
        // VM-specific status filter (array of statuses - IN clause)
        if (!empty($filters['vm_status']) && is_array($filters['vm_status'])) {
            $escapedStatuses = array_map(function($status) {
                return "'" . $this->escapeString($status) . "'";
            }, $filters['vm_status']);
            $inClause = implode(', ', $escapedStatuses);
            $conditions[] = "\"status\" IN ({$inClause})";
        }
        
        // VM-specific sendtoclient filter
        if (!empty($filters['vm_sendtoclient'])) {
            $sendtoclient = $this->escapeString($filters['vm_sendtoclient']);
            $conditions[] = "\"sendtoclient\" = '{$sendtoclient}'";
        }
        
        // Zone filter
        if (!empty($filters['zone'])) {
            $zone = $this->escapeString($filters['zone']);
            $conditions[] = "\"zone\" = '{$zone}'";
        }
        
        // Date range filters (for additional safety within partitions)
        if (!empty($filters['date_from'])) {
            $dateFrom = $this->escapeString($filters['date_from']);
            $conditions[] = "\"receivedtime\" >= '{$dateFrom}'";
        }
        
        if (!empty($filters['date_to'])) {
            $dateTo = $this->escapeString($filters['date_to']);
            $conditions[] = "\"receivedtime\" <= '{$dateTo}'";
        }
        
        return $conditions;
    }
    
    /**
     * Escape string for SQL query
     * 
     * Basic SQL escaping to prevent injection.
     * Note: This is a simple implementation. For production, consider using
     * parameterized queries or Laravel's query builder.
     * 
     * @param string $value The value to escape
     * @return string The escaped value
     */
    private function escapeString(string $value): string
    {
        // Replace single quotes with two single quotes (PostgreSQL escaping)
        return str_replace("'", "''", $value);
    }
    
    /**
     * Count total records across date range with filters
     * 
     * Returns the total count of records matching the criteria across all partitions.
     * Useful for pagination.
     * 
     * Requirements: 6.4, 10.4
     * 
     * @param Carbon $startDate Start date (inclusive)
     * @param Carbon $endDate End date (inclusive)
     * @param array $filters Optional filters
     * @param array $tablePrefixes Optional table prefixes to query (default: ['alerts'])
     * @return int Total record count
     */
    public function countDateRange(
        Carbon $startDate,
        Carbon $endDate,
        array $filters = [],
        array $tablePrefixes = ['alerts']
    ): int {
        try {
            // Get all partitions in the date range for all specified table prefixes
            $allPartitions = collect();
            
            foreach ($tablePrefixes as $prefix) {
                $partitions = $this->getPartitionsInRange($startDate, $endDate, $prefix);
                $allPartitions = $allPartitions->merge($partitions);
            }
            
            if ($allPartitions->isEmpty()) {
                return 0;
            }
            
            // Build count query
            $sql = $this->buildCountQuery($allPartitions, $filters);
            
            // Execute the query
            $result = DB::connection($this->connection)->select($sql);
            
            return (int) ($result[0]->total ?? 0);
            
        } catch (Exception $e) {
            Log::error('Failed to count date range', [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'table_prefixes' => $tablePrefixes,
                'filters' => $filters,
                'error' => $e->getMessage()
            ]);
            
            return 0;
        }
    }
    
    /**
     * Build COUNT query across multiple partitions
     * 
     * @param Collection $partitions Collection of PartitionRegistry models
     * @param array $filters Filter criteria
     * @return string The complete COUNT SQL query
     */
    private function buildCountQuery(Collection $partitions, array $filters = []): string
    {
        // Build individual COUNT queries for each partition
        $countQueries = [];
        
        foreach ($partitions as $partition) {
            $whereConditions = $this->buildWhereConditions($filters);
            $whereClause = !empty($whereConditions) 
                ? 'WHERE ' . implode(' AND ', $whereConditions)
                : '';
            
            $countQueries[] = "SELECT COUNT(*) as count FROM {$partition->table_name} {$whereClause}";
        }
        
        // Combine with UNION ALL and sum the counts
        $unionQuery = implode("\nUNION ALL\n", $countQueries);
        
        return "SELECT SUM(count) as total FROM (\n{$unionQuery}\n) AS partition_counts";
    }
    
    /**
     * Query with pagination support
     * 
     * Provides paginated results across partitions with consistent format.
     * 
     * Requirements: 10.3, 10.4
     * 
     * @param Carbon $startDate Start date (inclusive)
     * @param Carbon $endDate End date (inclusive)
     * @param array $filters Optional filters
     * @param int $perPage Items per page
     * @param int $page Page number (1-indexed)
     * @param array $tablePrefixes Optional table prefixes to query (default: ['alerts'])
     * @return array Paginated results with metadata
     */
    public function queryWithPagination(
        Carbon $startDate,
        Carbon $endDate,
        array $filters = [],
        int $perPage = 50,
        int $page = 1,
        array $tablePrefixes = ['alerts']
    ): array {
        // Calculate offset
        $offset = ($page - 1) * $perPage;
        
        // Get total count
        $total = $this->countDateRange($startDate, $endDate, $filters, $tablePrefixes);
        
        // Get paginated results
        $options = [
            'limit' => $perPage,
            'offset' => $offset,
            'order_by' => $filters['order_by'] ?? 'receivedtime',
            'order_direction' => $filters['order_direction'] ?? 'DESC',
        ];
        
        $results = $this->queryDateRange($startDate, $endDate, $filters, $options, $tablePrefixes);
        
        // Calculate pagination metadata
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $from = $total > 0 ? $offset + 1 : null;
        $to = $total > 0 ? min($offset + $perPage, $total) : null;
        
        return [
            'data' => $results->toArray(),
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $from,
                'to' => $to,
            ],
        ];
    }
    
    /**
     * Get aggregated statistics across partitions
     * 
     * Computes aggregate statistics (counts by type, priority, etc.) across
     * multiple partitions.
     * 
     * Requirements: 6.4, 10.2
     * 
     * @param Carbon $startDate Start date (inclusive)
     * @param Carbon $endDate End date (inclusive)
     * @param array $filters Optional filters
     * @return array Statistics data
     */
    public function getAggregatedStatistics(
        Carbon $startDate,
        Carbon $endDate,
        array $filters = []
    ): array {
        try {
            $partitions = $this->getPartitionsInRange($startDate, $endDate);
            
            if ($partitions->isEmpty()) {
                return [
                    'by_type' => [],
                    'by_priority' => [],
                    'by_status' => [],
                ];
            }
            
            return [
                'by_type' => $this->aggregateByColumn($partitions, 'alerttype', $filters),
                'by_priority' => $this->aggregateByColumn($partitions, 'priority', $filters),
                'by_status' => $this->aggregateByColumn($partitions, 'status', $filters),
            ];
            
        } catch (Exception $e) {
            Log::error('Failed to get aggregated statistics', [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'error' => $e->getMessage()
            ]);
            
            return [
                'by_type' => [],
                'by_priority' => [],
                'by_status' => [],
            ];
        }
    }
    
    /**
     * Aggregate counts by a specific column across partitions
     * 
     * @param Collection $partitions Collection of PartitionRegistry models
     * @param string $column Column name to group by
     * @param array $filters Optional filters
     * @return array Aggregated counts
     */
    private function aggregateByColumn(
        Collection $partitions,
        string $column,
        array $filters = []
    ): array {
        // Build individual GROUP BY queries for each partition
        $groupQueries = [];
        
        foreach ($partitions as $partition) {
            $whereConditions = $this->buildWhereConditions($filters);
            $whereClause = !empty($whereConditions) 
                ? 'WHERE ' . implode(' AND ', $whereConditions)
                : '';
            
            $groupQueries[] = "SELECT \"{$column}\" as value, COUNT(*) as count FROM {$partition->table_name} {$whereClause} GROUP BY \"{$column}\"";
        }
        
        // Combine with UNION ALL
        $unionQuery = implode("\nUNION ALL\n", $groupQueries);
        
        // Sum counts for each value
        $sql = "SELECT value, SUM(count) as total FROM (\n{$unionQuery}\n) AS grouped_results GROUP BY value ORDER BY total DESC";
        
        // Execute query
        $results = DB::connection($this->connection)->select($sql);
        
        // Convert to associative array
        $aggregated = [];
        foreach ($results as $result) {
            $key = $result->value ?? 'unknown';
            $aggregated[$key] = (int) $result->total;
        }
        
        return $aggregated;
    }
    
    /**
     * Check if any partitions exist in date range
     * 
     * @param Carbon $startDate Start date (inclusive)
     * @param Carbon $endDate End date (inclusive)
     * @return bool True if at least one partition exists
     */
    public function hasPartitionsInRange(Carbon $startDate, Carbon $endDate): bool
    {
        $partitions = $this->getPartitionsInRange($startDate, $endDate);
        return $partitions->isNotEmpty();
    }
    
    /**
     * Get list of missing partition dates in range
     * 
     * Identifies dates within the range that don't have partition tables.
     * Useful for monitoring and alerting.
     * 
     * @param Carbon $startDate Start date (inclusive)
     * @param Carbon $endDate End date (inclusive)
     * @return Collection Collection of Carbon dates
     */
    public function getMissingPartitionDates(Carbon $startDate, Carbon $endDate): Collection
    {
        $existingPartitions = $this->getPartitionsInRange($startDate, $endDate);
        $existingDates = $existingPartitions->pluck('partition_date')->map(function ($date) {
            return Carbon::parse($date)->toDateString();
        })->toArray();
        
        $missingDates = collect([]);
        $currentDate = $startDate->copy();
        
        while ($currentDate->lte($endDate)) {
            if (!in_array($currentDate->toDateString(), $existingDates)) {
                $missingDates->push($currentDate->copy());
            }
            $currentDate->addDay();
        }
        
        return $missingDates;
    }
}
