<?php

namespace App\Services;

use App\Models\SyncedAlert;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ReportService handles report generation from PostgreSQL.
 * 
 * This service queries PostgreSQL exclusively for all report data,
 * ensuring reporting queries don't impact the production MySQL database.
 * 
 * Now supports both single-table and date-partitioned queries through
 * the PartitionQueryRouter for improved performance on date-range queries.
 * 
 * ⚠️ READ from PostgreSQL only - never MySQL alerts
 * 
 * Requirements: 5.1, 5.2, 5.3, 10.1, 10.2, 10.3, 10.5
 */
class ReportService
{
    /**
     * PartitionQueryRouter for cross-partition queries
     */
    private ?PartitionQueryRouter $partitionRouter;
    
    /**
     * Flag to enable/disable partition routing
     */
    private bool $usePartitionRouter;
    
    /**
     * Create a new ReportService instance
     * 
     * @param PartitionQueryRouter|null $partitionRouter Optional PartitionQueryRouter instance
     * @param bool $usePartitionRouter Whether to use partition router (default: true)
     */
    public function __construct(
        ?PartitionQueryRouter $partitionRouter = null,
        bool $usePartitionRouter = true
    ) {
        $this->partitionRouter = $partitionRouter ?? new PartitionQueryRouter();
        $this->usePartitionRouter = $usePartitionRouter;
    }
    /**
     * Generate a daily report for a specific date
     * 
     * @param Carbon $date The date to generate the report for
     * @return array Report data
     */
    public function generateDailyReport(Carbon $date): array
    {
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        return $this->generateReport($startOfDay, $endOfDay, [
            'report_type' => 'daily',
            'report_date' => $date->toDateString(),
        ]);
    }

    /**
     * Generate a summary report with optional filters
     * 
     * @param Carbon|null $startDate Start date filter
     * @param Carbon|null $endDate End date filter
     * @param string|null $alertType Alert type filter
     * @param string|null $priority Priority/severity filter
     * @param string|null $panelId Panel ID filter
     * @return array Report data
     */
    public function generateSummaryReport(
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        ?string $alertType = null,
        ?string $priority = null,
        ?string $panelId = null
    ): array {
        $filters = array_filter([
            'alert_type' => $alertType,
            'priority' => $priority,
            'panel_id' => $panelId,
        ]);

        return $this->generateReport($startDate, $endDate, [
            'report_type' => 'summary',
            'filters' => $filters,
        ]);
    }

    /**
     * Generate a report with the given date range and metadata
     * 
     * Uses PartitionQueryRouter when date range is specified for improved performance.
     * Falls back to single-table queries when partition router is disabled.
     * 
     * Requirements: 10.1, 10.2, 10.3, 10.5
     * 
     * @param Carbon|null $startDate Start date
     * @param Carbon|null $endDate End date
     * @param array $metadata Additional metadata
     * @return array Report data
     */
    protected function generateReport(?Carbon $startDate, ?Carbon $endDate, array $metadata = []): array
    {
        $filters = $metadata['filters'] ?? [];
        
        // Determine if we should use partition router
        $usePartitions = $this->shouldUsePartitionRouter($startDate, $endDate);
        
        if ($usePartitions) {
            // Use partition router for date-range queries
            $totalAlerts = $this->countAlertsViaRouter($startDate, $endDate, $filters);
            $statistics = $this->generateStatisticsViaRouter($startDate, $endDate, $filters);
        } else {
            // Fall back to single-table query
            $query = $this->buildSingleTableQuery($startDate, $endDate, $filters);
            $totalAlerts = $query->count();
            $statistics = $this->generateStatistics($startDate, $endDate, $filters);
        }

        return [
            'metadata' => array_merge($metadata, [
                'generated_at' => now()->toIso8601String(),
                'date_range' => [
                    'start' => $startDate?->toIso8601String(),
                    'end' => $endDate?->toIso8601String(),
                ],
                'used_partitions' => $usePartitions,
            ]),
            'summary' => [
                'total_alerts' => $totalAlerts,
            ],
            'statistics' => $statistics,
        ];
    }
    
