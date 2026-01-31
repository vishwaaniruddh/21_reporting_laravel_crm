<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * RMSSitesController - RMS Sites management
 * 
 * Provides paginated RMS sites data with filtering and export capabilities.
 */
class RMSSitesController extends Controller
{
    /**
     * GET /api/rms-sites
     * 
     * Get paginated RMS sites with optional filters
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:10|max:100',
                'atmid' => 'nullable|string|max:255',
                'customer' => 'nullable|string|max:255',
                'bank' => 'nullable|string|max:255',
                'panel_make' => 'nullable|string|max:255',
                'dvrname' => 'nullable|string|max:255',
                'dvrip' => 'nullable|string|max:255',
                'live' => 'nullable|string|max:10',
            ]);

            $perPage = $validated['per_page'] ?? 25;
            $page = $validated['page'] ?? 1;
            
            // Build query
            $query = DB::connection('pgsql')->table('sites');
            
            // Apply filters
            if (!empty($validated['atmid'])) {
                $query->where('ATMID', 'ilike', '%' . $validated['atmid'] . '%');
            }
            
            if (!empty($validated['customer'])) {
                $query->where('Customer', $validated['customer']);
            }
            
            if (!empty($validated['bank'])) {
                $query->where('Bank', $validated['bank']);
            }
            
            if (!empty($validated['panel_make'])) {
                $query->where('Panel_Make', $validated['panel_make']);
            }
            
            if (!empty($validated['dvrname'])) {
                $query->where('DVRName', $validated['dvrname']);
            }
            
            if (!empty($validated['dvrip'])) {
                $query->where('DVRIP', 'ilike', '%' . $validated['dvrip'] . '%');
            }
            
            if (!empty($validated['live'])) {
                $query->where('live', $validated['live']);
            }
            
            // Get total count
            $total = $query->count();
            
            // Get paginated data
            $sites = $query
                ->orderBy('SN', 'asc')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();
            
            // Calculate pagination
            $lastPage = ceil($total / $perPage);
            $from = $total > 0 ? (($page - 1) * $perPage) + 1 : null;
            $to = $total > 0 ? min($page * $perPage, $total) : null;
            
            return response()->json([
                'success' => true,
                'data' => [
                    'sites' => $sites,
                    'pagination' => [
                        'current_page' => $page,
                        'last_page' => $lastPage,
                        'per_page' => $perPage,
                        'total' => $total,
                        'from' => $from,
                        'to' => $to,
                    ],
                    'total_count' => $total,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch RMS sites', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FETCH_ERROR',
                    'message' => 'Failed to fetch RMS sites',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }
    
    /**
     * GET /api/rms-sites/filter-options
     * 
     * Get available filter options
     */
    public function filterOptions(): JsonResponse
    {
        try {
            // Cache filter options for 1 hour
            $customers = Cache::remember('rms_sites_customers', 3600, function() {
                return DB::connection('pgsql')
                    ->table('sites')
                    ->select('Customer')
                    ->whereNotNull('Customer')
                    ->where('Customer', '!=', '')
                    ->distinct()
                    ->orderBy('Customer')
                    ->pluck('Customer');
            });

            $banks = Cache::remember('rms_sites_banks', 3600, function() {
                return DB::connection('pgsql')
                    ->table('sites')
                    ->select('Bank')
                    ->whereNotNull('Bank')
                    ->where('Bank', '!=', '')
                    ->distinct()
                    ->orderBy('Bank')
                    ->pluck('Bank');
            });

            $panelMakes = Cache::remember('rms_sites_panel_makes', 3600, function() {
                return DB::connection('pgsql')
                    ->table('sites')
                    ->select('Panel_Make')
                    ->whereNotNull('Panel_Make')
                    ->where('Panel_Make', '!=', '')
                    ->distinct()
                    ->orderBy('Panel_Make')
                    ->pluck('Panel_Make');
            });

            $dvrNames = Cache::remember('rms_sites_dvr_names', 3600, function() {
                return DB::connection('pgsql')
                    ->table('sites')
                    ->select('DVRName')
                    ->whereNotNull('DVRName')
                    ->where('DVRName', '!=', '')
                    ->distinct()
                    ->orderBy('DVRName')
                    ->pluck('DVRName');
            });

            $liveStatuses = Cache::remember('rms_sites_live_statuses', 3600, function() {
                return DB::connection('pgsql')
                    ->table('sites')
                    ->select('live')
                    ->whereNotNull('live')
                    ->where('live', '!=', '')
                    ->distinct()
                    ->orderBy('live')
                    ->pluck('live');
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'customers' => $customers,
                    'banks' => $banks,
                    'panel_makes' => $panelMakes,
                    'dvr_names' => $dvrNames,
                    'live_statuses' => $liveStatuses,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch filter options', [
                'error' => $e->getMessage()
            ]);

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
     * GET /api/rms-sites/export/csv
     * 
     * Export RMS sites to CSV
     */
    public function exportCsv(Request $request)
    {
        try {
            $validated = $request->validate([
                'atmid' => 'nullable|string|max:255',
                'customer' => 'nullable|string|max:255',
                'bank' => 'nullable|string|max:255',
                'panel_make' => 'nullable|string|max:255',
                'dvrname' => 'nullable|string|max:255',
                'dvrip' => 'nullable|string|max:255',
                'live' => 'nullable|string|max:10',
                'limit' => 'nullable|integer|min:1|max:100000',
            ]);

            $limit = $validated['limit'] ?? 100000;

            ini_set('memory_limit', '512M');
            ini_set('max_execution_time', 300);
            set_time_limit(300);
            
            $filename = 'rms_sites_' . date('Y-m-d_His') . '.csv';

            $headers = [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'must-revalidate',
                'Pragma' => 'public',
            ];

            $callback = function () use ($validated, $limit) {
                $file = fopen('php://output', 'w');
                fwrite($file, "\xEF\xBB\xBF"); // UTF-8 BOM
                
                // CSV Headers
                fputcsv($file, [
                    'SN', 'Customer', 'Bank', 'ATMID', 'ATM ID 2', 'ATM ID 3',
                    'Address', 'City', 'State', 'Zone', 'Panel ID (New)', 'Panel ID (Old)',
                    'Panel Make', 'DVR Name', 'DVR IP', 'Port', 'Username', 'Password',
                    'Live', 'Installation Date', 'Old Panel ID', 'New Panel ID',
                    'Router ID', 'SIM Number', 'SIM Owner', 'Router Brand'
                ]);

                // Build query
                $query = DB::connection('pgsql')->table('sites');
                
                // Apply filters
                if (!empty($validated['atmid'])) {
                    $query->where('ATMID', 'ilike', '%' . $validated['atmid'] . '%');
                }
                if (!empty($validated['customer'])) {
                    $query->where('Customer', $validated['customer']);
                }
                if (!empty($validated['bank'])) {
                    $query->where('Bank', $validated['bank']);
                }
                if (!empty($validated['panel_make'])) {
                    $query->where('Panel_Make', $validated['panel_make']);
                }
                if (!empty($validated['dvrname'])) {
                    $query->where('DVRName', $validated['dvrname']);
                }
                if (!empty($validated['dvrip'])) {
                    $query->where('DVRIP', 'ilike', '%' . $validated['dvrip'] . '%');
                }
                if (!empty($validated['live'])) {
                    $query->where('live', $validated['live']);
                }
                
                // Export data in chunks
                $query->orderBy('SN', 'asc')
                    ->chunk(1000, function($sites) use ($file, &$limit) {
                        foreach ($sites as $site) {
                            if ($limit <= 0) return false;
                            
                            fputcsv($file, [
                                $site->SN ?? '',
                                $site->Customer ?? '',
                                $site->Bank ?? '',
                                $site->ATMID ?? '',
                                $site->ATMID_2 ?? '',
                                $site->ATMID_3 ?? '',
                                $site->SiteAddress ?? '',
                                $site->City ?? '',
                                $site->State ?? '',
                                $site->Zone ?? '',
                                $site->NewPanelID ?? '',
                                $site->OldPanelID ?? '',
                                $site->Panel_Make ?? '',
                                $site->DVRName ?? '',
                                $site->DVRIP ?? '',
                                $site->Port ?? '',
                                $site->UserName ?? '',
                                $site->Password ?? '',
                                $site->live ?? '',
                                $site->installationDate ?? '',
                                $site->old_panelid ?? '',
                                $site->new_panelid ?? '',
                                $site->RouterID ?? '',
                                $site->SIMNumber ?? '',
                                $site->SIMOwner ?? '',
                                $site->RouterBrand ?? '',
                            ]);
                            
                            $limit--;
                        }
                    });

                fclose($file);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Exception $e) {
            Log::error('Failed to export RMS sites CSV', [
                'error' => $e->getMessage()
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
}
