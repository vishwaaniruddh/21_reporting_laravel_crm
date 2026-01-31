<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\DatabaseStatusController;
use App\Http\Controllers\ErrorQueueController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ConfigurationController;
use App\Http\Controllers\TableSyncConfigurationController;
use App\Http\Controllers\TableSyncController;
use App\Http\Controllers\AlertsReportController;
use App\Http\Controllers\VMAlertController;
use App\Http\Controllers\PostgresDashboardController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Authentication routes
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    
    // Protected auth routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// Profile Management
Route::prefix('profile')->middleware('auth:sanctum')->group(function () {
    // GET /api/profile - get current user profile
    Route::get('/', [\App\Http\Controllers\ProfileController::class, 'show']);
    
    // PUT /api/profile - update profile information
    Route::put('/', [\App\Http\Controllers\ProfileController::class, 'update']);
    
    // PUT /api/profile/password - change password
    Route::put('password', [\App\Http\Controllers\ProfileController::class, 'updatePassword']);
    
    // POST /api/profile/image - upload profile image
    Route::post('image', [\App\Http\Controllers\ProfileController::class, 'uploadImage']);
    
    // DELETE /api/profile/image - remove profile image
    Route::delete('image', [\App\Http\Controllers\ProfileController::class, 'removeImage']);
});

// Database status and routing endpoints
Route::prefix('database')->group(function () {
    Route::get('status', [DatabaseStatusController::class, 'index']);
    Route::get('health', [DatabaseStatusController::class, 'health']);
    Route::get('status/{database}', [DatabaseStatusController::class, 'show']);
    Route::get('route/{operation}', [DatabaseStatusController::class, 'route']);
});

// Dashboard statistics
Route::prefix('dashboard')->middleware(['auth:sanctum', 'permission:dashboard.view'])->group(function () {
    // GET /api/dashboard/stats - get dashboard statistics
    Route::get('stats', [\App\Http\Controllers\DashboardController::class, 'stats']);
    
    // Executive Dashboard - 20 Data Points with Real-time Updates
    // GET /api/dashboard/executive - comprehensive executive metrics
    Route::get('executive', [\App\Http\Controllers\ExecutiveDashboardController::class, 'index']);
    
    // PostgreSQL Dashboard - Alert Count Distribution (Requirements: 8.1, 8.3, 11.1, 11.2)
    Route::prefix('postgres')->group(function () {
        // GET /api/dashboard/postgres/data - fetch alert distribution
        Route::get('data', [PostgresDashboardController::class, 'data']);
        
        // GET /api/dashboard/postgres/details - fetch alert details
        Route::get('details', [PostgresDashboardController::class, 'details']);
    });
});

// User operations with RBAC protection (Requirements 9.1-9.6)
Route::prefix('users')->middleware(['auth:sanctum'])->group(function () {
    // GET /api/users - list users (filtered by role permissions)
    Route::get('/', [UserController::class, 'index'])->middleware('permission:users.read');
    
    // POST /api/users - create new users
    Route::post('/', [UserController::class, 'store'])->middleware('permission:users.create');
    
    // GET /api/users/{id} - get user details
    Route::get('{id}', [UserController::class, 'show'])->middleware('permission:users.read');
    
    // PUT/PATCH /api/users/{id} - update user details
    Route::put('{id}', [UserController::class, 'update'])->middleware('permission:users.update');
    Route::patch('{id}', [UserController::class, 'update'])->middleware('permission:users.update');
    
    // DELETE /api/users/{id} - deactivate users
    Route::delete('{id}', [UserController::class, 'destroy'])->middleware('permission:users.delete');
    
    // Database status endpoint
    Route::get('database/status', [UserController::class, 'status']);
});

// Role operations with RBAC protection (Requirement 9.5)
Route::prefix('roles')->middleware(['auth:sanctum'])->group(function () {
    // GET /api/roles - list available roles
    Route::get('/', [RoleController::class, 'index'])->middleware('permission:roles.read');
    
    // POST /api/roles/{role}/permissions - update role permissions
    Route::post('/{role}/permissions', [RoleController::class, 'updatePermissions'])->middleware('permission:permissions.assign');
});

// Permission operations with RBAC protection (Requirement 9.6)
Route::prefix('permissions')->middleware(['auth:sanctum'])->group(function () {
    // GET /api/permissions - list available permissions
    Route::get('/', [PermissionController::class, 'index'])->middleware('permission:permissions.read');
});

