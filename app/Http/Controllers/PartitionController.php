<?php

namespace App\Http\Controllers;

use App\Services\DateGroupedSyncService;
use App\Services\PartitionManager;
use App\Services\PartitionQueryRouter;
use App\Models\PartitionRegistry;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * PartitionController handles partition management and querying.
 * 
 * Provides endpoints for:
 * - POST /api/sync/partitioned/trigger - Trigger date-partitioned sync
 * - GET /api/sync/partitions - List all partition tables
 * - GET /api/sync/partitions/{date} - Get partition info for specific date
 * - GET /api/reports/partitioned/query - Query across date partitions
 * 
 * Requirements: 5.1, 6.1, 9.4, 10.1
 */
class PartitionController extends Controller
{
    protected DateGroupedSyncService $syncService;
    protected PartitionManager $partitionManager;
    protected PartitionQueryRouter $queryRouter;

    public function __construct(
        DateGroupedSyncService $syncService,
        PartitionManager $partitionManager,
        PartitionQueryRouter $queryRouter
    ) {
        $this->syncService = $syncService;
        $this->partitionManager = $partitionManager;
        $this->queryRouter = $queryRouter;
    }

    /**
     * POST /api/sync/partitioned/trigger
     * 
     * Manually trigger a date-partitioned sync job.
     * Syncs alerts from MySQL to date-partitioned PostgreSQL tables.
     * 
     * Requirements: 5.1
     */
    public function triggerSync(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'batch_size' => 'nullable|integer|min:1000|max:50000',
                'start_from_id' => 'nullable|integer|min:0',
            ]);

            // Check if there are records to sync
            if (!$this->syncService->hasRecordsToSync()) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'message' => 'No records to sync',
                        'unsynced_count' => 0,
                    ],
                ]);
            }

            $batchSize = $validated['batch_size'] ?? null;
            $startFromId = $validated['start_from_id'] ?? null;

            // Execute the sync
            $result = $this->syncService->syncBatch($batchSize, $startFromId);

            Log::info('Partitioned sync triggered manually', [
                'batch_size' => $batchSize,
                'start_from_id' => $startFromId,
                'records_processed' => $result->totalRecordsProcessed,
                'date_groups' => count($result->dateGroupResults),
                'success' => $result->success,
                'triggered_by' => $request->user()?->id ?? 'api',
            ]);

            // Format date group results for response
            $dateGroupSummary = array_map(function ($dateGroupResult) {
                return [
                    'date' => $dateGroupResult->date->toDateString(),
                    'partition_table' => $dateGroupResult->partitionTable,
                    'records_inserted' => $dateGroupResult->recordsInserted,
                    'success' => $dateGroupResult->success,
                    'error_message' => $dateGroupResult->errorMessage,
                ];
            }, $result->dateGroupResults);

            return response()->json([
                'success' => $result->success,
                'data' => [
                    'message' => $result->success 
                        ? 'Sync completed successfully' 
                        : 'Sync completed with some failures',
                    'total_records_processed' => $result->totalRecordsProcessed,
                    'last_processed_id' => $result->lastProcessedId,
                    'date_groups' => $dateGroupSummary,
                    'duration_seconds' => round($result->duration, 2),
                    'unsynced_remaining' => $this->syncService->getUnsyncedCount(),
                ],
            ], $result->success ? 200 : 207); // 207 Multi-Status for partial success
        } catch (Exception $e) {
            Log::error('Failed to trigger partitioned sync', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SYNC_TRIGGER_ERROR',
                    'message' => 'Failed to trigger partitioned sync',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/sync/partitions
     * 
     * List all partition tables with metadata for both alerts and backalerts.
     * Returns partition names, dates, record counts, and sync status.
     * 
     * Requirements: 9.4
     */
    public function listPartitions(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'per_page' => 'nullable|integer|min:1|max:100',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
                'order_by' => 'nullable|string|in:partition_date,record_count,last_synced_at',
                'order_direction' => 'nullable|string|in:asc,desc',
                'table_type' => 'nullable|string|in:alerts,backalerts,combined',
            ]);

            $perPage = $validated['per_page'] ?? 50;
            $orderBy = $validated['order_by'] ?? 'partition_date';
            $orderDirection = $validated['order_direction'] ?? 'desc';
            $tableType = $validated['table_type'] ?? 'combined';

            // If requesting combined view, return combined statistics
            if ($tableType === 'combined') {
                return $this->getCombinedPartitionStats($validated);
            }

            // Build query for specific table type
            $query = PartitionRegistry::query();

            if ($tableType !== 'combined') {
                $query->where('table_type', $tableType);
            }

            // Apply date range filter if provided
            if (!empty($validated['date_from']) && !empty($validated['date_to'])) {
                $startDate = Carbon::parse($validated['date_from']);
                $endDate = Carbon::parse($validated['date_to']);
                $query->dateRange($startDate, $endDate);
            } elseif (!empty($validated['date_from'])) {
                $query->where('partition_date', '>=', $validated['date_from']);
            } elseif (!empty($validated['date_to'])) {
                $query->where('partition_date', '<=', $validated['date_to']);
            }

            // Apply ordering
            $query->orderBy($orderBy, $orderDirection);

            // Paginate results
            $partitions = $query->paginate($perPage);

            // Calculate summary statistics
            $totalRecords = PartitionRegistry::getTotalRecordCount();
            $totalPartitions = PartitionRegistry::count();
            $stalePartitions = PartitionRegistry::getStalePartitions(24)->count();
            
            // Get counts by type
            $alertsCount = PartitionRegistry::getTotalRecordCountByType('alerts');
            $backalertsCount = PartitionRegistry::getTotalRecordCountByType('backalerts');

            // Format partition data
            $partitionData = $partitions->map(function ($partition) {
                return [
                    'table_name' => $partition->table_name,
                    'table_type' => $partition->table_type ?? 'alerts',
                    'partition_date' => $partition->partition_date->toDateString(),
                    'record_count' => $partition->record_count,
                    'created_at' => $partition->created_at->toIso8601String(),
                    'last_synced_at' => $partition->last_synced_at 
                        ? $partition->last_synced_at->toIso8601String() 
                        : null,
                    'is_stale' => $partition->last_synced_at 
                        ? $partition->last_synced_at->lt(now()->subHours(24))
                        : true,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'partitions' => $partitionData,
                    'pagination' => [
                        'current_page' => $partitions->currentPage(),
                        'last_page' => $partitions->lastPage(),
                        'per_page' => $partitions->perPage(),
                        'total' => $partitions->total(),
                        'from' => $partitions->firstItem(),
                        'to' => $partitions->lastItem(),
                    ],
                    'summary' => [
                        'total_partitions' => $totalPartitions,
                        'total_records' => $totalRecords,
                        'alerts_records' => $alertsCount,
                        'backalerts_records' => $backalertsCount,
                        'stale_partitions' => $stalePartitions,
                        'table_type_filter' => $tableType,
                    ],
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Failed to list partitions', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'LIST_PARTITIONS_ERROR',
                    'message' => 'Failed to list partitions',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Get combined partition statistics showing both alerts and backalerts for each date
     */
    private function getCombinedPartitionStats(array $validated): JsonResponse
    {
        try {
            $perPage = $validated['per_page'] ?? 50;
            
            // Get all combined stats
            $combinedStats = PartitionRegistry::getAllCombinedStats();
            
            // Apply date filtering if provided
            if (!empty($validated['date_from']) || !empty($validated['date_to'])) {
                $combinedStats = $combinedStats->filter(function ($stat) use ($validated) {
                    $date = Carbon::parse($stat['date']);
                    
                    if (!empty($validated['date_from']) && $date->lt(Carbon::parse($validated['date_from']))) {
                        return false;
                    }
                    
                    if (!empty($validated['date_to']) && $date->gt(Carbon::parse($validated['date_to']))) {
                        return false;
                    }
                    
                    return true;
                });
            }
            
            // Convert to paginated format
            $total = $combinedStats->count();
            $currentPage = 1;
            $offset = ($currentPage - 1) * $perPage;
            $paginatedStats = $combinedStats->slice($offset, $perPage)->values();
            
            // Calculate summary statistics
            $totalAlerts = PartitionRegistry::getTotalRecordCountByType('alerts');
            $totalBackalerts = PartitionRegistry::getTotalRecordCountByType('backalerts');
            $totalRecords = $totalAlerts + $totalBackalerts;
            $totalPartitions = PartitionRegistry::count();
            $stalePartitions = PartitionRegistry::getStalePartitions(24)->count();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'combined_partitions' => $paginatedStats,
                    'pagination' => [
                        'current_page' => $currentPage,
                        'last_page' => ceil($total / $perPage),
                        'per_page' => $perPage,
                        'total' => $total,
                        'from' => $offset + 1,
                        'to' => min($offset + $perPage, $total),
                    ],
                    'summary' => [
                        'total_partitions' => $totalPartitions,
                        'total_records' => $totalRecords,
                        'alerts_records' => $totalAlerts,
                        'backalerts_records' => $totalBackalerts,
                        'stale_partitions' => $stalePartitions,
                        'table_type_filter' => 'combined',
                    ],
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Failed to get combined partition stats', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'COMBINED_STATS_ERROR',
                    'message' => 'Failed to get combined partition statistics',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/sync/partitions/{date}
     * 
     * Get detailed info for a specific partition by date.
     * Returns partition metadata, record count, and sync history.
     * 
     * Requirements: 9.4
     */
    public function getPartitionInfo(string $date): JsonResponse
    {
        try {
            // Parse and validate date
            try {
                $partitionDate = Carbon::parse($date);
            } catch (Exception $e) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVALID_DATE',
                        'message' => 'Invalid date format',
                        'details' => 'Date must be in YYYY-MM-DD format',
                    ],
                ], 400);
            }

            // Get partition info from registry
            $partition = PartitionRegistry::getPartitionByDate($partitionDate);

            if (!$partition) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'PARTITION_NOT_FOUND',
                        'message' => 'Partition not found for the specified date',
                        'details' => "No partition exists for date: {$partitionDate->toDateString()}",
                    ],
                ], 404);
            }

            // Check if partition table actually exists
            $tableExists = $this->partitionManager->partitionTableExists($partition->table_name);

            // Get actual record count from the partition table if it exists
            $actualRecordCount = null;
            if ($tableExists) {
                try {
                    $actualRecordCount = $this->partitionManager->getPartitionRecordCount($partition->table_name);
                } catch (Exception $e) {
                    Log::warning('Failed to get actual record count', [
                        'partition' => $partition->table_name,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Calculate time since last sync
            $hoursSinceSync = null;
            if ($partition->last_synced_at) {
                $hoursSinceSync = round($partition->last_synced_at->diffInHours(now()), 1);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'table_name' => $partition->table_name,
                    'partition_date' => $partition->partition_date->toDateString(),
                    'record_count' => $partition->record_count,
                    'actual_record_count' => $actualRecordCount,
                    'count_mismatch' => $actualRecordCount !== null && $actualRecordCount !== $partition->record_count,
                    'created_at' => $partition->created_at->toIso8601String(),
                    'last_synced_at' => $partition->last_synced_at 
                        ? $partition->last_synced_at->toIso8601String() 
                        : null,
                    'hours_since_sync' => $hoursSinceSync,
                    'is_stale' => $partition->last_synced_at 
                        ? $partition->last_synced_at->lt(now()->subHours(24))
                        : true,
                    'table_exists' => $tableExists,
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Failed to get partition info', [
                'date' => $date,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'PARTITION_INFO_ERROR',
                    'message' => 'Failed to get partition info',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/reports/partitioned/query
     * 
     * Query across date partitions with filters.
     * Supports date range, alert_type, severity, and other filters.
     * Returns paginated results from multiple partition tables.
     * 
     * Requirements: 6.1, 10.1
     */
    public function queryPartitions(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'date_from' => 'required|date',
                'date_to' => 'required|date|after_or_equal:date_from',
                'alert_type' => 'nullable|string',
                'severity' => 'nullable|string',
                'priority' => 'nullable|string',
                'terminal_id' => 'nullable|string',
                'panel_id' => 'nullable|string',
                'status' => 'nullable|string',
                'zone' => 'nullable|string',
                'per_page' => 'nullable|integer|min:1|max:1000',
                'page' => 'nullable|integer|min:1',
                'order_by' => 'nullable|string|in:receivedtime,alerttype,priority,panelid,status',
                'order_direction' => 'nullable|string|in:asc,desc',
            ]);

            $startDate = Carbon::parse($validated['date_from']);
            $endDate = Carbon::parse($validated['date_to']);

            // Check if date range is too large (more than 90 days)
            if ($startDate->diffInDays($endDate) > 90) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'DATE_RANGE_TOO_LARGE',
                        'message' => 'Date range cannot exceed 90 days',
                        'details' => 'Please narrow your date range to 90 days or less',
                    ],
                ], 400);
            }

            // Check if any partitions exist in the date range
            if (!$this->queryRouter->hasPartitionsInRange($startDate, $endDate)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'alerts' => [],
                        'pagination' => [
                            'current_page' => 1,
                            'last_page' => 1,
                            'per_page' => $validated['per_page'] ?? 50,
                            'total' => 0,
                            'from' => null,
                            'to' => null,
                        ],
                        'filters_applied' => $this->buildFiltersApplied($validated),
                        'date_range' => [
                            'from' => $startDate->toDateString(),
                            'to' => $endDate->toDateString(),
                        ],
                        'message' => 'No partitions found in the specified date range',
                    ],
                ]);
            }

            // Build filters array
            $filters = array_filter([
                'alert_type' => $validated['alert_type'] ?? null,
                'severity' => $validated['severity'] ?? $validated['priority'] ?? null,
                'terminal_id' => $validated['terminal_id'] ?? $validated['panel_id'] ?? null,
                'status' => $validated['status'] ?? null,
                'zone' => $validated['zone'] ?? null,
                'order_by' => $validated['order_by'] ?? 'receivedtime',
                'order_direction' => $validated['order_direction'] ?? 'desc',
            ]);

            // Get pagination parameters
            $perPage = $validated['per_page'] ?? 50;
            $page = $validated['page'] ?? 1;

            // Query with pagination
            $result = $this->queryRouter->queryWithPagination(
                $startDate,
                $endDate,
                $filters,
                $perPage,
                $page
            );

            // Get missing partition dates for informational purposes
            $missingDates = $this->queryRouter->getMissingPartitionDates($startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => [
                    'alerts' => $result['data'],
                    'pagination' => $result['pagination'],
                    'filters_applied' => $this->buildFiltersApplied($validated),
                    'date_range' => [
                        'from' => $startDate->toDateString(),
                        'to' => $endDate->toDateString(),
                        'days' => $startDate->diffInDays($endDate) + 1,
                    ],
                    'missing_dates' => $missingDates->map(fn($date) => $date->toDateString())->toArray(),
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Failed to query partitions', [
                'filters' => $request->all(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'QUERY_ERROR',
                    'message' => 'Failed to query partitions',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Build a summary of applied filters for the response
     * 
     * @param array $validated Validated request data
     * @return array Filters summary
     */
    private function buildFiltersApplied(array $validated): array
    {
        $filters = [];

        if (!empty($validated['alert_type'])) {
            $filters['alert_type'] = $validated['alert_type'];
        }

        if (!empty($validated['severity']) || !empty($validated['priority'])) {
            $filters['severity'] = $validated['severity'] ?? $validated['priority'];
        }

        if (!empty($validated['terminal_id']) || !empty($validated['panel_id'])) {
            $filters['terminal_id'] = $validated['terminal_id'] ?? $validated['panel_id'];
        }

        if (!empty($validated['status'])) {
            $filters['status'] = $validated['status'];
        }

        if (!empty($validated['zone'])) {
            $filters['zone'] = $validated['zone'];
        }

        return $filters;
    }
}
