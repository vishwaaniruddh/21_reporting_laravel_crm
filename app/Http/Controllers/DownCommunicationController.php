<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DownCommunicationController - Down Communication Report
 * 
 * Provides down communication data from MySQL with sites JOIN.
 * Shows ATMs where communication is down (dc_date != today or is null).
 */
class DownCommunicationController extends Controller
{
    /**
     * GET /api/down-communication
     * 
     * Get paginated down communication report with filters
     * Optimized: Uses PostgreSQL for sites, MySQL for down_communication and esurvsites
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:10|max:100',
                'customer' => 'nullable|string|max:255',
                'bank' => 'nullable|string|max:255',
                'atmid' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:255',
                'state' => 'nullable|string|max:255',
            ]);

            $perPage = $validated['per_page'] ?? 25;
            $page = $validated['page'] ?? 1;
            $todayDate = date('Y-m-d');

            // Get down communication records from MySQL
            $downComm = DB::connection('mysql')
                ->table('down_communication')
                ->select('atm_id', 'panel_id', 'dc_date')
                ->get();

            // Separate working and not working
            $workingCount = 0;
            $notWorkingPanelIds = [];
            
            foreach ($downComm as $dc) {
                $dc_date_only = is_null($dc->dc_date) ? null : date('Y-m-d', strtotime($dc->dc_date));
                
                if ($dc_date_only === $todayDate) {
                    $workingCount++;
                } else {
                    $notWorkingPanelIds[] = $dc->panel_id;
                }
            }

            if (empty($notWorkingPanelIds)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'records' => [],
                        'pagination' => [
                            'current_page' => 1,
                            'last_page' => 1,
                            'per_page' => $perPage,
                            'total' => 0,
                            'from' => null,
                            'to' => null,
                        ],
                        'summary' => [
                            'working_count' => $workingCount,
                            'not_working_count' => 0,
                            'total_count' => $workingCount,
                        ],
                    ],
                ]);
            }

            // Get sites from PostgreSQL (faster) - filter by panel IDs
            $sitesQuery = DB::connection('pgsql')
                ->table('sites')
                ->whereIn('NewPanelID', $notWorkingPanelIds)
                ->select([
                    'Customer', 'Bank', 'ATMID', 'ATMShortName', 'City', 'State',
                    'Panel_Make', 'OldPanelID', 'NewPanelID', 'DVRIP', 'DVRName', 'Zone'
                ]);

            // Apply filters
            if (!empty($validated['customer'])) {
                $sitesQuery->where('Customer', 'ilike', '%' . $validated['customer'] . '%');
            }
            if (!empty($validated['bank'])) {
                $sitesQuery->where('Bank', 'ilike', '%' . $validated['bank'] . '%');
            }
            if (!empty($validated['atmid'])) {
                $sitesQuery->where('ATMID', 'ilike', '%' . $validated['atmid'] . '%');
            }
            if (!empty($validated['city'])) {
                $sitesQuery->where('City', 'ilike', '%' . $validated['city'] . '%');
            }
            if (!empty($validated['state'])) {
                $sitesQuery->where('State', 'ilike', '%' . $validated['state'] . '%');
            }

            $sites = $sitesQuery->get();

            // Create panel_id to dc_date mapping
            $dcDateMap = [];
            foreach ($downComm as $dc) {
                $dcDateMap[$dc->panel_id] = $dc->dc_date;
            }

            // Get ATMIDs for BM lookup
            $atmIds = $sites->pluck('ATMID')->toArray();
            
            // Get BM data from MySQL esurvsites
            $bmData = [];
            if (!empty($atmIds)) {
                $bmRecords = DB::connection('mysql')
                    ->table('esurvsites')
                    ->whereIn('ATM_ID', $atmIds)
                    ->select('ATM_ID', 'CSSBM', 'CSSBMNumber')
                    ->get();
                
                foreach ($bmRecords as $bm) {
                    $bmData[$bm->ATM_ID] = [
                        'CSSBM' => $bm->CSSBM,
                        'CSSBMNumber' => $bm->CSSBMNumber,
                    ];
                }
            }

            // Build final records
            $notWorkingRecords = [];
            foreach ($sites as $site) {
                $bm = $bmData[$site->ATMID] ?? null;
                
                $notWorkingRecords[] = [
                    'Customer' => $site->Customer,
                    'Bank' => $site->Bank,
                    'ATMID' => $site->ATMID,
                    'ATMShortName' => $site->ATMShortName,
                    'City' => $site->City,
                    'State' => $site->State,
                    'Panel_Make' => $site->Panel_Make,
                    'OldPanelID' => $site->OldPanelID,
                    'NewPanelID' => $site->NewPanelID,
                    'DVRIP' => $site->DVRIP,
                    'DVRName' => $site->DVRName,
                    'dc_date' => $dcDateMap[$site->NewPanelID] ?? null,
                    'Zone' => $site->Zone,
                    'CSSBM' => $bm['CSSBM'] ?? '',
                    'CSSBMNumber' => $bm['CSSBMNumber'] ?? '',
                ];
            }

            // Paginate results
            $total = count($notWorkingRecords);
            $offset = ($page - 1) * $perPage;
            $paginatedRecords = array_slice($notWorkingRecords, $offset, $perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'records' => $paginatedRecords,
                    'pagination' => [
                        'current_page' => $page,
                        'last_page' => ceil($total / $perPage),
                        'per_page' => $perPage,
                        'total' => $total,
                        'from' => $total > 0 ? $offset + 1 : null,
                        'to' => min($offset + $perPage, $total),
                    ],
                    'summary' => [
                        'working_count' => $workingCount,
                        'not_working_count' => $total,
                        'total_count' => $workingCount + $total,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch down communication report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FETCH_ERROR',
                    'message' => 'Failed to fetch down communication report',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/down-communication/filter-options
     * 
     * Get available filter options (uses PostgreSQL for sites)
     */
    public function filterOptions(): JsonResponse
    {
        try {
            $customers = DB::connection('pgsql')
                ->table('sites')
                ->select('Customer')
                ->whereNotNull('Customer')
                ->where('Customer', '!=', '')
                ->distinct()
                ->orderBy('Customer')
                ->pluck('Customer');

            $banks = DB::connection('pgsql')
                ->table('sites')
                ->select('Bank')
                ->whereNotNull('Bank')
                ->where('Bank', '!=', '')
                ->distinct()
                ->orderBy('Bank')
                ->pluck('Bank');

            return response()->json([
                'success' => true,
                'data' => [
                    'customers' => $customers,
                    'banks' => $banks,
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
     * GET /api/down-communication/export/csv
     * 
     * Export down communication report to CSV (optimized with PostgreSQL)
     */
    public function exportCsv(Request $request)
    {
        try {
            $todayDate = date('Y-m-d');

            $filename = 'down_communication_' . $todayDate . '.csv';

            $headers = [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'must-revalidate',
                'Pragma' => 'public',
            ];

            $callback = function () use ($todayDate) {
                $file = fopen('php://output', 'w');
                fwrite($file, "\xEF\xBB\xBF");

                fputcsv($file, [
                    '#', 'Customer', 'Bank', 'ATM ID', 'ATM Short Name', 'City', 'State',
                    'Panel Make', 'Old Panel ID', 'New Panel ID', 'DVR IP', 'DVR Name',
                    'Last Communication', 'BM Name', 'BM Number', 'Zone'
                ]);

                // Get down communication from MySQL
                $downComm = DB::connection('mysql')
                    ->table('down_communication')
                    ->select('atm_id', 'panel_id', 'dc_date')
                    ->get();

                $notWorkingPanelIds = [];
                $dcDateMap = [];
                
                foreach ($downComm as $dc) {
                    $dc_date_only = is_null($dc->dc_date) ? null : date('Y-m-d', strtotime($dc->dc_date));
                    
                    if ($dc_date_only !== $todayDate) {
                        $notWorkingPanelIds[] = $dc->panel_id;
                        $dcDateMap[$dc->panel_id] = $dc->dc_date;
                    }
                }

                if (empty($notWorkingPanelIds)) {
                    fclose($file);
                    return;
                }

                // Get sites from PostgreSQL
                $sites = DB::connection('pgsql')
                    ->table('sites')
                    ->whereIn('NewPanelID', $notWorkingPanelIds)
                    ->select([
                        'Customer', 'Bank', 'ATMID', 'ATMShortName', 'City', 'State',
                        'Panel_Make', 'OldPanelID', 'NewPanelID', 'DVRIP', 'DVRName', 'Zone'
                    ])
                    ->get();

                // Get BM data from MySQL
                $atmIds = $sites->pluck('ATMID')->toArray();
                $bmData = [];
                
                if (!empty($atmIds)) {
                    $bmRecords = DB::connection('mysql')
                        ->table('esurvsites')
                        ->whereIn('ATM_ID', $atmIds)
                        ->select('ATM_ID', 'CSSBM', 'CSSBMNumber')
                        ->get();
                    
                    foreach ($bmRecords as $bm) {
                        $bmData[$bm->ATM_ID] = [
                            'CSSBM' => $bm->CSSBM,
                            'CSSBMNumber' => $bm->CSSBMNumber,
                        ];
                    }
                }

                $sr = 1;
                foreach ($sites as $site) {
                    $bm = $bmData[$site->ATMID] ?? null;
                    
                    fputcsv($file, [
                        $sr++,
                        $site->Customer,
                        $site->Bank,
                        $site->ATMID,
                        $site->ATMShortName,
                        $site->City,
                        $site->State,
                        $site->Panel_Make,
                        $site->OldPanelID,
                        $site->NewPanelID,
                        $site->DVRIP,
                        $site->DVRName,
                        $dcDateMap[$site->NewPanelID] ?? null,
                        $bm['CSSBM'] ?? '',
                        $bm['CSSBMNumber'] ?? '',
                        $site->Zone,
                    ]);
                }

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
}