// Analytics operations (PostgreSQL database)
Route::prefix('analytics')->group(function () {
    Route::get('/', [AnalyticsController::class, 'index']);
    Route::post('/', [AnalyticsController::class, 'store']);
    Route::get('summary', [AnalyticsController::class, 'summary']);
    Route::get('{id}', [AnalyticsController::class, 'show']);
    Route::put('{id}', [AnalyticsController::class, 'update']);
    Route::patch('{id}', [AnalyticsController::class, 'update']);
    Route::delete('{id}', [AnalyticsController::class, 'destroy']);
    Route::get('database/status', [AnalyticsController::class, 'status']);
});

// Health check endpoint
Route::get('health', function () {
    return response()->json([
        'success' => true,
        'message' => 'API is running',
        'timestamp' => now()->toISOString()
    ]);
});

// Server time endpoint - returns current server date/time
Route::get('server-time', function () {
    return response()->json([
        'success' => true,
        'date' => now()->toDateString(), // YYYY-MM-DD
        'time' => now()->toTimeString(), // HH:MM:SS
        'datetime' => now()->toDateTimeString(), // YYYY-MM-DD HH:MM:SS
        'timezone' => config('app.timezone'),
        'timestamp' => now()->timestamp,
    ]);
});

// Pipeline error queue management (Requirements: 7.5)
Route::prefix('pipeline/error-queue')->middleware(['auth:sanctum'])->group(function () {
    // GET /api/pipeline/error-queue/stats - get error queue statistics
    Route::get('stats', [ErrorQueueController::class, 'statistics']);
    
    // GET /api/pipeline/error-queue - list failed records with pagination
    Route::get('/', [ErrorQueueController::class, 'index']);
    
    // GET /api/pipeline/error-queue/review - get records requiring manual review
    Route::get('review', [ErrorQueueController::class, 'requiresReview']);
    
    // POST /api/pipeline/error-queue/retry-all - retry all eligible records
    Route::post('retry-all', [ErrorQueueController::class, 'retryAll']);
    
    // DELETE /api/pipeline/error-queue/cleanup - clean up old resolved/ignored records
    Route::delete('cleanup', [ErrorQueueController::class, 'cleanup']);
    
    // GET /api/pipeline/error-queue/{id} - get a single failed record
    Route::get('{id}', [ErrorQueueController::class, 'show']);
    
    // POST /api/pipeline/error-queue/{id}/retry - retry a single failed record
    Route::post('{id}/retry', [ErrorQueueController::class, 'retry']);
    
    // POST /api/pipeline/error-queue/{id}/force-retry - force retry a record
    Route::post('{id}/force-retry', [ErrorQueueController::class, 'forceRetry']);
    
    // POST /api/pipeline/error-queue/{id}/resolve - mark record as resolved
    Route::post('{id}/resolve', [ErrorQueueController::class, 'resolve']);
    
    // POST /api/pipeline/error-queue/{id}/ignore - mark record as ignored
    Route::post('{id}/ignore', [ErrorQueueController::class, 'ignore']);
});

// Pipeline monitoring and control (Requirements: 2.2, 2.3)
Route::prefix('pipeline')->group(function () {
    // GET /api/pipeline/status - current pipeline status (public for monitoring)
    Route::get('status', [PipelineController::class, 'status']);
    
    // GET /api/pipeline/sync-logs - sync history with pagination
    Route::get('sync-logs', [PipelineController::class, 'syncLogs']);
    
    // Protected pipeline control endpoints
    Route::middleware(['auth:sanctum'])->group(function () {
        // POST /api/pipeline/sync/trigger - manually trigger sync
        Route::post('sync/trigger', [PipelineController::class, 'triggerSync']);
        
        // GET /api/pipeline/cleanup/preview - preview cleanup without executing
        Route::get('cleanup/preview', [PipelineController::class, 'cleanupPreview']);
        
        // POST /api/pipeline/cleanup/trigger - manually trigger cleanup (requires admin + confirmation)
        Route::post('cleanup/trigger', [PipelineController::class, 'triggerCleanup'])
            ->middleware('role:superadmin');
    });
});

