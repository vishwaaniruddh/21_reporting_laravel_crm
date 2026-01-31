<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SystemHealthController - System health and performance monitoring
 * 
 * Provides real-time system metrics including:
 * - Server resources (CPU, Memory, Disk)
 * - Database performance
 * - Application metrics
 * - API usage statistics
 */
class SystemHealthController extends Controller
{
    /**
     * GET /api/system/health
     * 
     * Get comprehensive system health metrics
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $data = [
                'server_stats' => $this->getServerStats(),
                'database_stats' => $this->getDatabaseStats(),
                'application_stats' => $this->getApplicationStats(),
                'api_stats' => $this->getApiStats(),
                'cache_stats' => $this->getCacheStats(),
                'disk_usage' => $this->getDiskUsage(),
                'process_info' => $this->getProcessInfo(),
                'log_files' => $this->getLogFiles(),
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
                'timestamp' => now()->toIso8601String(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch system health', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SYSTEM_HEALTH_ERROR',
                    'message' => 'Failed to fetch system health metrics',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/system/logs/{filename}
     * 
     * Read a specific log file
     */
    public function readLog(Request $request, string $filename): JsonResponse
    {
        try {
            $logPath = storage_path('logs/' . basename($filename));
            
            if (!file_exists($logPath)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Log file not found'],
                ], 404);
            }

            $lines = $request->get('lines', 500);
            $content = $this->tailFile($logPath, $lines);

