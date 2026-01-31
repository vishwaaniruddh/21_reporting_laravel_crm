<?php

namespace App\Http\Controllers;

use App\Services\ErrorQueueService;
use App\Models\FailedSyncRecord;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * ErrorQueueController provides admin interface for managing failed sync records.
 * 
 * This controller allows administrators to view, retry, resolve, and ignore
 * records that have failed to sync from MySQL to PostgreSQL.
 * 
 * Requirements: 7.5
 */
class ErrorQueueController extends Controller
{
    protected ErrorQueueService $errorQueueService;

    public function __construct(ErrorQueueService $errorQueueService)
    {
        $this->errorQueueService = $errorQueueService;
    }

    /**
     * Get error queue statistics
     * 
     * GET /api/pipeline/error-queue/stats
     */
    public function statistics(): JsonResponse
    {
        $stats = $this->errorQueueService->getStatistics();

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * List failed records with pagination
     * 
     * GET /api/pipeline/error-queue
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->get('status');
        $perPage = $request->get('per_page', 20);

        $query = FailedSyncRecord::query()
            ->orderBy('created_at', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        $records = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $records,
        ]);
    }

    /**
     * Get records requiring manual review
     * 
     * GET /api/pipeline/error-queue/review
     */
    public function requiresReview(): JsonResponse
    {
        $records = $this->errorQueueService->getRecordsRequiringReview();

        return response()->json([
            'success' => true,
            'data' => $records,
            'count' => $records->count(),
        ]);
    }

    /**
     * Get a single failed record
     * 
     * GET /api/pipeline/error-queue/{id}
     */
    public function show(int $id): JsonResponse
    {
        $record = FailedSyncRecord::find($id);

        if (!$record) {
            return response()->json([
                'success' => false,
                'error' => 'Record not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $record,
        ]);
    }

    /**
     * Retry a single failed record
     * 
     * POST /api/pipeline/error-queue/{id}/retry
     */
    public function retry(int $id): JsonResponse
    {
        $record = FailedSyncRecord::find($id);

        if (!$record) {
            return response()->json([
                'success' => false,
                'error' => 'Record not found',
            ], 404);
        }

        $success = $this->errorQueueService->retryRecord($record);

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Record synced successfully' : 'Retry failed',
            'data' => $record->fresh(),
        ]);
    }

    /**
     * Force retry a record (even if max retries exceeded)
     * 
     * POST /api/pipeline/error-queue/{id}/force-retry
     */
    public function forceRetry(int $id): JsonResponse
    {
        $success = $this->errorQueueService->forceRetry($id);

        if (!$success) {
            return response()->json([
                'success' => false,
                'error' => 'Record not found or retry failed',
            ], 404);
        }

        $record = FailedSyncRecord::find($id);

        return response()->json([
            'success' => true,
            'message' => 'Force retry completed',
            'data' => $record,
        ]);
    }

    /**
     * Retry all eligible records
     * 
     * POST /api/pipeline/error-queue/retry-all
     */
    public function retryAll(): JsonResponse
    {
        $results = $this->errorQueueService->retryEligibleRecords();

        return response()->json([
            'success' => true,
            'message' => 'Retry completed',
            'data' => $results,
        ]);
    }

    /**
     * Mark a record as resolved
     * 
     * POST /api/pipeline/error-queue/{id}/resolve
     */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $userId = $request->user()?->id;
        $notes = $request->get('notes');

        $success = $this->errorQueueService->resolveRecord($id, $userId, $notes);

        if (!$success) {
            return response()->json([
                'success' => false,
                'error' => 'Record not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Record marked as resolved',
        ]);
    }

    /**
     * Mark a record as ignored
     * 
     * POST /api/pipeline/error-queue/{id}/ignore
     */
    public function ignore(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $userId = $request->user()?->id;
        $notes = $request->get('notes');

        $success = $this->errorQueueService->ignoreRecord($id, $userId, $notes);

        if (!$success) {
            return response()->json([
                'success' => false,
                'error' => 'Record not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Record marked as ignored',
        ]);
    }

    /**
     * Clean up old resolved/ignored records
     * 
     * DELETE /api/pipeline/error-queue/cleanup
     */
    public function cleanup(Request $request): JsonResponse
    {
        $request->validate([
            'days_old' => 'nullable|integer|min:1|max:365',
        ]);

        $daysOld = $request->get('days_old', 30);
        $deleted = $this->errorQueueService->cleanupOldRecords($daysOld);

        return response()->json([
            'success' => true,
            'message' => "Cleaned up {$deleted} old records",
            'deleted' => $deleted,
        ]);
    }
}
