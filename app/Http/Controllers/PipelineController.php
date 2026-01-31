<?php

namespace App\Http\Controllers;

use App\Jobs\SyncJob;
use App\Jobs\CleanupJob;
use App\Models\Alert;
use App\Models\SyncBatch;
use App\Models\SyncedAlert;
use App\Services\SyncService;
use App\Services\SyncLogService;
use App\Services\CleanupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PipelineController handles monitoring and control of the data pipeline.
 * 
 * Provides endpoints for:
 * - GET /api/pipeline/status - Current pipeline status
 * - GET /api/pipeline/sync-logs - Sync history with pagination
 * - POST /api/pipeline/sync/trigger - Manual sync trigger
 * - POST /api/pipeline/cleanup/trigger - Manual cleanup trigger (requires admin + confirmation)
 * 
 * Requirements: 2.2, 2.3
 */
class PipelineController extends Controller
{
    protected SyncLogService $syncLogService;
    protected SyncService $syncService;
    protected CleanupService $cleanupService;

    public function __construct(
        SyncLogService $syncLogService,
        SyncService $syncService,
        CleanupService $cleanupService
    ) {
        $this->syncLogService = $syncLogService;
        $this->syncService = $syncService;
        $this->cleanupService = $cleanupService;
    }

    /**
     * GET /api/pipeline/status
     * 
     * Returns current pipeline status including:
     * - Sync job status (idle/running/failed)
     * - Cleanup job status
     * - Records synced, pending, last sync time
     * - Database connection health
     * 
     * Requirements: 2.2, 2.3
     */
    public function status(): JsonResponse
    {
        try {
            // Get job statuses from cache
            $syncStatus = SyncJob::getStatus();
            $cleanupStatus = CleanupJob::getStatus();

            // Get record counts
            $unsyncedCount = $this->syncService->getUnsyncedCount();
            $syncedCount = $this->syncService->getSyncedCount();
            $postgresCount = SyncedAlert::count();

            // Get last successful sync info
            $lastSync = $this->syncLogService->getLastSuccessfulSync();

            // Get batch statistics
            $batchStats = $this->getBatchStatistics();

            // Check for recent failures
            $consecutiveFailures = $this->syncLogService->getConsecutiveFailureCount();
            $hasRecentFailures = $consecutiveFailures >= 3;

            // Check database connections
            $dbHealth = $this->checkDatabaseHealth();

            // Determine overall pipeline status
            $overallStatus = $this->determineOverallStatus(
                $syncStatus['status'] ?? 'idle',
                $cleanupStatus['status'] ?? 'idle',
                $hasRecentFailures,
                $dbHealth
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'overall_status' => $overallStatus,
                    'sync_job' => [
                        'status' => $syncStatus['status'] ?? 'idle',
                        'last_updated' => $syncStatus['updated_at'] ?? null,
                        'total_processed' => $syncStatus['total_processed'] ?? null,
                        'batches_processed' => $syncStatus['batches_processed'] ?? null,
                        'last_processed_id' => $syncStatus['last_processed_id'] ?? null,
                        'error' => $syncStatus['error'] ?? null,
                    ],
                    'cleanup_job' => [
                        'status' => $cleanupStatus['status'] ?? 'idle',
                        'last_updated' => $cleanupStatus['updated_at'] ?? null,
                        'records_deleted' => $cleanupStatus['records_deleted'] ?? null,
                        'error' => $cleanupStatus['error'] ?? null,
                    ],
                    'records' => [
                        'mysql_unsynced' => $unsyncedCount,
                        'mysql_synced' => $syncedCount,
                        'mysql_total' => $unsyncedCount + $syncedCount,
                        'postgresql_total' => $postgresCount,
                    ],
                    'last_sync' => $lastSync ? [
                        'timestamp' => $lastSync->created_at->toIso8601String(),
                        'records_affected' => $lastSync->records_affected,
                        'duration_ms' => $lastSync->duration_ms,
                        'batch_id' => $lastSync->batch_id,
                    ] : null,
                    'batches' => $batchStats,
                    'health' => [
                        'consecutive_failures' => $consecutiveFailures,
                        'has_recent_failures' => $hasRecentFailures,
                        'databases' => $dbHealth,
                    ],
                    'configuration' => [
                        'batch_size' => config('pipeline.batch_size'),
                        'retention_days' => config('pipeline.retention_days'),
                        'cleanup_enabled' => config('pipeline.cleanup_enabled', false),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get pipeline status', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'STATUS_ERROR',
                    'message' => 'Failed to retrieve pipeline status',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/pipeline/sync-logs
     * 
     * Returns sync history with pagination.
     * Supports filtering by operation, status, and date range.
     * 
     * Requirements: 2.5
     */
    public function syncLogs(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'per_page' => 'nullable|integer|min:1|max:100',
                'operation' => 'nullable|string|in:sync,verify,cleanup',
                'status' => 'nullable|string|in:success,failed,partial',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
                'batch_id' => 'nullable|integer',
            ]);

            $perPage = $validated['per_page'] ?? 20;
            $filters = array_filter([
                'operation' => $validated['operation'] ?? null,
                'status' => $validated['status'] ?? null,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
                'batch_id' => $validated['batch_id'] ?? null,
            ]);

            $logs = $this->syncLogService->getLogs($perPage, $filters);

            // Get statistics for the filtered period
            $stats = $this->syncLogService->getStatistics(
                $filters['date_from'] ? \Carbon\Carbon::parse($filters['date_from']) : null,
                $filters['date_to'] ? \Carbon\Carbon::parse($filters['date_to']) : null
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'logs' => $logs->items(),
                    'pagination' => [
                        'current_page' => $logs->currentPage(),
                        'last_page' => $logs->lastPage(),
                        'per_page' => $logs->perPage(),
                        'total' => $logs->total(),
                    ],
                    'statistics' => $stats,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get sync logs', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'LOGS_ERROR',
                    'message' => 'Failed to retrieve sync logs',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * POST /api/pipeline/sync/trigger
     * 
     * Manually trigger a sync job.
     * 
     * Requirements: 2.3
     */
    public function triggerSync(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'batch_size' => 'nullable|integer|min:1000|max:50000',
                'start_from_id' => 'nullable|integer|min:0',
            ]);

            // Check if a sync is already running
            $currentStatus = SyncJob::getStatus();
            if (($currentStatus['status'] ?? 'idle') === 'running') {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'SYNC_ALREADY_RUNNING',
                        'message' => 'A sync job is already running',
                        'details' => [
                            'started_at' => $currentStatus['updated_at'] ?? null,
                        ],
                    ],
                ], 409);
            }

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

