<?php

namespace App\Http\Controllers;

use App\Models\PartitionRegistry;
use App\Services\PartitionQueryRouter;
use App\Jobs\GenerateCsvExportJobV2;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * DownloadsV2Controller - Redis-based downloads (TESTING)
 * 
 * This is a parallel implementation using Redis for queue and cache.
 * Does NOT affect the existing Downloads module.
 * 
 * Key Differences from V1:
 * - Uses Redis queue instead of database queue
 * - Caches partition data in Redis
 * - Real-time job status updates via Redis
 * - Faster job processing
 */
class DownloadsV2Controller extends Controller
{
    private PartitionQueryRouter $partitionRouter;
    
    public function __construct(?PartitionQueryRouter $partitionRouter = null)
    {
        $this->partitionRouter = $partitionRouter ?? new PartitionQueryRouter();
    }
    
    /**
     * GET /api/downloads-v2/partitions
     * 
     * Get available partitions with ACTUAL filtered record counts
     * Uses Redis cache for 5 minutes
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

            // Close session to allow parallel requests
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            $type = $validated['type'];
            $cacheKey = "downloads_v2_partitions_{$type}";
            
            // Try to get from Redis cache (5 minutes TTL)
            $cachedData = Redis::get($cacheKey);
            if ($cachedData) {
                Log::info('Downloads V2: Partitions loaded from Redis cache', ['type' => $type]);
                return response()->json([
                    'success' => true,
                    'data' => json_decode($cachedData, true),
                    'cached' => true,
                ]);
            }
            
            // Get all partition dates with combined statistics
            $allStats = PartitionRegistry::getAllCombinedStats();
            
            // Format for frontend with ACTUAL counts
            $partitions = $allStats->map(function ($stats) use ($type) {
                $date = Carbon::parse($stats['date']);
                
                if ($type === 'vm-alerts') {
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
                return $partition['records'] > 0;
            })
            ->values();

            // Cache in Redis for 5 minutes (300 seconds)
            Redis::setex($cacheKey, 300, json_encode($partitions));
            
            Log::info('Downloads V2: Partitions cached in Redis', [
                'type' => $type,
                'count' => $partitions->count()
            ]);

            return response()->json([
                'success' => true,
                'data' => $partitions,
                'cached' => false,
            ]);

        } catch (\Exception $e) {
            Log::error('Downloads V2: Failed to fetch partitions', [
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
     * POST /api/downloads-v2/request
     * 
     * Request a CSV export (queued to Redis for background processing)
     * Much faster than database queue
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

            // Create job record in database (for persistence)
            DB::table('export_jobs_v2')->insert([
                'job_id' => $jobId,
                'user_id' => $userId,
                'type' => $validated['type'],
                'date' => $validated['date'],
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Store initial status in Redis (for real-time updates)
            $redisKey = "export_job_v2:{$jobId}";
            Redis::hmset($redisKey, [
                'job_id' => $jobId,
                'user_id' => $userId,
                'type' => $validated['type'],
                'date' => $validated['date'],
                'status' => 'pending',
                'created_at' => now()->toISOString(),
            ]);
            Redis::expire($redisKey, 86400); // Expire after 24 hours

            // Dispatch job to Redis queue
            GenerateCsvExportJobV2::dispatch(
                $validated['type'],
                $validated['date'],
                $userId,
                $jobId
            )->onQueue('exports-v2'); // Use separate queue

            Log::info('Downloads V2: Export job queued to Redis', [
                'job_id' => $jobId,
                'type' => $validated['type'],
                'date' => $validated['date'],
                'user_id' => $userId
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'job_id' => $jobId,
                    'message' => 'Export queued successfully (Redis V2). You will be notified when ready.',
                    'version' => 'v2-redis',
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Downloads V2: Failed to request export', [
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
     * GET /api/downloads-v2/status/{jobId}
     * 
     * Check export job status (reads from Redis first, then database)
     * Real-time status updates
     * 
     * @param string $jobId
     * @return JsonResponse
     */
    public function checkStatus(string $jobId): JsonResponse
    {
        try {
            // Try Redis first (real-time status)
            $redisKey = "export_job_v2:{$jobId}";
            $redisData = Redis::hgetall($redisKey);
            
            if (!empty($redisData)) {
                // Convert Redis hash to object-like structure
                $job = (object) $redisData;
                
                Log::info('Downloads V2: Status loaded from Redis', [
                    'job_id' => $jobId,
                    'status' => $job->status ?? 'unknown'
                ]);
                
                return response()->json([
                    'success' => true,
                    'data' => [
                        'job_id' => $job->job_id ?? $jobId,
                        'status' => $job->status ?? 'unknown',
                        'type' => $job->type ?? null,
                        'date' => $job->date ?? null,
                        'total_records' => $job->total_records ?? null,
                        'filepath' => $job->filepath ?? null,
                        'error_message' => $job->error_message ?? null,
                        'created_at' => $job->created_at ?? null,
                        'completed_at' => $job->completed_at ?? null,
                        'progress_percent' => $job->progress_percent ?? 0,
                    ],
                    'source' => 'redis',
                ]);
            }
            
            // Fallback to database
            $job = DB::table('export_jobs_v2')
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
                    'progress_percent' => 0,
                ],
                'source' => 'database',
            ]);

        } catch (\Exception $e) {
            Log::error('Downloads V2: Failed to check export status', [
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
     * GET /api/downloads-v2/file/{jobId}
     * 
     * Download completed export file
     * 
     * @param string $jobId
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function downloadFile(string $jobId)
    {
        try {
            $job = DB::table('export_jobs_v2')
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

            Log::info('Downloads V2: Export file downloaded', [
                'job_id' => $jobId,
                'user_id' => auth()->id(),
                'filepath' => $job->filepath
            ]);

            return Storage::disk('public')->download($job->filepath);

        } catch (\Exception $e) {
            Log::error('Downloads V2: Failed to download export file', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            abort(500, 'Failed to download export file');
        }
    }

    /**
     * GET /api/downloads-v2/my-exports
     * 
     * Get user's export history (last 50 exports)
     * 
     * @return JsonResponse
     */
    public function myExports(): JsonResponse
    {
        try {
            $exports = DB::table('export_jobs_v2')
                ->where('user_id', auth()->id())
                ->orderBy('created_at', 'desc')
                ->limit(50)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $exports,
                'version' => 'v2-redis',
            ]);

        } catch (\Exception $e) {
            Log::error('Downloads V2: Failed to fetch export history', [
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
     * DELETE /api/downloads-v2/delete/{jobId}
     * 
     * Delete an export job and its file
     * 
     * @param string $jobId
     * @return JsonResponse
     */
    public function deleteExport(string $jobId): JsonResponse
    {
        try {
            $job = DB::table('export_jobs_v2')
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

            // Delete from database
            DB::table('export_jobs_v2')->where('job_id', $jobId)->delete();
            
            // Delete from Redis
            Redis::del("export_job_v2:{$jobId}");

            Log::info('Downloads V2: Export job deleted', [
                'job_id' => $jobId,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Export deleted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Downloads V2: Failed to delete export', [
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
    
    /**
     * GET /api/downloads-v2/stats
     * 
     * Get Redis queue statistics
     * 
     * @return JsonResponse
     */
    public function getStats(): JsonResponse
    {
        try {
            // Get queue size
            $queueSize = Redis::llen('queues:exports-v2');
            
            // Get recent jobs from Redis
            $recentJobs = [];
            $keys = Redis::keys('export_job_v2:*');
            foreach (array_slice($keys, 0, 10) as $key) {
                $jobData = Redis::hgetall($key);
                if (!empty($jobData)) {
                    $recentJobs[] = $jobData;
                }
            }
            
            // Get stats from database
            $dbStats = DB::table('export_jobs_v2')
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get()
                ->pluck('count', 'status');

            return response()->json([
                'success' => true,
                'data' => [
                    'queue_size' => $queueSize,
                    'recent_jobs_in_redis' => count($recentJobs),
                    'database_stats' => $dbStats,
                    'version' => 'v2-redis',
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Downloads V2: Failed to get stats', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'STATS_ERROR',
                    'message' => 'Failed to get statistics',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }
    
    // Private helper methods (same as V1)
    
    private function getAllAlertCount(Carbon $date): int
    {
        try {
            $startDate = $date->copy()->startOfDay();
            $endDate = $date->copy()->endOfDay();
            
            $filters = [];
            
            $count = $this->partitionRouter->countDateRange(
                $startDate,
                $endDate,
                $filters,
                ['alerts', 'backalerts']
            );
            
            return $count;
            
        } catch (\Exception $e) {
            Log::error('Downloads V2: Failed to get all alert count', [
                'date' => $date->toDateString(),
                'error' => $e->getMessage()
            ]);
            
            return 0;
        }
    }
    
    private function getVMAlertCount(Carbon $date): int
    {
        try {
            $startDate = $date->copy()->startOfDay();
            $endDate = $date->copy()->endOfDay();
            
            $filters = [
                'vm_status' => ['O', 'C'],
                'vm_sendtoclient' => 'S',
            ];
            
            $count = $this->partitionRouter->countDateRange(
                $startDate,
                $endDate,
                $filters,
                ['alerts', 'backalerts']
            );
            
            return $count;
            
        } catch (\Exception $e) {
            Log::error('Downloads V2: Failed to get VM alert count', [
                'date' => $date->toDateString(),
                'error' => $e->getMessage()
            ]);
            
            return 0;
        }
    }
}