            return response()->json([
                'success' => true,
                'data' => [
                    'filename' => $filename,
                    'content' => $content,
                    'size' => $this->formatBytes(filesize($logPath)),
                    'modified' => date('Y-m-d H:i:s', filemtime($logPath)),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['message' => 'Failed to read log file: ' . $e->getMessage()],
            ], 500);
        }
    }

    /**
     * DELETE /api/system/logs/{filename}
     * 
     * Clear a specific log file
     */
    public function clearLog(Request $request, string $filename): JsonResponse
    {
        try {
            $logPath = storage_path('logs/' . basename($filename));
            
            if (!file_exists($logPath)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Log file not found'],
                ], 404);
            }

            // Clear the file content
            file_put_contents($logPath, '');
            
            Log::info('Log file cleared', ['filename' => $filename, 'user' => auth()->id()]);

            return response()->json([
                'success' => true,
                'message' => 'Log file cleared successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['message' => 'Failed to clear log file: ' . $e->getMessage()],
            ], 500);
        }
    }

    /**
     * Get list of log files with details
     */
    private function getLogFiles(): array
    {
        $logPath = storage_path('logs');
        $files = [];

        if (!is_dir($logPath)) {
            return $files;
        }

        $iterator = new \DirectoryIterator($logPath);
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'log') {
                $files[] = [
                    'name' => $file->getFilename(),
                    'size' => $file->getSize(),
                    'size_formatted' => $this->formatBytes($file->getSize()),
                    'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                    'modified_ago' => $this->timeAgo($file->getMTime()),
                ];
            }
        }

        // Sort by modified date descending
        usort($files, fn($a, $b) => strtotime($b['modified']) - strtotime($a['modified']));

        return $files;
    }

    /**
     * Read last N lines from a file
     */
    private function tailFile(string $filepath, int $lines = 500): string
    {
        $file = new \SplFileObject($filepath, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();

        $startLine = max(0, $totalLines - $lines);
        $content = [];

        $file->seek($startLine);
        while (!$file->eof()) {
            $content[] = $file->current();
            $file->next();
        }

        return implode('', $content);
    }

    /**
     * Get human readable time ago
     */
    private function timeAgo(int $timestamp): string
    {
        $diff = time() - $timestamp;
        
        if ($diff < 60) return $diff . ' seconds ago';
        if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
        if ($diff < 604800) return floor($diff / 86400) . ' days ago';
        
        return date('M j, Y', $timestamp);
    }

    /**
     * Get server resource statistics
     */
    private function getServerStats(): array
    {
        $stats = [];

        // Memory usage
        $stats['memory'] = [
            'used' => memory_get_usage(true),
            'used_formatted' => $this->formatBytes(memory_get_usage(true)),
            'peak' => memory_get_peak_usage(true),
            'peak_formatted' => $this->formatBytes(memory_get_peak_usage(true)),
            'limit' => ini_get('memory_limit'),
        ];

        // Get system memory info (Linux)
        if (PHP_OS_FAMILY === 'Linux' && file_exists('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
            preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $available);
            
            if (!empty($total[1]) && !empty($available[1])) {
                $totalMem = $total[1] * 1024; // Convert KB to bytes
                $availableMem = $available[1] * 1024;
                $usedMem = $totalMem - $availableMem;
                
                $stats['system_memory'] = [
                    'total' => $totalMem,
                    'total_formatted' => $this->formatBytes($totalMem),
                    'used' => $usedMem,
                    'used_formatted' => $this->formatBytes($usedMem),
                    'available' => $availableMem,
                    'available_formatted' => $this->formatBytes($availableMem),
                    'usage_percent' => round(($usedMem / $totalMem) * 100, 2),
                ];
            }
        }

        // CPU load average (Linux/Unix)
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $stats['cpu'] = [
                'load_1min' => round($load[0], 2),
                'load_5min' => round($load[1], 2),
                'load_15min' => round($load[2], 2),
            ];
        }

        // PHP version and configuration
        $stats['php'] = [
            'version' => PHP_VERSION,
            'os' => PHP_OS,
            'sapi' => PHP_SAPI,
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
        ];

        return $stats;
    }

    /**
     * Get database statistics
     */
    private function getDatabaseStats(): array
    {
        $stats = [];

        try {
            // PostgreSQL stats
            $pgStats = DB::connection('pgsql')->select("
                SELECT 
                    pg_database_size(current_database()) as db_size,
                    (SELECT count(*) FROM pg_stat_activity WHERE state = 'active') as active_connections,
                    (SELECT count(*) FROM pg_stat_activity) as total_connections
            ");

            if (!empty($pgStats)) {
                $stats['postgresql'] = [
                    'database_size' => $pgStats[0]->db_size,
                    'database_size_formatted' => $this->formatBytes($pgStats[0]->db_size),
                    'active_connections' => $pgStats[0]->active_connections,
                    'total_connections' => $pgStats[0]->total_connections,
                    'status' => 'connected',
                ];

                // Get table count and sizes
                $tables = DB::connection('pgsql')->select("
                    SELECT 
                        schemaname,
                        COUNT(*) as table_count,
                        SUM(pg_total_relation_size(schemaname||'.'||tablename)) as total_size
                    FROM pg_tables 
                    WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
                    GROUP BY schemaname
                ");

                $stats['postgresql']['tables'] = $tables;
            }
        } catch (\Exception $e) {
            $stats['postgresql'] = [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }

        try {
            // MySQL stats
            $mysqlStats = DB::connection('mysql')->select("
                SELECT 
                    SUM(data_length + index_length) as db_size,
                    COUNT(*) as table_count
                FROM information_schema.TABLES 
                WHERE table_schema = DATABASE()
            ");

            if (!empty($mysqlStats)) {
                $stats['mysql'] = [
                    'database_size' => $mysqlStats[0]->db_size,
                    'database_size_formatted' => $this->formatBytes($mysqlStats[0]->db_size),
                    'table_count' => $mysqlStats[0]->table_count,
                    'status' => 'connected',
                ];

                // Get connection stats
                $connections = DB::connection('mysql')->select("SHOW STATUS LIKE 'Threads_connected'");
                if (!empty($connections)) {
                    $stats['mysql']['active_connections'] = $connections[0]->Value;
                }
            }
        } catch (\Exception $e) {
            $stats['mysql'] = [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }

        return $stats;
    }

    /**
     * Get application statistics
     */
    private function getApplicationStats(): array
    {
        $stats = [];

        // Laravel version
        $stats['laravel_version'] = app()->version();

        // Environment
        $stats['environment'] = config('app.env');
        $stats['debug_mode'] = config('app.debug');

        // Uptime (if available)
        if (function_exists('posix_getpid')) {
            $pid = posix_getpid();
            $stats['process_id'] = $pid;
        }

        // Session stats
        $stats['session'] = [
            'driver' => config('session.driver'),
            'lifetime' => config('session.lifetime'),
        ];

        // Queue stats (if using database queue)
        try {
            $queueStats = DB::connection('pgsql')->table('jobs')->select([
                DB::raw('COUNT(*) as total_jobs'),
                DB::raw('COUNT(CASE WHEN attempts = 0 THEN 1 END) as pending_jobs'),
                DB::raw('COUNT(CASE WHEN attempts > 0 THEN 1 END) as processing_jobs'),
            ])->first();

            // Get job details for modal
            $jobDetails = DB::connection('pgsql')->table('jobs')
                ->select(['id', 'queue', 'payload', 'attempts', 'created_at', 'available_at', 'reserved_at'])
                ->orderBy('created_at', 'desc')
                ->limit(50)
                ->get()
                ->map(function ($job) {
                    // Decode payload to get job class name
                    $jobName = 'Unknown Job';
                    $jobData = [];
                    try {
                        $payload = json_decode($job->payload, true);
                        if (isset($payload['displayName'])) {
                            $jobName = class_basename($payload['displayName']);
                        } elseif (isset($payload['job'])) {
                            $jobName = class_basename($payload['job']);
                        }
                        // Get command data if available
                        if (isset($payload['data']['command'])) {
                            $command = unserialize($payload['data']['command']);
                            $jobData = [
                                'class' => get_class($command),
                            ];
                        }
                    } catch (\Exception $e) {
                        // Ignore decode errors
                    }

                    // Determine status
                    $status = 'pending';
                    if ($job->reserved_at !== null) {
                        $status = 'processing';
                    } elseif ($job->attempts > 0) {
                        $status = 'retrying';
                    }

                    return [
                        'id' => $job->id,
                        'queue' => $job->queue,
                        'job_name' => $jobName,
                        'attempts' => $job->attempts,
                        'status' => $status,
                        'created_at' => $job->created_at,
                        'available_at' => $job->available_at,
                    ];
                });

            $stats['queue'] = [
                'total_jobs' => $queueStats->total_jobs ?? 0,
                'pending_jobs' => $queueStats->pending_jobs ?? 0,
                'processing_jobs' => $queueStats->processing_jobs ?? 0,
                'job_details' => $jobDetails,
            ];
        } catch (\Exception $e) {
            $stats['queue'] = ['status' => 'not_configured', 'error' => $e->getMessage()];
        }

        // Failed jobs
        try {
            $failedJobs = DB::connection('pgsql')->table('failed_jobs')
                ->select(['id', 'queue', 'failed_at', 'exception'])
                ->orderBy('failed_at', 'desc')
                ->limit(20)
                ->get()
                ->map(function ($job) {
                    return [
                        'id' => $job->id,
                        'queue' => $job->queue,
                        'failed_at' => $job->failed_at,
                        'exception' => substr($job->exception ?? '', 0, 200),
                    ];
                });

            $stats['failed_jobs'] = [
                'count' => DB::connection('pgsql')->table('failed_jobs')->count(),
                'details' => $failedJobs,
            ];
        } catch (\Exception $e) {
            $stats['failed_jobs'] = ['count' => 0, 'details' => []];
        }

        return $stats;
    }

    /**
     * Get API usage statistics
     */
    private function getApiStats(): array
    {
        $stats = [];

        try {
            $todayStart = now()->startOfDay();
            $hourStart = now()->startOfHour();

            // Get from api_request_logs table
            $stats['total_requests_today'] = DB::connection('pgsql')
                ->table('api_request_logs')
                ->where('created_at', '>=', $todayStart)
                ->count();

            $stats['total_requests_hour'] = DB::connection('pgsql')
                ->table('api_request_logs')
                ->where('created_at', '>=', $hourStart)
                ->count();

            $stats['error_count_today'] = DB::connection('pgsql')
                ->table('api_request_logs')
                ->where('created_at', '>=', $todayStart)
                ->where('status_code', '>=', 400)
                ->count();

            // Top endpoints
            $stats['top_endpoints'] = DB::connection('pgsql')
                ->table('api_request_logs')
                ->select('endpoint', DB::raw('COUNT(*) as count'), DB::raw('AVG(response_time) as avg_time'))
                ->where('created_at', '>=', $todayStart)
                ->groupBy('endpoint')
                ->orderByDesc('count')
                ->limit(10)
                ->get();

            // Average response time
            $stats['avg_response_time'] = round(DB::connection('pgsql')
                ->table('api_request_logs')
                ->where('created_at', '>=', $todayStart)
                ->avg('response_time') ?? 0, 2);

            // Recent requests with user info - Today's requests
            $stats['recent_requests'] = DB::connection('pgsql')
                ->table('api_request_logs as a')
                ->leftJoin('users as u', 'a.user_id', '=', 'u.id')
                ->select('a.endpoint', 'a.method', 'a.status_code', 'a.response_time', 'a.created_at', 'a.user_id', 'u.name as user_name')
                ->where('a.created_at', '>=', $todayStart)
                ->orderByDesc('a.created_at')
                ->limit(100)
                ->get();

            // Last hour requests
            $stats['recent_requests_hour'] = DB::connection('pgsql')
                ->table('api_request_logs as a')
                ->leftJoin('users as u', 'a.user_id', '=', 'u.id')
                ->select('a.endpoint', 'a.method', 'a.status_code', 'a.response_time', 'a.created_at', 'a.user_id', 'u.name as user_name')
                ->where('a.created_at', '>=', $hourStart)
                ->orderByDesc('a.created_at')
                ->limit(100)
                ->get();

            // Error requests today
            $stats['error_requests_today'] = DB::connection('pgsql')
                ->table('api_request_logs as a')
                ->leftJoin('users as u', 'a.user_id', '=', 'u.id')
                ->select('a.endpoint', 'a.method', 'a.status_code', 'a.response_time', 'a.created_at', 'a.user_id', 'u.name as user_name')
                ->where('a.created_at', '>=', $todayStart)
                ->where('a.status_code', '>=', 400)
                ->orderByDesc('a.created_at')
                ->limit(100)
                ->get();

            // User-wise API usage stats
            $stats['user_stats'] = DB::connection('pgsql')
                ->table('api_request_logs as a')
                ->leftJoin('users as u', 'a.user_id', '=', 'u.id')
                ->select(
                    'a.user_id',
                    'u.name as user_name',
                    DB::raw('COUNT(*) as total_requests'),
                    DB::raw('COUNT(CASE WHEN a.status_code >= 400 THEN 1 END) as error_count'),
                    DB::raw('ROUND(AVG(a.response_time)::numeric, 2) as avg_response_time'),
                    DB::raw('MAX(a.created_at) as last_request')
                )
                ->where('a.created_at', '>=', $todayStart)
                ->groupBy('a.user_id', 'u.name')
                ->orderByDesc('total_requests')
                ->limit(15)
                ->get()
                ->map(function ($item) {
                    $item->user_name = $item->user_name ?? 'Guest/System';
                    $item->error_rate = $item->total_requests > 0 
                        ? round(($item->error_count / $item->total_requests) * 100, 2) 
                        : 0;
                    return $item;
                });

        } catch (\Exception $e) {
            Log::error('Failed to get API stats: ' . $e->getMessage());
            $stats['total_requests_today'] = 0;
            $stats['total_requests_hour'] = 0;
            $stats['error_count_today'] = 0;
            $stats['top_endpoints'] = [];
            $stats['avg_response_time'] = 0;
            $stats['recent_requests'] = [];
            $stats['recent_requests_hour'] = [];
            $stats['error_requests_today'] = [];
            $stats['user_stats'] = [];
        }

        // Error rate
        $stats['error_rate'] = $stats['total_requests_today'] > 0 
            ? round(($stats['error_count_today'] / $stats['total_requests_today']) * 100, 2) 
            : 0;

        // Log file size
        try {
            $logFile = storage_path('logs/laravel.log');
            if (file_exists($logFile)) {
                $stats['log_file_size'] = $this->formatBytes(filesize($logFile));
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return $stats;
    }

    /**
     * Get cache statistics
     */
    private function getCacheStats(): array
    {
        $stats = [
            'driver' => config('cache.default'),
        ];

        try {
            // Try to get cache info (works with Redis, Memcached)
            if (config('cache.default') === 'redis') {
                $redis = Cache::getStore()->getRedis();
                $info = $redis->info();
                
                $stats['redis'] = [
                    'version' => $info['redis_version'] ?? 'unknown',
                    'used_memory' => $info['used_memory'] ?? 0,
                    'used_memory_formatted' => $this->formatBytes($info['used_memory'] ?? 0),
                    'connected_clients' => $info['connected_clients'] ?? 0,
                    'total_commands_processed' => $info['total_commands_processed'] ?? 0,
                ];
            }
        } catch (\Exception $e) {
            $stats['status'] = 'unavailable';
        }

        return $stats;
    }

    /**
     * Get disk usage statistics
     */
    private function getDiskUsage(): array
    {
        $stats = [];

        $basePath = base_path();
        
        if (function_exists('disk_free_space') && function_exists('disk_total_space')) {
            $free = disk_free_space($basePath);
            $total = disk_total_space($basePath);
            $used = $total - $free;

            $stats = [
                'total' => $total,
                'total_formatted' => $this->formatBytes($total),
                'used' => $used,
                'used_formatted' => $this->formatBytes($used),
                'free' => $free,
                'free_formatted' => $this->formatBytes($free),
                'usage_percent' => round(($used / $total) * 100, 2),
            ];
        }

        // Storage directories
        $stats['storage'] = [
            'logs' => $this->getDirectorySize(storage_path('logs')),
            'cache' => $this->getDirectorySize(storage_path('framework/cache')),
            'sessions' => $this->getDirectorySize(storage_path('framework/sessions')),
        ];

        return $stats;
    }

    /**
     * Get process information
     */
    private function getProcessInfo(): array
    {
        $stats = [];

        // Get current process info
        if (function_exists('getrusage')) {
            $usage = getrusage();
            $stats['resource_usage'] = [
                'user_time' => $usage['ru_utime.tv_sec'] . '.' . $usage['ru_utime.tv_usec'],
                'system_time' => $usage['ru_stime.tv_sec'] . '.' . $usage['ru_stime.tv_usec'],
            ];
        }

        return $stats;
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Get directory size
     */
    private function getDirectorySize($path): array
    {
        if (!is_dir($path)) {
            return ['size' => 0, 'size_formatted' => '0 B', 'file_count' => 0];
        }

        $size = 0;
        $count = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                    $count++;
                }
            }
        } catch (\Exception $e) {
            // Ignore errors
        }

        return [
            'size' => $size,
            'size_formatted' => $this->formatBytes($size),
            'file_count' => $count,
        ];
    }

    /**
     * GET /api/system/health/user-requests/{userId}
     * 
     * Get paginated API requests for a specific user
     */
    public function getUserRequests(Request $request, $userId): JsonResponse
    {
        try {
            $perPage = $request->input('per_page', 50);
            $page = $request->input('page', 1);
            $todayStart = now()->startOfDay();

            // Get total count
            $total = DB::connection('pgsql')
                ->table('api_request_logs')
                ->where('user_id', $userId)
                ->where('created_at', '>=', $todayStart)
                ->count();

            // Get paginated requests
            $requests = DB::connection('pgsql')
                ->table('api_request_logs as a')
                ->leftJoin('users as u', 'a.user_id', '=', 'u.id')
                ->select('a.endpoint', 'a.method', 'a.status_code', 'a.response_time', 'a.created_at', 'a.user_id', 'u.name as user_name')
                ->where('a.user_id', $userId)
                ->where('a.created_at', '>=', $todayStart)
                ->orderByDesc('a.created_at')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'requests' => $requests,
                    'pagination' => [
                        'total' => $total,
                        'per_page' => $perPage,
                        'current_page' => $page,
                        'last_page' => ceil($total / $perPage),
                        'from' => (($page - 1) * $perPage) + 1,
                        'to' => min($page * $perPage, $total),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch user requests', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user requests',
            ], 500);
        }
    }
}