// Reports from PostgreSQL (Requirements: 5.1, 5.2, 5.3, 5.4)
// ⚠️ All reports query PostgreSQL ONLY - never MySQL alerts
Route::prefix('reports')->group(function () {
    // GET /api/reports/daily - daily report (NEW - comprehensive daily breakdown)
    Route::get('daily', [\App\Http\Controllers\DailyReportController::class, 'index'])
        ->middleware(['auth:sanctum', 'permission:reports.view']);
    
    // GET /api/reports/weekly - weekly report (NEW - comprehensive weekly breakdown)
    Route::get('weekly', [\App\Http\Controllers\WeeklyReportController::class, 'index'])
        ->middleware(['auth:sanctum', 'permission:reports.view']);
    
    // GET /api/reports/monthly - monthly report (NEW - comprehensive monthly breakdown)
    Route::get('monthly', [\App\Http\Controllers\MonthlyReportController::class, 'index'])
        ->middleware(['auth:sanctum', 'permission:reports.view']);
    
    // GET /api/reports/summary - summary report with filters
    Route::get('summary', [ReportController::class, 'summary']);
    
    // GET /api/reports/alerts - filtered alerts with pagination
    Route::get('alerts', [ReportController::class, 'alerts']);
    
    // GET /api/reports/statistics - statistics only
    Route::get('statistics', [ReportController::class, 'statistics']);
    
    // GET /api/reports/filter-options - available filter options
    Route::get('filter-options', [ReportController::class, 'filterOptions']);
    
    // GET /api/reports/export/csv - CSV export
    Route::get('export/csv', [ReportController::class, 'exportCsv']);
    
    // GET /api/reports/export/pdf - PDF export
    Route::get('export/pdf', [ReportController::class, 'exportPdf']);
});

// Pipeline configuration management (Requirements: 6.1, 6.2, 6.3, 6.4, 6.5)
Route::prefix('config/pipeline')->group(function () {
    // GET /api/config/pipeline - get current configuration (public for monitoring)
    Route::get('/', [ConfigurationController::class, 'show']);
    
    // GET /api/config/pipeline/schedules - get schedule configuration
    Route::get('schedules', [ConfigurationController::class, 'schedules']);
    
    // GET /api/config/pipeline/alerts - get alert threshold configuration
    Route::get('alerts', [ConfigurationController::class, 'alerts']);
    
    // Protected configuration endpoints (require authentication)
    Route::middleware(['auth:sanctum'])->group(function () {
        // PUT /api/config/pipeline - update configuration
        Route::put('/', [ConfigurationController::class, 'update'])
            ->middleware('role:superadmin,admin');
        
        // POST /api/config/pipeline/reset - reset configuration to defaults
        Route::post('reset', [ConfigurationController::class, 'reset'])
            ->middleware('role:superadmin');
    });
});