            // Dispatch the sync job
            $batchSize = $validated['batch_size'] ?? null;
            $startFromId = $validated['start_from_id'] ?? null;

            SyncJob::dispatch($startFromId, $batchSize);

            Log::info('Sync job triggered manually', [
                'batch_size' => $batchSize,
                'start_from_id' => $startFromId,
                'triggered_by' => $request->user()?->id ?? 'api',
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Sync job has been queued',
                    'unsynced_count' => $this->syncService->getUnsyncedCount(),
                    'batch_size' => $batchSize ?? config('pipeline.batch_size'),
                    'start_from_id' => $startFromId,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to trigger sync job', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TRIGGER_ERROR',
                    'message' => 'Failed to trigger sync job',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * POST /api/pipeline/cleanup/trigger
     * 
     * Manually trigger a cleanup job.
     * ⚠️ Requires admin authentication and explicit confirmation.
     * 
     * Requirements: 4.5
     */
    public function triggerCleanup(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'confirm' => 'required|boolean',
                'retention_days' => 'nullable|integer|min:1|max:365',
                'batch_ids' => 'nullable|array',
                'batch_ids.*' => 'integer',
            ]);

            // SAFETY CHECK 1: Explicit confirmation required
            if (!$validated['confirm']) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'CONFIRMATION_REQUIRED',
                        'message' => 'Cleanup requires explicit confirmation. Set confirm=true to proceed.',
                        'details' => [
                            'warning' => '⚠️ This operation will DELETE records from MySQL alerts table!',
                        ],
                    ],
                ], 400);
            }

            // SAFETY CHECK 2: Cleanup must be enabled in config
            if (!config('pipeline.cleanup_enabled', false)) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'CLEANUP_DISABLED',
                        'message' => 'Cleanup is disabled in configuration',
                        'details' => [
                            'hint' => 'Set PIPELINE_CLEANUP_ENABLED=true in environment to enable cleanup.',
                        ],
                    ],
                ], 403);
            }

            // Check if a cleanup is already running
            $currentStatus = CleanupJob::getStatus();
            if (($currentStatus['status'] ?? 'idle') === 'running') {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'CLEANUP_ALREADY_RUNNING',
                        'message' => 'A cleanup job is already running',
                        'details' => [
                            'started_at' => $currentStatus['updated_at'] ?? null,
                        ],
                    ],
                ], 409);
            }

            // Get preview of what will be cleaned
            $preview = CleanupJob::preview();

            if ($preview['eligible_batches'] === 0) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'message' => 'No eligible batches for cleanup',
                        'eligible_batches' => 0,
                        'eligible_records' => 0,
                    ],
                ]);
            }

            // Dispatch the cleanup job with admin confirmation
            $retentionDays = $validated['retention_days'] ?? null;
            $batchIds = $validated['batch_ids'] ?? null;

            CleanupJob::dispatchWithAdminConfirmation($retentionDays, $batchIds);

            Log::warning('Cleanup job triggered manually', [
                'retention_days' => $retentionDays,
                'batch_ids' => $batchIds,
                'eligible_batches' => $preview['eligible_batches'],
                'eligible_records' => $preview['eligible_records'],
                'triggered_by' => $request->user()?->id ?? 'api',
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Cleanup job has been queued',
                    'warning' => '⚠️ Records will be DELETED from MySQL alerts table!',
                    'preview' => $preview,
                    'retention_days' => $retentionDays ?? config('pipeline.retention_days'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to trigger cleanup job', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TRIGGER_ERROR',
                    'message' => 'Failed to trigger cleanup job',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/pipeline/cleanup/preview
     * 
     * Preview what would be cleaned without actually cleaning.
     */
    public function cleanupPreview(): JsonResponse
    {
        try {
            $preview = $this->cleanupService->previewCleanup();
            $canProceed = $this->cleanupService->canProceedWithCleanup();

            return response()->json([
                'success' => true,
                'data' => [
                    'preview' => $preview,
                    'can_proceed' => $canProceed['can_proceed'],
                    'blockers' => $canProceed['reasons'],
                    'cleanup_enabled' => config('pipeline.cleanup_enabled', false),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get cleanup preview', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'PREVIEW_ERROR',
                    'message' => 'Failed to get cleanup preview',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Get batch statistics
     */
    protected function getBatchStatistics(): array
    {
        return [
            'pending' => SyncBatch::where('status', SyncBatch::STATUS_PENDING)->count(),
            'processing' => SyncBatch::where('status', SyncBatch::STATUS_PROCESSING)->count(),
            'completed' => SyncBatch::where('status', SyncBatch::STATUS_COMPLETED)->count(),
            'verified' => SyncBatch::where('status', SyncBatch::STATUS_VERIFIED)->count(),
            'failed' => SyncBatch::where('status', SyncBatch::STATUS_FAILED)->count(),
            'cleaned' => SyncBatch::where('status', SyncBatch::STATUS_CLEANED)->count(),
        ];
    }

    /**
     * Check database connection health
     */
    protected function checkDatabaseHealth(): array
    {
        $health = [
            'mysql' => ['status' => 'unknown', 'latency_ms' => null],
            'postgresql' => ['status' => 'unknown', 'latency_ms' => null],
        ];

        // Check MySQL
        try {
            $start = microtime(true);
            DB::connection('mysql')->getPdo();
            $health['mysql'] = [
                'status' => 'healthy',
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
            ];
        } catch (\Exception $e) {
            $health['mysql'] = [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }

        // Check PostgreSQL
        try {
            $start = microtime(true);
            DB::connection('pgsql')->getPdo();
            $health['postgresql'] = [
                'status' => 'healthy',
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
            ];
        } catch (\Exception $e) {
            $health['postgresql'] = [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }

        return $health;
    }

    /**
     * Determine overall pipeline status
     */
    protected function determineOverallStatus(
        string $syncStatus,
        string $cleanupStatus,
        bool $hasRecentFailures,
        array $dbHealth
    ): string {
        // Check for database issues first
        if ($dbHealth['mysql']['status'] !== 'healthy' || $dbHealth['postgresql']['status'] !== 'healthy') {
            return 'degraded';
        }

        // Check for failures
        if ($syncStatus === 'failed' || $cleanupStatus === 'failed') {
            return 'failed';
        }

        if ($hasRecentFailures) {
            return 'warning';
        }

        // Check if running
        if ($syncStatus === 'running' || $cleanupStatus === 'running') {
            return 'running';
        }

        return 'healthy';
    }
}
