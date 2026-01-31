<?php

namespace App\Http\Controllers;

use App\Services\GenericSyncService;
use App\Services\TableSyncConfigurationService;
use App\Services\TableSyncLogService;
use App\Services\TableSyncErrorQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * TableSyncController handles sync operations and monitoring for table sync.
 * 
 * Provides endpoints for:
 * - POST /api/table-sync/sync/{id} - Trigger sync for specific table
 * - POST /api/table-sync/sync-all - Trigger sync for all enabled tables
 * - GET /api/table-sync/status/{id} - Get sync status for table
 * - GET /api/table-sync/logs - Get sync logs with filters
 * - GET /api/table-sync/errors - Get error queue with filters
 * - POST /api/table-sync/errors/{id}/retry - Retry specific error
 * 
 * ⚠️ NO DELETION FROM MYSQL: Sync reads from MySQL, writes to PostgreSQL
 * 
 * Requirements: 5.1, 5.6, 8.2, 8.3
 */
class TableSyncController extends Controller
{
    protected GenericSyncService $syncService;
    protected TableSyncConfigurationService $configService;
    protected TableSyncLogService $logService;
    protected TableSyncErrorQueueService $errorQueueService;

    public function __construct(
        GenericSyncService $syncService,
        TableSyncConfigurationService $configService,
        TableSyncLogService $logService,
        TableSyncErrorQueueService $errorQueueService
    ) {
        $this->syncService = $syncService;
        $this->configService = $configService;
        $this->logService = $logService;
        $this->errorQueueService = $errorQueueService;
    }