// Table Sync Configuration and Operations (Requirements: 5.1, 5.6, 8.1, 8.2, 8.3)
// ⚠️ NO DELETION FROM MYSQL: All operations read from MySQL, write to PostgreSQL
Route::prefix('table-sync')->group(function () {
    // Public endpoints for monitoring
    // GET /api/table-sync/overview - overview of all table syncs
    Route::get('overview', [TableSyncController::class, 'overview'])
        ->middleware(['auth:sanctum', 'permission:table-sync.view']);
    
    // GET /api/table-sync/logs - sync logs with filters
    Route::get('logs', [TableSyncController::class, 'logs'])
        ->middleware(['auth:sanctum', 'permission:table-sync.view']);
    
    // Protected endpoints (require authentication and permissions)
    Route::middleware(['auth:sanctum', 'permission:table-sync.view'])->group(function () {
        // Configuration CRUD endpoints
        Route::prefix('configurations')->group(function () {
            // GET /api/table-sync/configurations - list all configurations
            Route::get('/', [TableSyncConfigurationController::class, 'index']);
            
            // POST /api/table-sync/configurations - create new configuration
            Route::post('/', [TableSyncConfigurationController::class, 'store'])
                ->middleware('permission:table-sync.manage');
            
            // POST /api/table-sync/configurations/test - test configuration without creating
            Route::post('test', [TableSyncConfigurationController::class, 'test'])
                ->middleware('permission:table-sync.manage');
            
            // GET /api/table-sync/configurations/{id} - get single configuration
            Route::get('{id}', [TableSyncConfigurationController::class, 'show']);
            
            // PUT /api/table-sync/configurations/{id} - update configuration
            Route::put('{id}', [TableSyncConfigurationController::class, 'update'])
                ->middleware('permission:table-sync.manage');
            
            // DELETE /api/table-sync/configurations/{id} - delete configuration (from PostgreSQL only)
            Route::delete('{id}', [TableSyncConfigurationController::class, 'destroy'])
                ->middleware('permission:table-sync.manage');
            
            // POST /api/table-sync/configurations/{id}/enable - enable configuration
            Route::post('{id}/enable', [TableSyncConfigurationController::class, 'enable'])
                ->middleware('permission:table-sync.manage');
            
            // POST /api/table-sync/configurations/{id}/disable - disable configuration
            Route::post('{id}/disable', [TableSyncConfigurationController::class, 'disable'])
                ->middleware('permission:table-sync.manage');
            
            // POST /api/table-sync/configurations/{id}/duplicate - duplicate configuration
            Route::post('{id}/duplicate', [TableSyncConfigurationController::class, 'duplicate'])
                ->middleware('permission:table-sync.manage');
        });
        
        // Sync operation endpoints
        // POST /api/table-sync/sync/{id} - trigger sync for specific table
        Route::post('sync/{id}', [TableSyncController::class, 'sync'])
            ->middleware('permission:table-sync.manage');
        
        // POST /api/table-sync/sync-all - trigger sync for all enabled tables
        Route::post('sync-all', [TableSyncController::class, 'syncAll'])
            ->middleware('permission:table-sync.manage');
        
        // GET /api/table-sync/status/{id} - get sync status for table
        Route::get('status/{id}', [TableSyncController::class, 'status']);
        
        // POST /api/table-sync/{id}/resume - resume paused sync
        Route::post('{id}/resume', [TableSyncController::class, 'resume'])
            ->middleware('permission:table-sync.manage');
        
        // POST /api/table-sync/{id}/force-unlock - force unlock a stuck sync
        Route::post('{id}/force-unlock', [TableSyncController::class, 'forceUnlock'])
            ->middleware('permission:table-sync.manage');
        
        // POST /api/table-sync/force-unlock-all - force unlock all stuck syncs
        Route::post('force-unlock-all', [TableSyncController::class, 'forceUnlockAll'])
            ->middleware('permission:table-sync.manage');
        
        // Error queue endpoints
        Route::prefix('errors')->group(function () {
            // GET /api/table-sync/errors - get error queue with filters
            Route::get('/', [TableSyncController::class, 'errors']);
            
            // POST /api/table-sync/errors/retry-all - retry all eligible errors
            Route::post('retry-all', [TableSyncController::class, 'retryAllErrors'])
                ->middleware('permission:table-sync.manage');
            
            // POST /api/table-sync/errors/{id}/retry - retry specific error
            Route::post('{id}/retry', [TableSyncController::class, 'retryError'])
                ->middleware('permission:table-sync.manage');
            
            // POST /api/table-sync/errors/{id}/resolve - mark error as resolved
            Route::post('{id}/resolve', [TableSyncController::class, 'resolveError'])
                ->middleware('permission:table-sync.manage');
        });
    });
});


// Alerts Reports from PostgreSQL - 27 columns with sites JOIN
// All queries run against PostgreSQL alerts table only
Route::prefix('alerts-reports')->group(function () {
    // Protected routes that require authentication
    Route::middleware(['auth:sanctum', 'permission:reports.view'])->group(function () {
        // GET /api/alerts-reports - paginated alerts with 27 columns
        Route::get('/', [AlertsReportController::class, 'index']);
        
        // GET /api/alerts-reports/filter-options - customers and panel types
        Route::get('filter-options', [AlertsReportController::class, 'filterOptions']);
        
        // GET /api/alerts-reports/check-csv - check if pre-generated CSV exists
        Route::get('check-csv', [AlertsReportController::class, 'checkCsvReport']);
        
        // GET /api/alerts-reports/excel-check - check if Excel report exists for date
        Route::get('excel-check', [AlertsReportController::class, 'checkExcelReport']);
        
        // POST /api/alerts-reports/excel-generate - manually generate Excel report
        Route::post('excel-generate', [AlertsReportController::class, 'generateExcelReport']);
    });
    
    // POST /api/alerts-reports/export/csv/token - generate download token
    Route::post('export/csv/token', [AlertsReportController::class, 'generateExportToken'])
        ->middleware(['auth:sanctum', 'permission:reports.view']);
    
    // CSV export - supports both token-based (no auth) and authenticated requests
    // Uses close.session middleware to prevent blocking other requests
    Route::get('export/csv', [AlertsReportController::class, 'exportCsv'])
        ->middleware('close.session');
});

