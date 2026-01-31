<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DailyReportController - Detailed daily operational reports
 * 
 * Provides comprehensive daily breakdown of alerts, sites, and performance metrics
 */
class DailyReportController extends Controller
{
    /**
     * GET /api/reports/daily
     * 
     * Get comprehensive daily report for a specific date
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'date' => 'required|date',
                'customer' => 'nullable|string',
                'zone' => 'nullable|string',
                'panel_type' => 'nullable|string',
            ]);

            $date = Carbon::parse($validated['date']);
            $filters = [
                'customer' => $validated['customer'] ?? null,
                'zone' => $validated['zone'] ?? null,
                'panel_type' => $validated['panel_type'] ?? null,
            ];

            $data = [
                'summary' => $this->getDailySummary($date, $filters),
                'alert_breakdown' => $this->getAlertBreakdown($date, $filters),
                'hourly_distribution' => $this->getHourlyDistribution($date, $filters),
                'top_sites' => $this->getTopSites($date, $filters),
                'customer_breakdown' => $this->getCustomerBreakdown($date, $filters),
                'zone_breakdown' => $this->getZoneBreakdown($date, $filters),
                'alert_type_distribution' => $this->getAlertTypeDistribution($date, $filters),
                'comparisons' => $this->getComparisons($date, $filters),
                'down_sites' => $this->getDownSites($date),
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
                'meta' => [
                    'date' => $date->format('Y-m-d'),
                    'generated_at' => Carbon::now()->toIso8601String(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch daily report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'REPORT_ERROR',
                    'message' => 'Failed to fetch daily report',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Daily Summary - Key metrics for the day
     */
    private function getDailySummary($date, $filters): array
    {
        try {
            $partition = 'alerts_' . $date->format('Y_m_d');
            
            if (!$this->tableExists('pgsql', $partition)) {
                return $this->getEmptySummary();
            }

            // Total alerts
            $totalAlerts = 0;
            try {
                $query = DB::connection('pgsql')->table($partition);
                $this->applyFilters($query, $filters);
                $totalAlerts = $query->count();
            } catch (\Exception $e) {
                Log::warning('Failed to count total alerts', ['error' => $e->getMessage()]);
            }

            // VM alerts
            $vmAlerts = 0;
            try {
                $vmQuery = DB::connection('pgsql')->table($partition)
                    ->whereIn('status', ['O', 'C'])
                    ->where('sendtoclient', 'S');
                $this->applyFilters($vmQuery, $filters);
                $vmAlerts = $vmQuery->count();
            } catch (\Exception $e) {
                Log::warning('Failed to count VM alerts', ['error' => $e->getMessage()]);
            }

            // Unique sites with alerts
            $uniqueSites = 0;
            try {
                $sitesQuery = DB::connection('pgsql')->table($partition)
                    ->distinct();
                $this->applyFilters($sitesQuery, $filters);
                $uniqueSites = $sitesQuery->count('panelid');
            } catch (\Exception $e) {
                Log::warning('Failed to count unique sites', ['error' => $e->getMessage()]);
            }

            // Total sites
            $totalSites = 0;
            try {
                $totalSites = DB::connection('pgsql')->table('sites')->count();
            } catch (\Exception $e) {
                Log::warning('Failed to count total sites', ['error' => $e->getMessage()]);
            }

            // Down sites
            $downSites = 0;
            try {
                $downSites = DB::connection('mysql')
                    ->table('down_communication')
                    ->where(function($query) use ($date) {
                        $query->whereDate('dc_date', '!=', $date->format('Y-m-d'))
                              ->orWhereNull('dc_date');
                    })
                    ->count();
            } catch (\Exception $e) {
                Log::warning('Failed to count down sites', ['error' => $e->getMessage()]);
            }

            return [
                'total_alerts' => $totalAlerts,
                'vm_alerts' => $vmAlerts,
                'unique_sites_with_alerts' => $uniqueSites,
                'total_sites' => $totalSites,
                'down_sites' => $downSites,
                'healthy_sites' => max(0, $totalSites - $uniqueSites),
            ];
        } catch (\Exception $e) {
            Log::warning('Daily summary calculation failed', ['error' => $e->getMessage()]);
            return $this->getEmptySummary();
        }
    }

