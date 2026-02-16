<?php

namespace App\Http\Controllers;

use App\Services\PartitionQueryRouter;
use App\Services\ExcelReportService;
use App\Services\CsvReportService;
use Carbon\Carbon;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * AlertsReportController - Reports from PostgreSQL alerts table
 * 
 * Provides paginated alerts data with filtering and export capabilities.
 * All queries run against PostgreSQL only.
 * 
 * Now supports both single-table and date-partitioned queries through
 * the PartitionQueryRouter for improved performance on date-range queries.
 * 
 * Matches exact 26-column format from reference.
 * 
 * Requirements: 10.1, 10.5
 */
class AlertsReportController extends Controller
{
    /**
     * PartitionQueryRouter for cross-partition queries
     */
    private PartitionQueryRouter $partitionRouter;
    
    /**
     * Create a new AlertsReportController instance
     * 
     * @param PartitionQueryRouter|null $partitionRouter Optional PartitionQueryRouter instance
     */
    public function __construct(?PartitionQueryRouter $partitionRouter = null)
    {
        $this->partitionRouter = $partitionRouter ?? new PartitionQueryRouter();
    }
    /**
     * GET /api/alerts-reports
     * 
     * Get paginated alerts with optional filters - 26 columns with sites JOIN.
     * Uses PartitionQueryRouter when date range is specified for improved performance.
     * 
     * Requirements: 10.1, 10.5
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:10|max:100',
                'panelid' => 'nullable|string|max:255',
                'dvrip' => 'nullable|string|max:255',
                'customer' => 'nullable|string|max:255',
                'panel_type' => 'nullable|string|max:255',
                'atmid' => 'nullable|string|max:255',
                'from_date' => 'required|date',
            ]);

            $perPage = $validated['per_page'] ?? 25;
            $page = $validated['page'] ?? 1;
            
            // Parse date filter
            $fromDate = Carbon::parse($validated['from_date'])->startOfDay();
            $toDate = $fromDate->copy()->endOfDay();
            
            // Always use partition router (no single alerts table exists)
            $result = $this->getAlertsViaRouter($fromDate, $toDate, $validated, $perPage, $page);
            
            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch alerts', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FETCH_ERROR',
                    'message' => 'Failed to fetch alerts',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }
    
    /**
     * Determine if partition router should be used
     * 
     * Requirements: 10.1, 10.5
     * 
     * @param Carbon|null $startDate Start date
    /**
     * Get alerts via partition router
     * 
     * Requirements: 10.1, 10.5
     * 
     * @param Carbon $startDate Start date
     * @param Carbon $endDate End date
     * @param array $validated Validated request data
     * @param int $perPage Items per page
     * @param int $page Page number
     * @return array
     */
    protected function getAlertsViaRouter(
        Carbon $startDate,
        Carbon $endDate,
        array $validated,
        int $perPage,
        int $page
    ): array {
        try {
            // Build filter array for partition router
            $filters = [];
            
            if (!empty($validated['panelid'])) {
                $filters['panel_id'] = $validated['panelid'];
            }
            
            // For sites-based filters, get panel IDs first
            $panelIds = $this->getPanelIdsFromSitesFilters($validated);
            
            if ($panelIds !== null) {
                if (empty($panelIds)) {
                    // No matching sites, return empty
                    return [
                        'alerts' => [],
                        'pagination' => [
                            'current_page' => 1,
                            'last_page' => 1,
                            'per_page' => $perPage,
                            'total' => 0,
                            'from' => null,
                            'to' => null
                        ],
                        'total_count' => 0,
                    ];
                }
                
                // Use panel IDs filter with partition router
                $filters['panel_ids'] = $panelIds;
            }
            
            // Query via partition router with pagination - INCLUDE BOTH ALERTS AND BACKALERTS
            $result = $this->partitionRouter->queryWithPagination(
                $startDate,
                $endDate,
                $filters,
                $perPage,
                $page,
                ['alerts', 'backalerts'] // Query both table types
            );
            
            // Convert to array format
            $alerts = collect($result['data'])->map(function($record) {
                return (array) $record;
            })->toArray();
            
            // Enrich with sites data
            $enrichedAlerts = $this->enrichWithSites($alerts);
            
            return [
                'alerts' => $enrichedAlerts,
                'pagination' => $result['pagination'],
                'total_count' => $result['pagination']['total'],
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to get alerts via partition router', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Return empty result instead of falling back
            return [
                'alerts' => [],
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                    'from' => null,
                    'to' => null
                ],
                'total_count' => 0,
            ];
        }
    }
    