// Recent Alerts from MySQL - Last 15 Minutes (READ-ONLY)
// Queries MySQL alerts table directly - NO UPDATES OR DELETES
Route::prefix('recent-alerts')->group(function () {
    Route::middleware(['auth:sanctum', 'permission:reports.view'])->group(function () {
        // GET /api/recent-alerts - alerts from last 15 minutes
        Route::get('/', [\App\Http\Controllers\RecentAlertsController::class, 'index']);
        
        // GET /api/recent-alerts/filter-options - available filter options
        Route::get('filter-options', [\App\Http\Controllers\RecentAlertsController::class, 'filterOptions']);
    });
});

// VM Alerts Reports from PostgreSQL - 27 columns with sites JOIN
// All queries run against PostgreSQL alerts table only (VM-specific filtering)
Route::prefix('vm-alerts')->group(function () {
    // Protected routes that require authentication
    Route::middleware(['auth:sanctum', 'permission:reports.view'])->group(function () {
        // GET /api/vm-alerts - paginated VM alerts with 27 columns
        Route::get('/', [VMAlertController::class, 'index']);
        
        // GET /api/vm-alerts/filter-options - customers and panel types
        Route::get('filter-options', [VMAlertController::class, 'filterOptions']);
        
        // GET /api/vm-alerts/check-csv - check if pre-generated CSV exists
        Route::get('check-csv', [VMAlertController::class, 'checkCsvReport']);
        
        // GET /api/vm-alerts/excel-check - check if Excel report exists for date
        Route::get('excel-check', [VMAlertController::class, 'checkExcelReport']);
        
        // POST /api/vm-alerts/excel-generate - manually generate Excel report
        Route::post('excel-generate', [VMAlertController::class, 'generateExcelReport']);
    });
    
    // POST /api/vm-alerts/export/csv/token - generate download token
    Route::post('export/csv/token', [VMAlertController::class, 'generateExportToken'])
        ->middleware(['auth:sanctum', 'permission:reports.view']);
    
    // CSV export - supports both token-based (no auth) and authenticated requests
    // Uses close.session middleware to prevent blocking other requests
    Route::get('export/csv', [VMAlertController::class, 'exportCsv'])
        ->middleware('close.session');
});

// RMS Sites Management
Route::prefix('rms-sites')->group(function () {
    // Protected routes that require authentication
    Route::middleware(['auth:sanctum', 'permission:sites.rms'])->group(function () {
        // GET /api/rms-sites - paginated RMS sites list
        Route::get('/', [\App\Http\Controllers\RMSSitesController::class, 'index']);
        
        // GET /api/rms-sites/filter-options - get filter dropdown options
        Route::get('filter-options', [\App\Http\Controllers\RMSSitesController::class, 'filterOptions']);
        
        // GET /api/rms-sites/export/csv - export to CSV
        Route::get('export/csv', [\App\Http\Controllers\RMSSitesController::class, 'exportCsv']);
    });
});

// DVR Sites Management
Route::prefix('dvr-sites')->group(function () {
    // Protected routes that require authentication
    Route::middleware(['auth:sanctum', 'permission:sites.dvr'])->group(function () {
        // GET /api/dvr-sites - paginated DVR sites list
        Route::get('/', [\App\Http\Controllers\DVRSitesController::class, 'index']);
        
        // GET /api/dvr-sites/filter-options - get filter dropdown options
        Route::get('filter-options', [\App\Http\Controllers\DVRSitesController::class, 'filterOptions']);
        
        // GET /api/dvr-sites/export/csv - export to CSV
        Route::get('export/csv', [\App\Http\Controllers\DVRSitesController::class, 'exportCsv']);
    });
});

// Date-Partitioned Sync Management (Requirements: 5.1, 6.1, 9.4, 10.1)
// ⚠️ NO DELETION FROM MYSQL: All operations read from MySQL, write to PostgreSQL partitions
Route::prefix('sync')->group(function () {
    // Public endpoints for monitoring
    // GET /api/sync/partitions - list all partition tables
    Route::get('partitions', [\App\Http\Controllers\PartitionController::class, 'listPartitions'])
        ->middleware(['auth:sanctum', 'permission:partitions.view']);
    
    // GET /api/sync/partitions/{date} - get partition info for specific date
    Route::get('partitions/{date}', [\App\Http\Controllers\PartitionController::class, 'getPartitionInfo'])
        ->middleware(['auth:sanctum', 'permission:partitions.view']);
    
    // Protected endpoints (require authentication and manage permission)
    Route::middleware(['auth:sanctum', 'permission:partitions.manage'])->group(function () {
        // POST /api/sync/partitioned/trigger - trigger date-partitioned sync
        Route::post('partitioned/trigger', [\App\Http\Controllers\PartitionController::class, 'triggerSync']);
    });
});