    /**
     * Alert Breakdown by Category
     */
    private function getAlertBreakdown($date, $filters): array
    {
        try {
            $partition = 'alerts_' . $date->format('Y_m_d');
            
            if (!$this->tableExists('pgsql', $partition)) {
                return [];
            }

            $query = DB::connection('pgsql')
                ->table($partition)
                ->select('alerttype', DB::raw('COUNT(*) as count'))
                ->groupBy('alerttype')
                ->orderByDesc('count')
                ->limit(10);
            
            $this->applyFilters($query, $filters);
            
            return $query->get()->map(function($item) {
                return [
                    'type' => $item->alerttype ?? 'Unknown',
                    'count' => $item->count,
                ];
            })->toArray();
        } catch (\Exception $e) {
            Log::warning('Alert breakdown calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Hourly Distribution - Alerts by hour
     */
    private function getHourlyDistribution($date, $filters): array
    {
        try {
            $partition = 'alerts_' . $date->format('Y_m_d');
            
            if (!$this->tableExists('pgsql', $partition)) {
                return array_fill(0, 24, ['hour' => 0, 'total' => 0, 'vm_alerts' => 0]);
            }

            // Get total alerts by hour
            $totalQuery = DB::connection('pgsql')
                ->table($partition)
                ->select(
                    DB::raw('EXTRACT(HOUR FROM receivedtime) as hour'),
                    DB::raw('COUNT(*) as total')
                )
                ->groupBy('hour')
                ->orderBy('hour');
            
            $this->applyFilters($totalQuery, $filters);
            $totalByHour = $totalQuery->get()->keyBy('hour');

            // Get VM alerts by hour
            $vmQuery = DB::connection('pgsql')
                ->table($partition)
                ->select(
                    DB::raw('EXTRACT(HOUR FROM receivedtime) as hour'),
                    DB::raw('COUNT(*) as vm_count')
                )
                ->whereIn('status', ['O', 'C'])
                ->where('sendtoclient', 'S')
                ->groupBy('hour')
                ->orderBy('hour');
            
            $this->applyFilters($vmQuery, $filters);
            $vmByHour = $vmQuery->get()->keyBy('hour');

            // Build 24-hour array
            $distribution = [];
            for ($hour = 0; $hour < 24; $hour++) {
                $distribution[] = [
                    'hour' => $hour,
                    'total' => isset($totalByHour[$hour]) ? (int)$totalByHour[$hour]->total : 0,
                    'vm_alerts' => isset($vmByHour[$hour]) ? (int)$vmByHour[$hour]->vm_count : 0,
                ];
            }

            return $distribution;
        } catch (\Exception $e) {
            Log::warning('Hourly distribution calculation failed', ['error' => $e->getMessage()]);
            return array_fill(0, 24, ['hour' => 0, 'total' => 0, 'vm_alerts' => 0]);
        }
    }

    /**
     * Top Sites with Most Alerts
     */
    private function getTopSites($date, $filters): array
    {
        try {
            $partition = 'alerts_' . $date->format('Y_m_d');
            
            if (!$this->tableExists('pgsql', $partition)) {
                return [];
            }

            $query = DB::connection('pgsql')
                ->table($partition)
                ->select('panelid', DB::raw('COUNT(*) as alert_count'))
                ->whereNotNull('panelid')
                ->where('panelid', '!=', '')
                ->groupBy('panelid')
                ->orderByDesc('alert_count')
                ->limit(10);
            
            $this->applyFilters($query, $filters);
            $topPanels = $query->get();

            // Get site details
            $panelIds = $topPanels->pluck('panelid')->toArray();
            $sites = DB::connection('pgsql')
                ->table('sites')
                ->whereIn('NewPanelID', $panelIds)
                ->select('NewPanelID', 'ATMID', 'ATMShortName', 'Customer', 'City', 'Zone')
                ->get()
                ->keyBy('NewPanelID');

            return $topPanels->map(function($item) use ($sites) {
                $site = $sites->get($item->panelid);
                return [
                    'panel_id' => $item->panelid,
                    'atmid' => $site->ATMID ?? 'Unknown',
                    'site_name' => $site->ATMShortName ?? 'Unknown',
                    'customer' => $site->Customer ?? 'Unknown',
                    'city' => $site->City ?? 'Unknown',
                    'zone' => $site->Zone ?? 'Unknown',
                    'alert_count' => $item->alert_count,
                ];
            })->toArray();
        } catch (\Exception $e) {
            Log::warning('Top sites calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Customer Breakdown
     */
    private function getCustomerBreakdown($date, $filters): array
    {
        try {
            $partition = 'alerts_' . $date->format('Y_m_d');
            
            if (!$this->tableExists('pgsql', $partition)) {
                return [];
            }

            // Get panel IDs with their alert counts
            $query = DB::connection('pgsql')
                ->table($partition)
                ->select('panelid', DB::raw('COUNT(*) as alert_count'))
                ->whereNotNull('panelid')
                ->where('panelid', '!=', '')
                ->groupBy('panelid');
            
            $this->applyFilters($query, $filters);
            $alertsByPanel = $query->get()->keyBy('panelid');

            // Get customer mapping from sites
            $sites = DB::connection('pgsql')
                ->table('sites')
                ->whereIn('NewPanelID', array_keys($alertsByPanel->toArray()))
                ->select('NewPanelID', 'Customer')
                ->get();

            // Aggregate by customer
            $customerCounts = [];
            foreach ($sites as $site) {
                $customer = $site->Customer ?? 'Unknown';
                $alertCount = $alertsByPanel[$site->NewPanelID]->alert_count ?? 0;
                
                if (!isset($customerCounts[$customer])) {
                    $customerCounts[$customer] = 0;
                }
                $customerCounts[$customer] += $alertCount;
            }

            // Sort and format
            arsort($customerCounts);
            
            return array_map(function($customer, $count) {
                return [
                    'customer' => $customer,
                    'alert_count' => $count,
                ];
            }, array_keys($customerCounts), $customerCounts);
        } catch (\Exception $e) {
            Log::warning('Customer breakdown calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Zone Breakdown
     */
    private function getZoneBreakdown($date, $filters): array
    {
        try {
            $partition = 'alerts_' . $date->format('Y_m_d');
            
            if (!$this->tableExists('pgsql', $partition)) {
                return [];
            }

            // Get panel IDs with their alert counts
            $query = DB::connection('pgsql')
                ->table($partition)
                ->select('panelid', DB::raw('COUNT(*) as alert_count'))
                ->whereNotNull('panelid')
                ->where('panelid', '!=', '')
                ->groupBy('panelid');
            
            $this->applyFilters($query, $filters);
            $alertsByPanel = $query->get()->keyBy('panelid');

            // Get zone mapping from sites
            $sites = DB::connection('pgsql')
                ->table('sites')
                ->whereIn('NewPanelID', array_keys($alertsByPanel->toArray()))
                ->select('NewPanelID', 'Zone')
                ->get();

            // Aggregate by zone
            $zoneCounts = [];
            foreach ($sites as $site) {
                $zone = $site->Zone ?? 'Unknown';
                $alertCount = $alertsByPanel[$site->NewPanelID]->alert_count ?? 0;
                
                if (!isset($zoneCounts[$zone])) {
                    $zoneCounts[$zone] = 0;
                }
                $zoneCounts[$zone] += $alertCount;
            }

            // Sort and format
            arsort($zoneCounts);
            
            return array_map(function($zone, $count) {
                return [
                    'zone' => $zone,
                    'alert_count' => $count,
                ];
            }, array_keys($zoneCounts), $zoneCounts);
        } catch (\Exception $e) {
            Log::warning('Zone breakdown calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Alert Type Distribution
     */
    private function getAlertTypeDistribution($date, $filters): array
    {
        try {
            $partition = 'alerts_' . $date->format('Y_m_d');
            
            if (!$this->tableExists('pgsql', $partition)) {
                return [];
            }

            $query = DB::connection('pgsql')
                ->table($partition)
                ->select('alerttype', DB::raw('COUNT(*) as count'))
                ->groupBy('alerttype')
                ->orderByDesc('count');
            
            $this->applyFilters($query, $filters);
            
            $results = $query->get();
            $total = $results->sum('count');

            return $results->map(function($item) use ($total) {
                return [
                    'type' => $item->alerttype ?? 'Unknown',
                    'count' => $item->count,
                    'percentage' => $total > 0 ? round(($item->count / $total) * 100, 2) : 0,
                ];
            })->toArray();
        } catch (\Exception $e) {
            Log::warning('Alert type distribution calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Comparisons - Today vs Yesterday, Last Week, Monthly Average
     */
    private function getComparisons($date, $filters): array
    {
        try {
            $today = $this->getDateMetrics($date, $filters);
            $yesterday = $this->getDateMetrics($date->copy()->subDay(), $filters);
            $lastWeek = $this->getDateMetrics($date->copy()->subWeek(), $filters);

            return [
                'today' => $today,
                'yesterday' => $yesterday,
                'last_week_same_day' => $lastWeek,
                'vs_yesterday' => [
                    'alerts_change' => $this->calculateChange($yesterday['total_alerts'], $today['total_alerts']),
                    'vm_alerts_change' => $this->calculateChange($yesterday['vm_alerts'], $today['vm_alerts']),
                ],
                'vs_last_week' => [
                    'alerts_change' => $this->calculateChange($lastWeek['total_alerts'], $today['total_alerts']),
                    'vm_alerts_change' => $this->calculateChange($lastWeek['vm_alerts'], $today['vm_alerts']),
                ],
            ];
        } catch (\Exception $e) {
            Log::warning('Comparisons calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get metrics for a specific date
     */
    private function getDateMetrics($date, $filters): array
    {
        try {
            $partition = 'alerts_' . $date->format('Y_m_d');
            
            if (!$this->tableExists('pgsql', $partition)) {
                return ['total_alerts' => 0, 'vm_alerts' => 0];
            }

            $query = DB::connection('pgsql')->table($partition);
            $this->applyFilters($query, $filters);
            $totalAlerts = $query->count();

            $vmQuery = DB::connection('pgsql')->table($partition)
                ->whereIn('status', ['O', 'C'])
                ->where('sendtoclient', 'S');
            $this->applyFilters($vmQuery, $filters);
            $vmAlerts = $vmQuery->count();

            return [
                'total_alerts' => $totalAlerts,
                'vm_alerts' => $vmAlerts,
            ];
        } catch (\Exception $e) {
            return ['total_alerts' => 0, 'vm_alerts' => 0];
        }
    }

    /**
     * Get Down Sites
     */
    private function getDownSites($date): array
    {
        try {
            $downComm = DB::connection('mysql')
                ->table('down_communication')
                ->select('atm_id', 'panel_id', 'dc_date')
                ->where(function($query) use ($date) {
                    $query->whereDate('dc_date', '!=', $date->format('Y-m-d'))
                          ->orWhereNull('dc_date');
                })
                ->limit(20)
                ->get();

            return $downComm->map(function($item) use ($date) {
                $downSince = $item->dc_date ? Carbon::parse($item->dc_date) : null;
                $hoursDown = $downSince ? $downSince->diffInHours($date) : 0;

                return [
                    'atm_id' => $item->atm_id,
                    'panel_id' => $item->panel_id,
                    'down_since' => $item->dc_date,
                    'hours_down' => $hoursDown,
                ];
            })->toArray();
        } catch (\Exception $e) {
            Log::warning('Down sites calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // Helper methods

    private function applyFilters($query, $filters)
    {
        if (!empty($filters['customer']) || !empty($filters['zone']) || !empty($filters['panel_type'])) {
            // Get panel IDs that match the filters
            $sitesQuery = DB::connection('pgsql')->table('sites');
            
            if (!empty($filters['customer'])) {
                $sitesQuery->where('Customer', $filters['customer']);
            }
            if (!empty($filters['zone'])) {
                $sitesQuery->where('Zone', $filters['zone']);
            }
            if (!empty($filters['panel_type'])) {
                $sitesQuery->where('PanelType', $filters['panel_type']);
            }
            
            $panelIds = $sitesQuery->pluck('NewPanelID')->toArray();
            
            if (!empty($panelIds)) {
                $query->whereIn('panelid', $panelIds);
            } else {
                // No matching sites, return empty
                $query->whereRaw('1 = 0');
            }
        }
    }

    private function calculateChange($old, $new): float
    {
        if ($old == 0) return $new > 0 ? 100 : 0;
        return round((($new - $old) / $old) * 100, 1);
    }

    private function tableExists($connection, $tableName): bool
    {
        try {
            return DB::connection($connection)
                ->getSchemaBuilder()
                ->hasTable($tableName);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function getEmptySummary(): array
    {
        return [
            'total_alerts' => 0,
            'vm_alerts' => 0,
            'unique_sites_with_alerts' => 0,
            'total_sites' => 0,
            'down_sites' => 0,
            'healthy_sites' => 0,
        ];
    }
}