    /**
     * Determine if partition router should be used
     * 
     * Requirements: 10.1, 10.5
     * 
     * @param Carbon|null $startDate Start date
     * @param Carbon|null $endDate End date
     * @return bool True if partition router should be used
     */
    protected function shouldUsePartitionRouter(?Carbon $startDate, ?Carbon $endDate): bool
    {
        // Don't use if disabled
        if (!$this->usePartitionRouter) {
            return false;
        }
        
        // Don't use if no date range specified
        if (!$startDate || !$endDate) {
            return false;
        }
        
        // Check if partitions exist in the date range
        try {
            return $this->partitionRouter->hasPartitionsInRange($startDate, $endDate);
        } catch (\Exception $e) {
            Log::warning('Failed to check for partitions, falling back to single table', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Build single-table query with filters
     * 
     * @param Carbon|null $startDate Start date
     * @param Carbon|null $endDate End date
     * @param array $filters Additional filters
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function buildSingleTableQuery(?Carbon $startDate, ?Carbon $endDate, array $filters = [])
    {
        $query = SyncedAlert::query();

        // Apply date range filter
        if ($startDate && $endDate) {
            $query->whereBetween('createtime', [$startDate, $endDate]);
        } elseif ($startDate) {
            $query->where('createtime', '>=', $startDate);
        } elseif ($endDate) {
            $query->where('createtime', '<=', $endDate);
        }

        // Apply additional filters
        if (!empty($filters['alert_type'])) {
            $query->where('alerttype', $filters['alert_type']);
        }
        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        if (!empty($filters['panel_id'])) {
            $query->where('panelid', $filters['panel_id']);
        }
        
        return $query;
    }
    
    /**
     * Count alerts via partition router
     * 
     * Requirements: 10.2, 10.3
     * 
     * @param Carbon $startDate Start date
     * @param Carbon $endDate End date
     * @param array $filters Additional filters
     * @return int Total alert count
     */
    protected function countAlertsViaRouter(Carbon $startDate, Carbon $endDate, array $filters = []): int
    {
        try {
            return $this->partitionRouter->countDateRange($startDate, $endDate, $filters);
        } catch (\Exception $e) {
            Log::error('Failed to count via partition router', [
                'error' => $e->getMessage(),
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString()
            ]);
            return 0;
        }
    }
    
    /**
     * Generate statistics via partition router
     * 
     * Requirements: 10.2, 10.3
     * 
     * @param Carbon $startDate Start date
     * @param Carbon $endDate End date
     * @param array $filters Additional filters
     * @return array Statistics data
     */
    protected function generateStatisticsViaRouter(Carbon $startDate, Carbon $endDate, array $filters = []): array
    {
        try {
            $aggregated = $this->partitionRouter->getAggregatedStatistics($startDate, $endDate, $filters);
            
            // Get trends via partition router
            $trends = $this->getTrendsViaRouter($startDate, $endDate, $filters);
            
            // Get top panels via partition router
            $byPanel = $this->getTopPanelsViaRouter($startDate, $endDate, $filters, 10);
            
            return [
                'by_type' => $aggregated['by_type'] ?? [],
                'by_priority' => $aggregated['by_priority'] ?? [],
                'by_status' => $aggregated['by_status'] ?? [],
                'by_panel' => $byPanel,
                'trends' => $trends,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to generate statistics via partition router', [
                'error' => $e->getMessage(),
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString()
            ]);
            
            // Fall back to empty statistics
            return [
                'by_type' => [],
                'by_priority' => [],
                'by_status' => [],
                'by_panel' => [],
                'trends' => ['daily_counts' => [], 'period' => ['start' => $startDate->toDateString(), 'end' => $endDate->toDateString()]],
            ];
        }
    }
    
    /**
     * Get trends via partition router
     * 
     * @param Carbon $startDate Start date
     * @param Carbon $endDate End date
     * @param array $filters Additional filters
     * @return array Trend data
     */
    protected function getTrendsViaRouter(Carbon $startDate, Carbon $endDate, array $filters = []): array
    {
        try {
            // Query all records in date range
            $results = $this->partitionRouter->queryDateRange($startDate, $endDate, $filters);
            
            // Group by date
            $dailyCounts = [];
            foreach ($results as $record) {
                $date = Carbon::parse($record->receivedtime ?? $record->createtime)->toDateString();
                $dailyCounts[$date] = ($dailyCounts[$date] ?? 0) + 1;
            }
            
            // Sort by date
            ksort($dailyCounts);
            
            return [
                'daily_counts' => $dailyCounts,
                'period' => [
                    'start' => $startDate->toDateString(),
                    'end' => $endDate->toDateString(),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get trends via partition router', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'daily_counts' => [],
                'period' => [
                    'start' => $startDate->toDateString(),
                    'end' => $endDate->toDateString(),
                ],
            ];
        }
    }
    
    /**
     * Get top panels via partition router
     * 
     * @param Carbon $startDate Start date
     * @param Carbon $endDate End date
     * @param array $filters Additional filters
     * @param int $limit Number of top panels to return
     * @return array Top panels with counts
     */
    protected function getTopPanelsViaRouter(Carbon $startDate, Carbon $endDate, array $filters = [], int $limit = 10): array
    {
        try {
            // Query all records in date range
            $results = $this->partitionRouter->queryDateRange($startDate, $endDate, $filters);
            
            // Count by panel
            $panelCounts = [];
            foreach ($results as $record) {
                $panelId = $record->panelid ?? 'unknown';
                $panelCounts[$panelId] = ($panelCounts[$panelId] ?? 0) + 1;
            }
            
            // Sort by count descending and take top N
            arsort($panelCounts);
            return array_slice($panelCounts, 0, $limit, true);
            
        } catch (\Exception $e) {
            Log::error('Failed to get top panels via partition router', [
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }

    /**
     * Generate summary statistics
     * 
     * Requirements: 5.3
     * 
     * @param Carbon|null $startDate Start date
     * @param Carbon|null $endDate End date
     * @param array $filters Additional filters
     * @return array Statistics data
     */
    public function generateStatistics(?Carbon $startDate = null, ?Carbon $endDate = null, array $filters = []): array
    {
        // Build base query with filters
        $baseQuery = function () use ($startDate, $endDate, $filters) {
            $query = SyncedAlert::query();
            
            if ($startDate && $endDate) {
                $query->whereBetween('createtime', [$startDate, $endDate]);
            } elseif ($startDate) {
                $query->where('createtime', '>=', $startDate);
            } elseif ($endDate) {
                $query->where('createtime', '<=', $endDate);
            }

            if (!empty($filters['alert_type'])) {
                $query->where('alerttype', $filters['alert_type']);
            }
            if (!empty($filters['priority'])) {
                $query->where('priority', $filters['priority']);
            }
            if (!empty($filters['panel_id'])) {
                $query->where('panelid', $filters['panel_id']);
            }

            return $query;
        };

        return [
            'by_type' => $this->getCountsByType($baseQuery()),
            'by_priority' => $this->getCountsByPriority($baseQuery()),
            'by_status' => $this->getCountsByStatus($baseQuery()),
            'by_panel' => $this->getCountsByPanel($baseQuery(), 10), // Top 10 panels
            'trends' => $this->getTrends($startDate, $endDate, $filters),
        ];
    }

    /**
     * Get alert counts grouped by type
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return array
     */
    protected function getCountsByType($query): array
    {
        return $query->clone()
            ->select('alerttype', DB::raw('COUNT(*) as count'))
            ->groupBy('alerttype')
            ->orderByDesc('count')
            ->get()
            ->mapWithKeys(fn($item) => [$item->alerttype ?? 'unknown' => $item->count])
            ->toArray();
    }

    /**
     * Get alert counts grouped by priority/severity
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return array
     */
    protected function getCountsByPriority($query): array
    {
        return $query->clone()
            ->select('priority', DB::raw('COUNT(*) as count'))
            ->groupBy('priority')
            ->orderByDesc('count')
            ->get()
            ->mapWithKeys(fn($item) => [$item->priority ?? 'unknown' => $item->count])
            ->toArray();
    }

    /**
     * Get alert counts grouped by status
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return array
     */
    protected function getCountsByStatus($query): array
    {
        return $query->clone()
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->orderByDesc('count')
            ->get()
            ->mapWithKeys(fn($item) => [$item->status ?? 'unknown' => $item->count])
            ->toArray();
    }

    /**
     * Get alert counts grouped by panel (top N)
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $limit Number of top panels to return
     * @return array
     */
    protected function getCountsByPanel($query, int $limit = 10): array
    {
        return $query->clone()
            ->select('panelid', DB::raw('COUNT(*) as count'))
            ->groupBy('panelid')
            ->orderByDesc('count')
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn($item) => [$item->panelid ?? 'unknown' => $item->count])
            ->toArray();
    }

    /**
     * Get trend data (daily counts over the date range)
     * 
     * @param Carbon|null $startDate Start date
     * @param Carbon|null $endDate End date
     * @param array $filters Additional filters
     * @return array
     */
    protected function getTrends(?Carbon $startDate, ?Carbon $endDate, array $filters = []): array
    {
        // Default to last 30 days if no date range specified
        if (!$startDate) {
            $startDate = now()->subDays(30)->startOfDay();
        }
        if (!$endDate) {
            $endDate = now()->endOfDay();
        }

        $query = SyncedAlert::query()
            ->whereBetween('createtime', [$startDate, $endDate]);

        if (!empty($filters['alert_type'])) {
            $query->where('alerttype', $filters['alert_type']);
        }
        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        if (!empty($filters['panel_id'])) {
            $query->where('panelid', $filters['panel_id']);
        }

        // Group by date
        $dailyCounts = $query
            ->select(DB::raw('DATE(createtime) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy(DB::raw('DATE(createtime)'))
            ->orderBy('date')
            ->get()
            ->mapWithKeys(fn($item) => [$item->date => $item->count])
            ->toArray();

        return [
            'daily_counts' => $dailyCounts,
            'period' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
        ];
    }

    /**
     * Get filtered alerts with pagination
     * 
     * Uses PartitionQueryRouter when date range is specified for improved performance.
     * Maintains backward compatibility with single-table queries.
     * 
     * Requirements: 5.2, 10.1, 10.2, 10.3, 10.5
     * 
     * @param array $filters Filter criteria
     * @param int $perPage Items per page
     * @param int $page Page number
     * @return array
     */
    public function getFilteredAlerts(array $filters = [], int $perPage = 50, int $page = 1): array
    {
        // Parse date filters
        $startDate = !empty($filters['date_from']) ? Carbon::parse($filters['date_from'])->startOfDay() : null;
        $endDate = !empty($filters['date_to']) ? Carbon::parse($filters['date_to'])->endOfDay() : null;
        
        // Determine if we should use partition router
        $usePartitions = $this->shouldUsePartitionRouter($startDate, $endDate);
        
        if ($usePartitions) {
            // Use partition router for date-range queries
            return $this->getFilteredAlertsViaRouter($startDate, $endDate, $filters, $perPage, $page);
        } else {
            // Fall back to single-table query
            return $this->getFilteredAlertsSingleTable($filters, $perPage, $page);
        }
    }
    
    /**
     * Get filtered alerts via partition router
     * 
     * Requirements: 10.2, 10.3, 10.5
     * 
     * @param Carbon $startDate Start date
     * @param Carbon $endDate End date
     * @param array $filters Filter criteria
     * @param int $perPage Items per page
     * @param int $page Page number
     * @return array
     */
    protected function getFilteredAlertsViaRouter(
        Carbon $startDate,
        Carbon $endDate,
        array $filters,
        int $perPage,
        int $page
    ): array {
        try {
            // Build filter array for partition router
            $routerFilters = [];
            
            if (!empty($filters['alert_type'])) {
                $routerFilters['alert_type'] = $filters['alert_type'];
            }
            if (!empty($filters['priority'])) {
                $routerFilters['priority'] = $filters['priority'];
            }
            if (!empty($filters['panel_id'])) {
                $routerFilters['panel_id'] = $filters['panel_id'];
            }
            if (!empty($filters['status'])) {
                $routerFilters['status'] = $filters['status'];
            }
            
            // Query via partition router with pagination
            $result = $this->partitionRouter->queryWithPagination(
                $startDate,
                $endDate,
                $routerFilters,
                $perPage,
                $page
            );
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('Failed to get filtered alerts via partition router', [
                'error' => $e->getMessage(),
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString()
            ]);
            
            // Fall back to single-table query
            return $this->getFilteredAlertsSingleTable($filters, $perPage, $page);
        }
    }
    
    /**
     * Get filtered alerts from single table (backward compatibility)
     * 
     * @param array $filters Filter criteria
     * @param int $perPage Items per page
     * @param int $page Page number
     * @return array
     */
    protected function getFilteredAlertsSingleTable(array $filters = [], int $perPage = 50, int $page = 1): array
    {
        $query = SyncedAlert::query();

        // Apply date range filter
        if (!empty($filters['date_from'])) {
            $query->where('createtime', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (!empty($filters['date_to'])) {
            $query->where('createtime', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        // Apply type filter
        if (!empty($filters['alert_type'])) {
            $query->where('alerttype', $filters['alert_type']);
        }

        // Apply priority/severity filter
        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        // Apply panel filter
        if (!empty($filters['panel_id'])) {
            $query->where('panelid', $filters['panel_id']);
        }

        // Apply status filter
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Order by createtime descending (most recent first)
        $query->orderByDesc('createtime');

        // Get paginated results
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * Get available filter options (distinct values)
     * 
     * @return array
     */
    public function getFilterOptions(): array
    {
        return [
            'alert_types' => SyncedAlert::distinct()
                ->whereNotNull('alerttype')
                ->pluck('alerttype')
                ->sort()
                ->values()
                ->toArray(),
            'priorities' => SyncedAlert::distinct()
                ->whereNotNull('priority')
                ->pluck('priority')
                ->sort()
                ->values()
                ->toArray(),
            'statuses' => SyncedAlert::distinct()
                ->whereNotNull('status')
                ->pluck('status')
                ->sort()
                ->values()
                ->toArray(),
        ];
    }

    /**
     * Export report data to CSV format
     * 
     * Uses PartitionQueryRouter when date range is specified for improved performance.
     * Maintains backward compatibility with single-table queries.
     * 
     * Requirements: 5.4, 10.1, 10.5
     * 
     * @param array $filters Filter criteria
     * @param int $limit Maximum records to export
     * @return string CSV content
     */
    public function exportToCsv(array $filters = [], int $limit = 10000): string
    {
        // Parse date filters
        $startDate = !empty($filters['date_from']) ? Carbon::parse($filters['date_from'])->startOfDay() : null;
        $endDate = !empty($filters['date_to']) ? Carbon::parse($filters['date_to'])->endOfDay() : null;
        
        // Determine if we should use partition router
        $usePartitions = $this->shouldUsePartitionRouter($startDate, $endDate);
        
        if ($usePartitions) {
            // Use partition router for date-range queries
            $alerts = $this->getAlertsForExportViaRouter($startDate, $endDate, $filters, $limit);
        } else {
            // Fall back to single-table query
            $alerts = $this->getAlertsForExportSingleTable($filters, $limit);
        }

        // Build CSV
        $output = fopen('php://temp', 'r+');
        
        // Header row
        fputcsv($output, [
            'ID',
            'Panel ID',
            'Alert Type',
            'Priority',
            'Status',
            'Message',
            'Location',
            'Created At',
            'Received At',
            'Closed At',
            'Closed By',
        ]);

        // Data rows
        foreach ($alerts as $alert) {
            // Handle both object and array formats
            $alertData = is_object($alert) ? (array) $alert : $alert;
            
            fputcsv($output, [
                $alertData['id'] ?? '',
                $alertData['panelid'] ?? '',
                $alertData['alerttype'] ?? '',
                $alertData['priority'] ?? '',
                $alertData['status'] ?? '',
                $alertData['alarm'] ?? '',
                $alertData['location'] ?? '',
                $alertData['createtime'] ?? '',
                $alertData['receivedtime'] ?? '',
                $alertData['closedtime'] ?? '',
                $alertData['closedBy'] ?? '',
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
    
    /**
     * Get alerts for export via partition router
     * 
     * @param Carbon $startDate Start date
     * @param Carbon $endDate End date
     * @param array $filters Filter criteria
     * @param int $limit Maximum records
     * @return Collection
     */
    protected function getAlertsForExportViaRouter(
        Carbon $startDate,
        Carbon $endDate,
        array $filters,
        int $limit
    ): Collection {
        try {
            // Build filter array for partition router
            $routerFilters = [];
            
            if (!empty($filters['alert_type'])) {
                $routerFilters['alert_type'] = $filters['alert_type'];
            }
            if (!empty($filters['priority'])) {
                $routerFilters['priority'] = $filters['priority'];
            }
            if (!empty($filters['panel_id'])) {
                $routerFilters['panel_id'] = $filters['panel_id'];
            }
            
            // Query via partition router with limit
            $options = [
                'limit' => $limit,
                'order_by' => 'receivedtime',
                'order_direction' => 'DESC',
            ];
            
            return $this->partitionRouter->queryDateRange($startDate, $endDate, $routerFilters, $options);
            
        } catch (\Exception $e) {
            Log::error('Failed to get alerts for export via partition router', [
                'error' => $e->getMessage()
            ]);
            
            // Fall back to single-table query
            return $this->getAlertsForExportSingleTable($filters, $limit);
        }
    }
    
    /**
     * Get alerts for export from single table
     * 
     * @param array $filters Filter criteria
     * @param int $limit Maximum records
     * @return Collection
     */
    protected function getAlertsForExportSingleTable(array $filters, int $limit): Collection
    {
        $query = SyncedAlert::query();

        // Apply filters
        if (!empty($filters['date_from'])) {
            $query->where('createtime', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (!empty($filters['date_to'])) {
            $query->where('createtime', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }
        if (!empty($filters['alert_type'])) {
            $query->where('alerttype', $filters['alert_type']);
        }
        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        if (!empty($filters['panel_id'])) {
            $query->where('panelid', $filters['panel_id']);
        }

        // Order and limit
        return $query->orderByDesc('createtime')->limit($limit)->get();
    }

    /**
     * Export report data to PDF format
     * 
     * Requirements: 5.4
     * 
     * @param array $filters Filter criteria
     * @param int $limit Maximum records to include
     * @return array PDF data (to be rendered by controller)
     */
    public function exportToPdf(array $filters = [], int $limit = 1000): array
    {
        // Generate summary report
        $startDate = !empty($filters['date_from']) ? Carbon::parse($filters['date_from']) : null;
        $endDate = !empty($filters['date_to']) ? Carbon::parse($filters['date_to']) : null;

        $report = $this->generateSummaryReport(
            $startDate,
            $endDate,
            $filters['alert_type'] ?? null,
            $filters['priority'] ?? null,
            $filters['panel_id'] ?? null
        );

        // Get sample alerts for the report
        $alertsData = $this->getFilteredAlerts($filters, $limit, 1);

        return [
            'title' => 'Alert Report',
            'generated_at' => now()->toDateTimeString(),
            'filters' => $filters,
            'summary' => $report['summary'],
            'statistics' => $report['statistics'],
            'alerts' => $alertsData['data'],
            'total_records' => $alertsData['pagination']['total'],
        ];
    }
}
