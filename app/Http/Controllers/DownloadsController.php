<?php

namespace App\Http\Controllers;

use App\Models\PartitionRegistry;
use App\Services\PartitionQueryRouter;
use App\Jobs\GenerateCsvExportJob;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * DownloadsController - Centralized downloads interface
 * 
 * Provides partition-based download information for alerts and VM alerts.
 * Shows available dates with ACTUAL filtered record counts.
 */
class DownloadsController extends Controller
{
    private PartitionQueryRouter $partitionRouter;
    
    public function __construct(?PartitionQueryRouter $partitionRouter = null)
    {
        $this->partitionRouter = $partitionRouter ?? new PartitionQueryRouter();
    }
    
    /**
     * GET /api/downloads/partitions
     * 
     * Get available partitions with ACTUAL filtered record counts for downloads
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getPartitions(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'type' => 'required|string|in:all-alerts,vm-alerts',
            ]);

            // IMPORTANT: Close session to allow parallel requests
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            $type = $validated['type'];
            
            // Get all partition dates with combined statistics
            $allStats = PartitionRegistry::getAllCombinedStats();
            
            // Format for frontend with ACTUAL counts
            $partitions = $allStats->map(function ($stats) use ($type) {
                $date = Carbon::parse($stats['date']);
                
                if ($type === 'vm-alerts') {
                    // For VM alerts, get the ACTUAL filtered count
                    $actualCount = $this->getVMAlertCount($date);
                    
                    return [
                        'date' => $stats['date'],
                        'records' => $actualCount,
                        'alerts_table' => $stats['alerts_table'],
                        'backalerts_table' => $stats['backalerts_table'],
                        'alerts_count' => $stats['alerts_count'],
                        'backalerts_count' => $stats['backalerts_count'],
                    ];
                } else {
                    // For all-alerts, get the ACTUAL count (no filters)
                    $actualCount = $this->getAllAlertCount($date);
                    
                    return [
                        'date' => $stats['date'],
                        'records' => $actualCount,
                        'alerts_table' => $stats['alerts_table'],
                        'backalerts_table' => $stats['backalerts_table'],
                        'alerts_count' => $stats['alerts_count'],
                        'backalerts_count' => $stats['backalerts_count'],
                    ];
                }
            })
            ->filter(function ($partition) {
                // Only include partitions with records
                return $partition['records'] > 0;
            })
            ->values();

            return response()->json([
                'success' => true,
                'data' => $partitions,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch download partitions', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FETCH_ERROR',
                    'message' => 'Failed to fetch download partitions',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }
    
    /**
     * Get actual all alert count for a specific date (no filters)
     * 
     * @param Carbon $date
     * @return int
     */
    private function getAllAlertCount(Carbon $date): int
    {
        try {
            $startDate = $date->copy()->startOfDay();
            $endDate = $date->copy()->endOfDay();
            
            // No filters - get all records
            $filters = [];
            
            // Get actual count without filters
            $count = $this->partitionRouter->countDateRange(
                $startDate,
                $endDate,
                $filters,
                ['alerts', 'backalerts'] // Query both table types
            );
            
            return $count;
            
        } catch (\Exception $e) {
            Log::error('Failed to get all alert count', [
                'date' => $date->toDateString(),
                'error' => $e->getMessage()
            ]);
            
            return 0;
        }
    }
    
    /**
     * Get actual VM alert count for a specific date with VM filters applied
     * 
     * @param Carbon $date
     * @return int
     */
    private function getVMAlertCount(Carbon $date): int
    {
        try {
            $startDate = $date->copy()->startOfDay();
            $endDate = $date->copy()->endOfDay();
            
            // VM-SPECIFIC FILTERS: Only status O or C, and sendtoclient = S
            $filters = [
                'vm_status' => ['O', 'C'],
                'vm_sendtoclient' => 'S',
            ];
            
            // Get actual count with VM filters
            $count = $this->partitionRouter->countDateRange(
                $startDate,
                $endDate,
                $filters,
                ['alerts', 'backalerts'] // Query both table types
            );
            
            return $count;
            
        } catch (\Exception $e) {
            Log::error('Failed to get VM alert count', [
                'date' => $date->toDateString(),
                'error' => $e->getMessage()
            ]);
            
            return 0;
        }
    }
    