// Partitioned Reports from PostgreSQL (Requirements: 6.1, 10.1)
// Query across date-partitioned alert tables
Route::prefix('reports/partitioned')->group(function () {
    // GET /api/reports/partitioned/query - query across date partitions
    Route::get('query', [\App\Http\Controllers\PartitionController::class, 'queryPartitions'])
        ->middleware(['auth:sanctum', 'permission:partitions.view']);
});

// Service Management (Windows NSSM Services)
Route::prefix('services')->middleware(['auth:sanctum', 'permission:services.manage'])->group(function () {
    // GET /api/services - list all managed services with status
    Route::get('/', [\App\Http\Controllers\ServiceManagementController::class, 'index']);
    
    // POST /api/services/start - start a service
    Route::post('start', [\App\Http\Controllers\ServiceManagementController::class, 'start']);
    
    // POST /api/services/stop - stop a service
    Route::post('stop', [\App\Http\Controllers\ServiceManagementController::class, 'stop']);
    
    // POST /api/services/restart - restart a service
    Route::post('restart', [\App\Http\Controllers\ServiceManagementController::class, 'restart']);
    
    // GET /api/services/logs - get service logs
    Route::get('logs', [\App\Http\Controllers\ServiceManagementController::class, 'logs']);
});

// System Health Monitoring
Route::prefix('system')->middleware(['auth:sanctum', 'permission:system.view'])->group(function () {
    // GET /api/system/health - comprehensive system health metrics
    Route::get('health', [\App\Http\Controllers\SystemHealthController::class, 'index']);
    
    // GET /api/system/health/user-requests/{userId} - get paginated API requests for a specific user
    Route::get('health/user-requests/{userId}', [\App\Http\Controllers\SystemHealthController::class, 'getUserRequests']);
    
    // GET /api/system/logs/{filename} - read a specific log file
    Route::get('logs/{filename}', [\App\Http\Controllers\SystemHealthController::class, 'readLog']);
    
    // DELETE /api/system/logs/{filename} - clear a specific log file
    Route::delete('logs/{filename}', [\App\Http\Controllers\SystemHealthController::class, 'clearLog']);
});

// Down Communication Report
Route::prefix('down-communication')->group(function () {
    // Protected routes that require authentication
    Route::middleware(['auth:sanctum', 'permission:reports.view'])->group(function () {
        // GET /api/down-communication - paginated down communication report
        Route::get('/', [\App\Http\Controllers\DownCommunicationController::class, 'index']);
        
        // GET /api/down-communication/filter-options - get filter dropdown options
        Route::get('filter-options', [\App\Http\Controllers\DownCommunicationController::class, 'filterOptions']);
        
        // GET /api/down-communication/export/csv - export to CSV
        Route::get('export/csv', [\App\Http\Controllers\DownCommunicationController::class, 'exportCsv']);
    });
});

// Downloads - Centralized partition-based downloads
Route::prefix('downloads')->group(function () {
    // Protected routes that require authentication
    Route::middleware(['auth:sanctum', 'permission:reports.view'])->group(function () {
        // GET /api/downloads/partitions - get available partitions with record counts
        Route::get('partitions', [\App\Http\Controllers\DownloadsController::class, 'getPartitions']);
        
        // Queue-based CSV exports (prevents portal blocking)
        // POST /api/downloads/request - request a CSV export (queued for background processing)
        Route::post('request', [\App\Http\Controllers\DownloadsController::class, 'requestExport']);
        
        // GET /api/downloads/status/{jobId} - check export job status
        Route::get('status/{jobId}', [\App\Http\Controllers\DownloadsController::class, 'checkStatus']);
        
        // GET /api/downloads/file/{jobId} - download completed export file
        Route::get('file/{jobId}', [\App\Http\Controllers\DownloadsController::class, 'downloadFile']);
        
        // GET /api/downloads/my-exports - get user's export history
        Route::get('my-exports', [\App\Http\Controllers\DownloadsController::class, 'myExports']);
        
        // DELETE /api/downloads/delete/{jobId} - delete an export job and its file
        Route::delete('delete/{jobId}', [\App\Http\Controllers\DownloadsController::class, 'deleteExport']);
    });
});