    /**
     * Get panel IDs from sites-based filters
     * 
     * @param array $validated Validated request data
     * @return array|null Array of panel IDs, or null if no sites filters applied
     */
    protected function getPanelIdsFromSitesFilters(array $validated): ?array
    {
        // Check if any sites-based filters are present
        if (empty($validated['dvrip']) && empty($validated['customer']) && 
            empty($validated['panel_type']) && empty($validated['atmid'])) {
            return null;
        }
        
        $sitesQuery = DB::connection('pgsql')->table('sites');
        
        if (!empty($validated['dvrip'])) {
            $sitesQuery->where('DVRIP', 'ilike', '%' . $validated['dvrip'] . '%');
        }
        if (!empty($validated['customer'])) {
            $sitesQuery->where('Customer', $validated['customer']);
        }
        if (!empty($validated['panel_type'])) {
            $sitesQuery->where('Panel_Make', $validated['panel_type']);
        }
        if (!empty($validated['atmid'])) {
            $sitesQuery->where('ATMID', 'ilike', '%' . $validated['atmid'] . '%');
        }

        $panelIds = $sitesQuery->select('OldPanelID', 'NewPanelID')->get();
        return $panelIds->pluck('OldPanelID')->merge($panelIds->pluck('NewPanelID'))->filter()->unique()->values()->toArray();
    }
    
    /**
    /**
     * Enrich alerts with sites data
     * 
     * Handles both object and array formats for compatibility with partition router.
     */
    private function enrichWithSites($alerts)
    {
        if (empty($alerts)) return $alerts;

        // Get unique panel IDs (handle both object and array formats)
        $panelIds = collect($alerts)->map(function($alert) {
            if (is_object($alert)) {
                return $alert->panelid ?? null;
            }
            return $alert['panelid'] ?? null;
        })->unique()->filter()->values()->toArray();
        
        if (empty($panelIds)) return $alerts;

        // Fetch sites data for these panels - FIX: Properly group OR conditions
        $sites = DB::connection('pgsql')
            ->table('sites')
            ->where(function($query) use ($panelIds) {
                $query->whereIn('OldPanelID', $panelIds)
                      ->orWhereIn('NewPanelID', $panelIds);
            })
            ->select(['OldPanelID', 'NewPanelID', 'Customer', 'Zone', 'ATMID', 'SiteAddress', 'City', 'State', 'DVRIP', 'Panel_Make', 'Bank'])
            ->get();

        // Create lookup by panel ID
        $siteLookup = [];
        foreach ($sites as $site) {
            if ($site->OldPanelID) $siteLookup[$site->OldPanelID] = $site;
            if ($site->NewPanelID) $siteLookup[$site->NewPanelID] = $site;
        }

        // Enrich alerts (handle both object and array formats)
        return collect($alerts)->map(function($alert) use ($siteLookup) {
            // Convert to object if it's an array
            if (is_array($alert)) {
                $alert = (object) $alert;
            }
            
            $site = $siteLookup[$alert->panelid] ?? null;
            $alert->Customer = $site->Customer ?? null;
            $alert->site_zone = $site->Zone ?? null;
            $alert->ATMID = $site->ATMID ?? null;
            $alert->SiteAddress = $site->SiteAddress ?? null;
            $alert->City = $site->City ?? null;
            $alert->State = $site->State ?? null;
            $alert->DVRIP = $site->DVRIP ?? null;
            $alert->Panel_Make = $site->Panel_Make ?? null;
            $alert->Bank = $site->Bank ?? null;
            $alert->testing_by_service_team = '';
            $alert->testing_remark = '';
            
            // Calculate aging: closedtime - receivedtime (in HH:MM:SS format)
            $alert->aging = $this->calculateAging($alert->closedtime ?? null, $alert->receivedtime ?? null);
            
            return $alert;
        })->toArray();
    }
    