    /**
     * POST /api/table-sync/sync/{id}
     * 
     * Trigger sync for a specific table configuration.
     * 
     * ⚠️ NO DELETION FROM MYSQL: Reads from MySQL, writes to PostgreSQL
     * 
     * @param int $id Configuration ID
     * @return JsonResponse
     */
    public function sync(int $id): JsonResponse
    {
        try {
            $config = $this->configService->getById($id);

            if (!$config) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => "Configuration with ID {$id} not found",
                    ],
                ], 404);
            }

            Log::info('Manual sync triggered', [
                'configuration_id' => $id,
                'source_table' => $config->source_table,
            ]);

            $result = $this->syncService->syncTable($id);

            return response()->json([
                'success' => $result->success,
                'data' => [
                    'message' => $result->success 
                        ? 'Sync completed successfully' 
                        : 'Sync completed with errors',
                    'records_synced' => $result->recordsSynced,
                    'records_failed' => $result->recordsFailed,
                    'start_id' => $result->startId,
                    'end_id' => $result->endId,
                    'error_message' => $result->errorMessage,
                ],
            ], $result->success ? 200 : 207);
        } catch (\Exception $e) {
            Log::error('Failed to trigger sync', [
                'configuration_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SYNC_ERROR',
                    'message' => 'Failed to trigger sync',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * POST /api/table-sync/sync-all
     * 
     * Trigger sync for all enabled table configurations.
     * 
     * ⚠️ NO DELETION FROM MYSQL: Reads from MySQL, writes to PostgreSQL
     * 
     * @return JsonResponse
     */
    public function syncAll(): JsonResponse
    {
        try {
            Log::info('Sync all tables triggered');

            $results = $this->syncService->syncAllTables();

            $summary = [
                'total' => count($results),
                'successful' => 0,
                'failed' => 0,
                'total_records_synced' => 0,
                'total_records_failed' => 0,
            ];

            $details = [];
            foreach ($results as $configId => $result) {
                if ($result->success) {
                    $summary['successful']++;
                } else {
                    $summary['failed']++;
                }
                $summary['total_records_synced'] += $result->recordsSynced;
                $summary['total_records_failed'] += $result->recordsFailed;

                $details[$configId] = [
                    'success' => $result->success,
                    'records_synced' => $result->recordsSynced,
                    'records_failed' => $result->recordsFailed,
                    'error_message' => $result->errorMessage,
                ];
            }

            return response()->json([
                'success' => $summary['failed'] === 0,
                'data' => [
                    'message' => $summary['failed'] === 0 
                        ? 'All syncs completed successfully' 
                        : 'Some syncs completed with errors',
                    'summary' => $summary,
                    'details' => $details,
                ],
            ], $summary['failed'] === 0 ? 200 : 207);
        } catch (\Exception $e) {
            Log::error('Failed to trigger sync all', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SYNC_ALL_ERROR',
                    'message' => 'Failed to trigger sync for all tables',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/table-sync/status/{id}
     * 
     * Get sync status for a specific table configuration.
     * 
     * @param int $id Configuration ID
     * @return JsonResponse
     */
    public function status(int $id): JsonResponse
    {
        try {
            $config = $this->configService->getById($id);

            if (!$config) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => "Configuration with ID {$id} not found",
                    ],
                ], 404);
            }

            $status = $this->syncService->getSyncStatus($id);
            $unsyncedCount = $this->syncService->getUnsyncedCount($id);
            $lastLog = $this->logService->getLastLog($id);
            $lastSuccessfulLog = $this->logService->getLastSuccessfulLog($id);
            $errorStats = $this->errorQueueService->getStatistics($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'configuration' => [
                        'id' => $config->id,
                        'name' => $config->name,
                        'source_table' => $config->source_table,
                        'target_table' => $config->getEffectiveTargetTable(),
                        'is_enabled' => $config->is_enabled,
                    ],
                    'status' => $status,
                    'unsynced_count' => $unsyncedCount,
                    'last_sync' => $lastLog ? [
                        'id' => $lastLog->id,
                        'status' => $lastLog->status,
                        'records_synced' => $lastLog->records_synced,
                        'records_failed' => $lastLog->records_failed,
                        'duration_ms' => $lastLog->duration_ms,
                        'started_at' => $lastLog->started_at?->toIso8601String(),
                        'completed_at' => $lastLog->completed_at?->toIso8601String(),
                        'error_message' => $lastLog->error_message,
                    ] : null,
                    'last_successful_sync' => $lastSuccessfulLog ? [
                        'id' => $lastSuccessfulLog->id,
                        'records_synced' => $lastSuccessfulLog->records_synced,
                        'duration_ms' => $lastSuccessfulLog->duration_ms,
                        'completed_at' => $lastSuccessfulLog->completed_at?->toIso8601String(),
                    ] : null,
                    'error_queue' => $errorStats,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get sync status', [
                'configuration_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'STATUS_ERROR',
                    'message' => 'Failed to retrieve sync status',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/table-sync/logs
     * 
     * Get sync logs with filtering and pagination.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function logs(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'configuration_id' => 'nullable|integer',
                'source_table' => 'nullable|string|max:255',
                'status' => 'nullable|string|in:running,completed,failed,partial',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            $filters = array_filter([
                'configuration_id' => $validated['configuration_id'] ?? null,
                'source_table' => $validated['source_table'] ?? null,
                'status' => $validated['status'] ?? null,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
            ]);

            $perPage = $validated['per_page'] ?? 20;
            $logs = $this->logService->getLogs($filters, $perPage);

            // Get statistics for the filtered period
            $stats = $this->logService->getStatistics(
                isset($filters['date_from']) ? \Carbon\Carbon::parse($filters['date_from']) : null,
                isset($filters['date_to']) ? \Carbon\Carbon::parse($filters['date_to']) : null
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
     * GET /api/table-sync/errors
     * 
     * Get error queue with filtering and pagination.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function errors(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'configuration_id' => 'nullable|integer',
                'source_table' => 'nullable|string|max:255',
                'resolved' => 'nullable|boolean',
                'retryable' => 'nullable|boolean',
                'exceeded_max_retries' => 'nullable|boolean',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            $filters = array_filter([
                'configuration_id' => $validated['configuration_id'] ?? null,
                'source_table' => $validated['source_table'] ?? null,
                'resolved' => $validated['resolved'] ?? null,
                'retryable' => $validated['retryable'] ?? null,
                'exceeded_max_retries' => $validated['exceeded_max_retries'] ?? null,
            ], fn($v) => $v !== null);

            $perPage = $validated['per_page'] ?? 20;
            $errors = $this->errorQueueService->getQueuedErrors($filters, $perPage);

            // Get overall statistics
            $stats = $this->errorQueueService->getStatistics(
                $validated['configuration_id'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'errors' => $errors->items(),
                    'pagination' => [
                        'current_page' => $errors->currentPage(),
                        'last_page' => $errors->lastPage(),
                        'per_page' => $errors->perPage(),
                        'total' => $errors->total(),
                    ],
                    'statistics' => $stats,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get error queue', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ERRORS_ERROR',
                    'message' => 'Failed to retrieve error queue',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * POST /api/table-sync/errors/{id}/retry
     * 
     * Retry a specific error from the error queue.
     * 
     * ⚠️ NO DELETION FROM MYSQL: Retry re-reads from MySQL, writes to PostgreSQL
     * 
     * @param int $id Error ID
     * @return JsonResponse
     */
    public function retryError(int $id): JsonResponse
    {
        try {
            Log::info('Retrying error from queue', ['error_id' => $id]);

            $success = $this->errorQueueService->retryById($id);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'message' => 'Error retry successful - record synced',
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'RETRY_FAILED',
                    'message' => 'Error retry failed - record could not be synced',
                ],
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to retry error', [
                'error_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'RETRY_ERROR',
                    'message' => 'Failed to retry error',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * POST /api/table-sync/errors/{id}/resolve
     * 
     * Mark an error as resolved without retrying.
     * 
     * @param int $id Error ID
     * @return JsonResponse
     */
    public function resolveError(int $id): JsonResponse
    {
        try {
            Log::info('Marking error as resolved', ['error_id' => $id]);

            $success = $this->errorQueueService->markResolved($id);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'message' => 'Error marked as resolved',
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => "Error with ID {$id} not found",
                ],
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to resolve error', [
                'error_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'RESOLVE_ERROR',
                    'message' => 'Failed to resolve error',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * POST /api/table-sync/errors/retry-all
     * 
     * Retry all eligible errors in the queue.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function retryAllErrors(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'configuration_id' => 'nullable|integer',
            ]);

            Log::info('Retrying all eligible errors', [
                'configuration_id' => $validated['configuration_id'] ?? 'all',
            ]);

            if (isset($validated['configuration_id'])) {
                $results = $this->errorQueueService->retryAllForConfiguration(
                    $validated['configuration_id']
                );
            } else {
                $results = $this->errorQueueService->retryAllEligible();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Retry operation completed',
                    'results' => $results,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retry all errors', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'RETRY_ALL_ERROR',
                    'message' => 'Failed to retry all errors',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/table-sync/overview
     * 
     * Get an overview of all table sync configurations and their status.
     * 
     * @return JsonResponse
     */
    public function overview(): JsonResponse
    {
        try {
            $configurations = $this->configService->getAllWithStats();
            $overallStats = $this->logService->getStatistics();
            $errorStats = $this->errorQueueService->getStatistics();

            $tableStatuses = [];
            foreach ($configurations as $config) {
                $tableStatuses[] = [
                    'id' => $config->id,
                    'name' => $config->name,
                    'source_table' => $config->source_table,
                    'target_table' => $config->getEffectiveTargetTable(),
                    'is_enabled' => $config->is_enabled,
                    'status' => $this->syncService->getSyncStatus($config->id),
                    'source_count' => $config->source_count ?? 0,
                    'target_count' => $config->target_count ?? 0,
                    'unsynced_count' => $config->unsynced_count ?? 0,
                    'sync_progress' => $config->sync_progress ?? 100,
                    'last_sync_at' => $config->last_sync_at?->toIso8601String(),
                    'last_sync_status' => $config->last_sync_status,
                    'total_syncs' => $config->total_syncs ?? 0,
                    'successful_syncs' => $config->successful_syncs ?? 0,
                    'failed_syncs' => $config->failed_syncs ?? 0,
                    'unresolved_errors' => $config->unresolved_errors ?? 0,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'tables' => $tableStatuses,
                    'overall_statistics' => $overallStats,
                    'error_queue_statistics' => $errorStats,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get table sync overview', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'OVERVIEW_ERROR',
                    'message' => 'Failed to retrieve table sync overview',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * POST /api/table-sync/{id}/resume
     * 
     * Resume a paused sync (clear error threshold pause).
     * 
     * @param int $id Configuration ID
     * @return JsonResponse
     */
    public function resume(int $id): JsonResponse
    {
        try {
            $config = $this->configService->getById($id);

            if (!$config) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => "Configuration with ID {$id} not found",
                    ],
                ], 404);
            }

            Log::info('Resuming paused sync', ['configuration_id' => $id]);

            $this->syncService->getErrorThresholdService()->resumeSync($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Sync resumed successfully',
                    'status' => $this->syncService->getSyncStatus($id),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to resume sync', [
                'configuration_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'RESUME_ERROR',
                    'message' => 'Failed to resume sync',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * POST /api/table-sync/{id}/force-unlock
     * 
     * Force unlock a stuck sync (release lock without waiting).
     * Use this when a sync is stuck in "locked" or "running" state.
     * 
     * @param int $id Configuration ID
     * @return JsonResponse
     */
    public function forceUnlock(int $id): JsonResponse
    {
        try {
            $config = $this->configService->getById($id);

            if (!$config) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => "Configuration with ID {$id} not found",
                    ],
                ], 404);
            }

            Log::warning('Force unlocking sync', [
                'configuration_id' => $id,
                'source_table' => $config->source_table,
            ]);

            // Release the lock
            $this->syncService->getLockService()->releaseLock($id);

            // Reset status to idle if it was running
            if ($config->last_sync_status === 'running') {
                $config->updateSyncStatus('idle');
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Sync lock released successfully. You can now start a new sync.',
                    'status' => $this->syncService->getSyncStatus($id),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to force unlock sync', [
                'configuration_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNLOCK_ERROR',
                    'message' => 'Failed to force unlock sync',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * POST /api/table-sync/force-unlock-all
     * 
     * Force unlock all stuck syncs.
     * 
     * @return JsonResponse
     */
    public function forceUnlockAll(): JsonResponse
    {
        try {
            Log::warning('Force unlocking all syncs');

            $configurations = $this->configService->getAll();
            $unlocked = [];

            foreach ($configurations as $config) {
                $wasLocked = $this->syncService->getLockService()->isLocked($config->id);
                
                if ($wasLocked) {
                    $this->syncService->getLockService()->releaseLock($config->id);
                    
                    if ($config->last_sync_status === 'running') {
                        $config->updateSyncStatus('idle');
                    }
                    
                    $unlocked[] = [
                        'id' => $config->id,
                        'name' => $config->name,
                        'source_table' => $config->source_table,
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => count($unlocked) > 0 
                        ? count($unlocked) . ' sync(s) unlocked successfully'
                        : 'No locked syncs found',
                    'unlocked' => $unlocked,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to force unlock all syncs', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNLOCK_ALL_ERROR',
                    'message' => 'Failed to force unlock all syncs',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }
}