    /**
     * POST /api/downloads/request
     * 
     * Request a CSV export (queued for background processing)
     * This prevents portal blocking by processing exports in the background
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function requestExport(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'type' => 'required|string|in:all-alerts,vm-alerts',
                'date' => 'required|date',
            ]);

            $jobId = Str::uuid()->toString();
            $userId = auth()->id();

            // Create job record
            DB::table('export_jobs')->insert([
                'job_id' => $jobId,
                'user_id' => $userId,
                'type' => $validated['type'],
                'date' => $validated['date'],
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Dispatch job to queue
            GenerateCsvExportJob::dispatch(
                $validated['type'],
                $validated['date'],
                $userId,
                $jobId
            );

            Log::info('Export job queued', [
                'job_id' => $jobId,
                'type' => $validated['type'],
                'date' => $validated['date'],
                'user_id' => $userId
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'job_id' => $jobId,
                    'message' => 'Export queued successfully. You will be notified when ready.',
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to request export', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'REQUEST_ERROR',
                    'message' => 'Failed to request export',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/downloads/status/{jobId}
     * 
     * Check export job status
     * 
     * @param string $jobId
     * @return JsonResponse
     */
    public function checkStatus(string $jobId): JsonResponse
    {
        try {
            $job = DB::table('export_jobs')
                ->where('job_id', $jobId)
                ->where('user_id', auth()->id())
                ->first();

            if (!$job) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => 'Export job not found',
                    ],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'job_id' => $job->job_id,
                    'status' => $job->status,
                    'type' => $job->type,
                    'date' => $job->date,
                    'total_records' => $job->total_records,
                    'filepath' => $job->filepath,
                    'error_message' => $job->error_message,
                    'created_at' => $job->created_at,
                    'completed_at' => $job->completed_at,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to check export status', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'STATUS_ERROR',
                    'message' => 'Failed to check export status',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/downloads/file/{jobId}
     * 
     * Download completed export file
     * 
     * @param string $jobId
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function downloadFile(string $jobId)
    {
        try {
            $job = DB::table('export_jobs')
                ->where('job_id', $jobId)
                ->where('user_id', auth()->id())
                ->where('status', 'completed')
                ->first();

            if (!$job) {
                abort(404, 'Export file not found or not ready');
            }

            if (!Storage::disk('public')->exists($job->filepath)) {
                abort(404, 'Export file not found on disk');
            }

            Log::info('Export file downloaded', [
                'job_id' => $jobId,
                'user_id' => auth()->id(),
                'filepath' => $job->filepath
            ]);

            return Storage::disk('public')->download($job->filepath);

        } catch (\Exception $e) {
            Log::error('Failed to download export file', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            abort(500, 'Failed to download export file');
        }
    }

    /**
     * GET /api/downloads/my-exports
     * 
     * Get user's export history (last 50 exports)
     * 
     * @return JsonResponse
     */
    public function myExports(): JsonResponse
    {
        try {
            $exports = DB::table('export_jobs')
                ->where('user_id', auth()->id())
                ->orderBy('created_at', 'desc')
                ->limit(50)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $exports,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch export history', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FETCH_ERROR',
                    'message' => 'Failed to fetch export history',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * DELETE /api/downloads/delete/{jobId}
     * 
     * Delete an export job and its file
     * 
     * @param string $jobId
     * @return JsonResponse
     */
    public function deleteExport(string $jobId): JsonResponse
    {
        try {
            $job = DB::table('export_jobs')
                ->where('job_id', $jobId)
                ->where('user_id', auth()->id())
                ->first();

            if (!$job) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => 'Export job not found',
                    ],
                ], 404);
            }

            // Delete file if exists
            if ($job->filepath && Storage::disk('public')->exists($job->filepath)) {
                Storage::disk('public')->delete($job->filepath);
            }

            // Delete job record
            DB::table('export_jobs')->where('job_id', $jobId)->delete();

            Log::info('Export job deleted', [
                'job_id' => $jobId,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Export deleted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete export', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DELETE_ERROR',
                    'message' => 'Failed to delete export',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }
}