    /**
     * Calculate aging between closed and received time
     * 
     * @param string|null $closedtime Closed timestamp
     * @param string|null $receivedtime Received timestamp
     * @return string Aging in HH:MM:SS format or 'NA' if closed is blank
     */
    private function calculateAging($closedtime, $receivedtime)
    {
        // If closedtime is blank/null, return NA
        if (empty($closedtime)) {
            return 'NA';
        }
        
        // If receivedtime is blank/null, return NA
        if (empty($receivedtime)) {
            return 'NA';
        }
        
        try {
            $closed = new DateTime($closedtime);
            $received = new DateTime($receivedtime);
            
            // Calculate difference in seconds
            $diffSeconds = $closed->getTimestamp() - $received->getTimestamp();
            
            // Handle negative values (if closed is before received)
            if ($diffSeconds < 0) {
                return 'NA';
            }
            
            // Convert to hours, minutes, seconds
            $hours = floor($diffSeconds / 3600);
            $minutes = floor(($diffSeconds % 3600) / 60);
            $seconds = $diffSeconds % 60;
            
            // Format as HH:MM:SS
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        } catch (\Exception $e) {
            return 'NA';
        }
    }

    /**
     * GET /api/alerts-reports/excel-check
     * 
     * Check if Excel report exists for a date and get download URL
     */
    public function checkExcelReport(Request $request, ExcelReportService $excelService): JsonResponse
    {
        try {
            $validated = $request->validate([
                'date' => 'required|date',
                'panel_type' => 'nullable|string|max:255',
                'customer' => 'nullable|string|max:255',
            ]);
            
            $date = Carbon::parse($validated['date']);
            
            // Check if date is today or future
            if ($date->isToday() || $date->isFuture()) {
                return response()->json([
                    'success' => true,
                    'exists' => false,
                    'message' => 'Excel reports are only available for past dates',
                ]);
            }
            
            // Build filters
            $filters = [];
            if (!empty($validated['panel_type'])) {
                $filters['panel_type'] = $validated['panel_type'];
            }
            if (!empty($validated['customer'])) {
                $filters['customer'] = $validated['customer'];
            }
            
            // Check if report exists
            $exists = $excelService->reportExists($date, $filters);
            $url = $exists ? $excelService->getReportUrl($date, $filters) : null;
            
            return response()->json([
                'success' => true,
                'exists' => $exists,
                'url' => $url,
                'date' => $date->toDateString(),
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to check Excel report', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CHECK_ERROR',
                    'message' => 'Failed to check Excel report',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }
    
    /**
     * GET /api/alerts-reports/excel-generate
     * 
     * Generate Excel report for a specific date (manual trigger)
     */
    public function generateExcelReport(Request $request, ExcelReportService $excelService): JsonResponse
    {
        try {
            $validated = $request->validate([
                'date' => 'required|date',
                'panel_type' => 'nullable|string|max:255',
                'customer' => 'nullable|string|max:255',
            ]);
            
            $date = Carbon::parse($validated['date']);
            
            // Check if date is today or future
            if ($date->isToday() || $date->isFuture()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVALID_DATE',
                        'message' => 'Excel reports can only be generated for past dates',
                    ],
                ], 400);
            }
            
            // Build filters
            $filters = [];
            if (!empty($validated['panel_type'])) {
                $filters['panel_type'] = $validated['panel_type'];
            }
            if (!empty($validated['customer'])) {
                $filters['customer'] = $validated['customer'];
            }
            
            // Generate report
            $filepath = $excelService->generateReport($date, $filters);
            $url = $excelService->getReportUrl($date, $filters);
            
            return response()->json([
                'success' => true,
                'message' => 'Excel report generated successfully',
                'url' => $url,
                'date' => $date->toDateString(),
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to generate Excel report', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'GENERATION_ERROR',
                    'message' => 'Failed to generate Excel report',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }
    
    /**
     * GET /api/alerts-reports/check-csv
     * 
     * Check if pre-generated CSV report exists for a date
     */
    public function checkCsvReport(Request $request, CsvReportService $csvService): JsonResponse
    {
        try {
            $validated = $request->validate([
                'date' => 'required|date',
            ]);
            
            $date = Carbon::parse($validated['date']);
            
            // Check if date is today or future
            if ($date->isToday() || $date->isFuture()) {
                return response()->json([
                    'success' => true,
                    'exists' => false,
                    'message' => 'Pre-generated reports are only available for past dates',
                ]);
            }
            
            // Check if report exists
            $exists = $csvService->reportExists($date);
            $url = $exists ? $csvService->getReportUrl($date) : null;
            
            return response()->json([
                'success' => true,
                'exists' => $exists,
                'url' => $url,
                'date' => $date->toDateString(),
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to check CSV report', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CHECK_ERROR',
                    'message' => 'Failed to check CSV report',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }
    
    /**
     * GET /api/alerts-reports/filter-options
     * 
     * Get available filter options (customers and panel types).
     */
    public function filterOptions(): JsonResponse
    {
        try {
            // Cache customers for 1 hour
            $customers = Cache::remember('alerts_report_customers', 3600, function() {
                return DB::connection('pgsql')
                    ->table('sites')
                    ->select('Customer')
                    ->whereNotNull('Customer')
                    ->where('Customer', '!=', '')
                    ->distinct()
                    ->orderBy('Customer')
                    ->pluck('Customer');
            });

            // Cache panel makes for 1 hour
            $panelMakes = Cache::remember('alerts_report_panel_makes', 3600, function() {
                return DB::connection('pgsql')
                    ->table('sites')
                    ->select('Panel_Make')
                    ->whereNotNull('Panel_Make')
                    ->where('Panel_Make', '!=', '')
                    ->distinct()
                    ->orderBy('Panel_Make')
                    ->pluck('Panel_Make');
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'customers' => $customers,
                    'panel_makes' => $panelMakes,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch filter options', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'OPTIONS_ERROR',
                    'message' => 'Failed to fetch filter options',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * POST /api/alerts-reports/export/csv/token
     * 
     * Generate a temporary download token for CSV export
     */
    public function generateExportToken(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'from_date' => 'required|date',
                'limit' => 'nullable|integer|min:1|max:1000000',
                'offset' => 'nullable|integer|min:0',
            ]);

            // Generate unique token
            $token = bin2hex(random_bytes(32));
            
            // Store token with parameters in cache for 10 minutes
            Cache::put("export_token:{$token}", [
                'from_date' => $validated['from_date'],
                'limit' => $validated['limit'] ?? 1000000,
                'offset' => $validated['offset'] ?? 0,
                'user_id' => auth()->id(),
            ], now()->addMinutes(10));
            
            return response()->json([
                'success' => true,
                'token' => $token,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to generate export token', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TOKEN_ERROR',
                    'message' => 'Failed to generate export token',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/alerts-reports/export/csv
     * 
     * Export ALL alerts for the selected date to CSV with all 27 columns.
     * Downloads complete data without applying any filters.
     * Uses PartitionQueryRouter for improved performance.
     * 
     * Supports both token-based (for direct browser downloads) and 
     * authenticated API requests (for backward compatibility).
     * 
     * Requirements: 10.1, 10.5
     */
    public function exportCsv(Request $request)
    {
        // ⚠️ CRITICAL: Force close session IMMEDIATELY to prevent blocking other users
        // This MUST be the FIRST thing we do, before ANY other processing
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
            Log::info('🔓 Session forcefully closed at start of CSV export');
        }
        
        try {
            // Check for token-based download (no auth middleware needed)
            if ($request->has('token')) {
                $token = $request->input('token');
                $params = Cache::get("export_token:{$token}");
                
                if (!$params) {
                    abort(401, 'Invalid or expired download token');
                }
                
                // Delete token after use (one-time use)
                Cache::forget("export_token:{$token}");
                
                // Use params from token
                $validated = $params;
            } else {
                // Regular authenticated request - validate normally
                $validated = $request->validate([
                    'from_date' => 'required|date',
                    'limit' => 'nullable|integer|min:1|max:1000000',
                    'offset' => 'nullable|integer|min:0',
                    // Filter parameters
                    'panelid' => 'nullable|string|max:255',
                    'dvrip' => 'nullable|string|max:255',
                    'customer' => 'nullable|string|max:255',
                    'panel_type' => 'nullable|string|max:255',
                    'atmid' => 'nullable|string|max:255',
                ]);
            }

            $limit = $validated['limit'] ?? 1000000; // 1 million records max
            $offset = $validated['offset'] ?? 0;

            // IMPORTANT: Close session to allow parallel requests
            // This prevents blocking other requests while CSV is being generated
            if (session_status() === PHP_SESSION_ACTIVE) {
                Log::info('Closing session for parallel download', [
                    'session_id' => session_id(),
                    'offset' => $offset,
                    'limit' => $limit
                ]);
                session_write_close();
            } else {
                Log::info('No active session to close', [
                    'session_status' => session_status(),
                    'offset' => $offset
                ]);
            }

            ini_set('memory_limit', '1024M');
            ini_set('max_execution_time', 600); // 10 minutes
            set_time_limit(600);
            
            // Parse date filter
            $fromDate = Carbon::parse($validated['from_date'])->startOfDay();
            $toDate = $fromDate->copy()->endOfDay();
            
            Log::info('CSV export started', [
                'from_date' => $fromDate->toDateString(),
                'limit' => $limit,
                'offset' => $offset,
                'filters' => $validated,
                'via_token' => $request->has('token')
            ]);

            // Generate filename in format: "21 Server Alert Report – DD-MM-YYYY.csv"
            $filename = '21 Server Alert Report – ' . $fromDate->format('d-m-Y') . '.csv';

            $headers = [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'must-revalidate',
                'Pragma' => 'public',
            ];

            $callback = function () use ($limit, $offset, $fromDate, $toDate, $validated) {
                $file = fopen('php://output', 'w');
                fwrite($file, "\xEF\xBB\xBF");
                
                // Add # as first column
                fputcsv($file, [
                    '#', // Serial number column
                    'Client', 'Incident #', 'Region', 'ATM ID', 'Address', 'City', 'State',
                    'Zone', 'Alarm', 'Category', 'Message', 'Created', 'Received', 'Closed',
                    'DVR IP', 'Panel', 'Panel ID', 'Bank', 'Type', 'Closed By', 'Closed Date',
                    'Aging (hrs)', 'Remark', 'Send IP', 'Testing', 'Testing Remark'
                ]);

                $processed = 0;
                $rowNumber = $offset + 1; // Serial number counter starts from offset + 1
                
                // Use partition router with filters
                $this->exportViaRouterWithFilters($file, $fromDate, $toDate, $validated, $limit, $offset, $processed, $rowNumber);

                fclose($file);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Exception $e) {
            Log::error('Failed to export CSV', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'EXPORT_ERROR',
                    'message' => 'Failed to export CSV',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }
    
    /**
     * Export via partition router WITHOUT filters - downloads ALL data for the date
     * 
     * @param resource $file File handle
     * @param Carbon $startDate Start date
     * @param Carbon $endDate End date
     * @param int $limit Maximum records
     * @param int $startOffset Starting offset for batch downloads
     * @param int &$processed Processed count (by reference)
     * @param int &$rowNumber Row number counter (by reference)
     */
    protected function exportViaRouterNoFilters($file, Carbon $startDate, Carbon $endDate, int $limit, int $startOffset, int &$processed, int &$rowNumber): void
    {
        try {
            // NO FILTERS - download all data for the date
            $chunkSize = 1000;
            $currentOffset = $startOffset; // Start from the batch offset
            
            while ($processed < $limit) {
                // Calculate how many records we still need
                $remaining = $limit - $processed;
                $fetchSize = min($chunkSize, $remaining);
                
                // Query chunk via partition router
                // IMPORTANT: currentOffset is the GLOBAL offset across all partitions
                $options = [
                    'limit' => $fetchSize,
                    'offset' => $currentOffset,
                    'order_by' => 'id',
                    'order_direction' => 'DESC',
                ];
                
                $results = $this->partitionRouter->queryDateRange($startDate, $endDate, [], $options, ['alerts', 'backalerts']);
                
                if ($results->isEmpty()) {
                    // No more data available
                    Log::info("CSV export: No more data at offset {$currentOffset}");
                    break;
                }
                
                // Convert to array format
                $alerts = $results->map(function($record) {
                    return (array) $record;
                })->toArray();
                
                // Enrich with sites data
                $enriched = $this->enrichWithSites($alerts);
                
                // Write to CSV
                foreach ($enriched as $report) {
                    if ($processed >= $limit) break;
                    
                    $this->writeCsvRow($file, $report, $rowNumber);
                    $processed++;
                    $rowNumber++;
                }
                
                // Move offset forward by the number of records we actually got
                $currentOffset += $results->count();
                
                // Log progress every 10k records
                if ($processed % 10000 == 0) {
                    Log::info("CSV export progress: {$processed} records exported, offset: {$currentOffset}");
                }
                
                // Free memory
                unset($results, $alerts, $enriched);
                if ($processed % 5000 == 0) {
                    gc_collect_cycles();
                }
            }
            
            Log::info("CSV export completed", [
                'total_records' => $processed,
                'final_offset' => $currentOffset,
                'date' => $startDate->toDateString()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to export via partition router', [
                'error' => $e->getMessage(),
                'offset' => $currentOffset ?? $startOffset,
                'processed' => $processed
            ]);
            throw $e;
        }
    }
    
    /**
     * Export via partition router WITH filters - downloads filtered data
     * 
     * @param resource $file File handle
     * @param Carbon $startDate Start date
     * @param Carbon $endDate End date
     * @param array $validated Validated request data with filters
     * @param int $limit Maximum records
     * @param int $startOffset Starting offset for batch downloads
     * @param int &$processed Processed count (by reference)
     * @param int &$rowNumber Row number counter (by reference)
     */
    protected function exportViaRouterWithFilters($file, Carbon $startDate, Carbon $endDate, array $validated, int $limit, int $startOffset, int &$processed, int &$rowNumber): void
    {
        try {
            // Build filter array for partition router
            $filters = [];
            
            if (!empty($validated['panelid'])) {
                $filters['panel_id'] = $validated['panelid'];
            }
            
            // For sites-based filters, get panel IDs first
            $panelIds = $this->getPanelIdsFromSitesFilters($validated);
            
            if ($panelIds !== null) {
                if (empty($panelIds)) {
                    // No matching sites, return empty
                    Log::info("CSV export: No matching sites for filters");
                    return;
                }
                
                // Use panel IDs filter with partition router
                $filters['panel_ids'] = $panelIds;
            }
            
            $chunkSize = 1000;
            $currentOffset = $startOffset;
            
            while ($processed < $limit) {
                // Calculate how many records we still need
                $remaining = $limit - $processed;
                $fetchSize = min($chunkSize, $remaining);
                
                // Query chunk via partition router with filters
                $options = [
                    'limit' => $fetchSize,
                    'offset' => $currentOffset,
                    'order_by' => 'id',
                    'order_direction' => 'DESC',
                ];
                
                $results = $this->partitionRouter->queryDateRange($startDate, $endDate, $filters, $options, ['alerts', 'backalerts']);
                
                if ($results->isEmpty()) {
                    Log::info("CSV export: No more data at offset {$currentOffset}");
                    break;
                }
                
                // Convert to array format
                $alerts = $results->map(function($record) {
                    return (array) $record;
                })->toArray();
                
                // Enrich with sites data
                $enriched = $this->enrichWithSites($alerts);
                
                // Write to CSV
                foreach ($enriched as $report) {
                    if ($processed >= $limit) break;
                    
                    $this->writeCsvRow($file, $report, $rowNumber);
                    $processed++;
                    $rowNumber++;
                }
                
                // Move offset forward
                $currentOffset += $results->count();
                
                // Log progress every 10k records
                if ($processed % 10000 == 0) {
                    Log::info("CSV export progress: {$processed} records exported, offset: {$currentOffset}");
                }
                
                // Free memory
                unset($results, $alerts, $enriched);
                if ($processed % 5000 == 0) {
                    gc_collect_cycles();
                }
            }
            
            Log::info("CSV export completed with filters", [
                'total_records' => $processed,
                'final_offset' => $currentOffset,
                'filters' => $filters,
                'date' => $startDate->toDateString()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to export via partition router with filters', [
                'error' => $e->getMessage(),
                'offset' => $currentOffset ?? $startOffset,
                'processed' => $processed
            ]);
            throw $e;
        }
    }
    
    /**
     * Write a single CSV row
     * 
     * @param resource $file File handle
     * @param object|array $report Report data
     * @param int $rowNumber Serial number for this row
     */
    protected function writeCsvRow($file, $report, int $rowNumber): void
    {
        // Convert to object if array
        if (is_array($report)) {
            $report = (object) $report;
        }
        
        $isRestoral = str_ends_with($report->alarm ?? '', 'R');
        
        // Aging is already formatted as HH:MM:SS or 'NA'
        $aging = $report->aging ?? 'NA';
        
        // Helper to preserve leading zeros - prefix with tab character
        // This forces Excel to treat the value as text
        $preserveLeadingZeros = function($value) {
            if ($value === null || $value === '') return '';
            $strVal = (string)$value;
            // If starts with 0 and is numeric-like, prefix with tab to force text
            if (strlen($strVal) > 1 && $strVal[0] === '0' && ctype_alnum($strVal)) {
                return "\t" . $strVal;
            }
            return $strVal;
        };
        
        fputcsv($file, [
            $rowNumber, // Serial number as first column
            $report->Customer ?? '',
            $report->id ?? '',
            $report->site_zone ?? '',
            $preserveLeadingZeros($report->ATMID ?? ''),
            $report->SiteAddress ?? '',
            $report->City ?? '',
            $report->State ?? '',
            $preserveLeadingZeros($report->zone ?? ''),
            $preserveLeadingZeros($report->alarm ?? ''),
            $report->alerttype ?? '',
            $isRestoral ? ($report->alerttype . ' Restoral') : ($report->alerttype ?? ''),
            $report->createtime ?? '',
            $report->receivedtime ?? '',
            $report->closedtime ?? '',
            $report->DVRIP ?? '',
            $report->Panel_Make ?? '',
            $preserveLeadingZeros($report->panelid ?? ''),
            $report->Bank ?? '',
            $isRestoral ? 'Non-Reactive' : 'Reactive',
            $report->closedBy ?? '',
            $report->closedtime ?? '',
            $aging,
            $report->comment ?? '',
            $report->sendip ?? '',
            '',
            '',
        ]);
    }
}
