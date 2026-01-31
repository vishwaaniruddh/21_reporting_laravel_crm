<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MonthlyReportController - Monthly operational reports
 * 
 * Provides comprehensive monthly breakdown with trends and insights
 */
class MonthlyReportController extends Controller
{
    /**
     * GET /api/reports/monthly
     * 
     * Get comprehensive monthly report
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'year' => 'nullable|integer|min:2020|max:2030',
                'month' => 'nullable|integer|min:1|max:12',
                'customer' => 'nullable|string',
                'zone' => 'nullable|string',
            ]);

            // Default to current month
            $year = $validated['year'] ?? Carbon::now()->year;
            $month = $validated['month'] ?? Carbon::now()->month;
            
            $monthStart = Carbon::create($year, $month, 1)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();

            $filters = [
                'customer' => $validated['customer'] ?? null,
                'zone' => $validated['zone'] ?? null,
            ];

            $data = [
                'month_summary' => $this->getMonthSummary($monthStart, $monthEnd, $filters),
                'weekly_breakdown' => $this->getWeeklyBreakdown($monthStart, $monthEnd, $filters),
                'daily_trend' => $this->getDailyTrend($monthStart, $monthEnd, $filters),
                'top_sites' => $this->getTopSites($monthStart, $monthEnd, $filters),
                'customer_breakdown' => $this->getCustomerBreakdown($monthStart, $monthEnd, $filters),
                'zone_breakdown' => $this->getZoneBreakdown($monthStart, $monthEnd, $filters),
                'alert_type_distribution' => $this->getAlertTypeDistribution($monthStart, $monthEnd, $filters),
                'performance_metrics' => $this->getPerformanceMetrics($monthStart, $monthEnd, $filters),
                'month_comparison' => $this->getMonthComparison($monthStart, $filters),
                'site_reliability' => $this->getSiteReliability($monthStart, $monthEnd, $filters),
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
                'meta' => [
                    'year' => $year,
                    'month' => $month,
                    'month_name' => $monthStart->format('F Y'),
                    'month_start' => $monthStart->format('Y-m-d'),
                    'month_end' => $monthEnd->format('Y-m-d'),
                    'days_in_month' => $monthStart->daysInMonth,
                    'generated_at' => Carbon::now()->toIso8601String(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch monthly report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'REPORT_ERROR',
                    'message' => 'Failed to fetch monthly report',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Month Summary - Key metrics for the month
     */
    private function getMonthSummary($monthStart, $monthEnd, $filters): array
    {
        try {
            $totalAlerts = 0;
            $vmAlerts = 0;
            $daysWithData = 0;

            $current = $monthStart->copy();
            while ($current->lte($monthEnd)) {
                $partition = 'alerts_' . $current->format('Y_m_d');
                
                if ($this->tableExists('pgsql', $partition)) {
                    try {
                        $query = DB::connection('pgsql')->table($partition);
                        $this->applyFilters($query, $filters);
                        $dayTotal = $query->count();
                        
                        if ($dayTotal > 0) {
                            $totalAlerts += $dayTotal;
                            $daysWithData++;
                        }

                        $vmQuery = DB::connection('pgsql')->table($partition)
                            ->whereIn('status', ['O', 'C'])
                            ->where('sendtoclient', 'S');
                        $this->applyFilters($vmQuery, $filters);
                        $vmAlerts += $vmQuery->count();
                    } catch (\Exception $e) {
                        Log::warning("Failed to query partition {$partition}", ['error' => $e->getMessage()]);
                    }
                }
                
                $current->addDay();
            }

            $avgDaily = $daysWithData > 0 ? $totalAlerts / $daysWithData : 0;
            $totalSites = DB::connection('pgsql')->table('sites')->count();

            return [
                'total_alerts' => $totalAlerts,
                'vm_alerts' => $vmAlerts,
                'avg_daily_alerts' => round($avgDaily, 2),
                'days_with_data' => $daysWithData,
                'total_sites' => $totalSites,
                'alerts_per_site' => $totalSites > 0 ? round($totalAlerts / $totalSites, 2) : 0,
            ];
        } catch (\Exception $e) {
            Log::warning('Month summary calculation failed', ['error' => $e->getMessage()]);
            return [
                'total_alerts' => 0,
                'vm_alerts' => 0,
                'avg_daily_alerts' => 0,
                'days_with_data' => 0,
                'total_sites' => 0,
                'alerts_per_site' => 0,
            ];
        }
    }

    /**
     * Weekly Breakdown - Break month into weeks
     */
    private function getWeeklyBreakdown($monthStart, $monthEnd, $filters): array
    {
        try {
            $weeks = [];
            $current = $monthStart->copy()->startOfWeek();
            $weekNum = 1;

            while ($current->lte($monthEnd)) {
                $weekEnd = $current->copy()->endOfWeek();
                if ($weekEnd->gt($monthEnd)) {
                    $weekEnd = $monthEnd->copy();
                }

                $weekAlerts = 0;
                $weekVmAlerts = 0;
                $day = $current->copy();

                while ($day->lte($weekEnd)) {
                    if ($day->gte($monthStart) && $day->lte($monthEnd)) {
                        $partition = 'alerts_' . $day->format('Y_m_d');
                        
                        if ($this->tableExists('pgsql', $partition)) {
                            try {
                                $query = DB::connection('pgsql')->table($partition);
                                $this->applyFilters($query, $filters);
                                $weekAlerts += $query->count();

                                $vmQuery = DB::connection('pgsql')->table($partition)
                                    ->whereIn('status', ['O', 'C'])
                                    ->where('sendtoclient', 'S');
                                $this->applyFilters($vmQuery, $filters);
                                $weekVmAlerts += $vmQuery->count();
                            } catch (\Exception $e) {
                                Log::warning("Failed to query partition {$partition}", ['error' => $e->getMessage()]);
                            }
                        }
                    }
                    $day->addDay();
                }

                $weeks[] = [
                    'week_number' => $weekNum,
                    'week_start' => max($current, $monthStart)->format('Y-m-d'),
                    'week_end' => min($weekEnd, $monthEnd)->format('Y-m-d'),
                    'total_alerts' => $weekAlerts,
                    'vm_alerts' => $weekVmAlerts,
                ];

                $current->addWeek();
                $weekNum++;
            }

            // Calculate week-to-week changes
            for ($i = 1; $i < count($weeks); $i++) {
                $prev = $weeks[$i - 1]['total_alerts'];
                $curr = $weeks[$i]['total_alerts'];
                $weeks[$i]['change_from_previous'] = $this->calculateChange($prev, $curr);
            }

            return $weeks;
        } catch (\Exception $e) {
            Log::warning('Weekly breakdown calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Daily Trend - All days in the month
     */
    private function getDailyTrend($monthStart, $monthEnd, $filters): array
    {
        try {
            $trend = [];
            $current = $monthStart->copy();

            while ($current->lte($monthEnd)) {
                $partition = 'alerts_' . $current->format('Y_m_d');
                $totalAlerts = 0;
                $vmAlerts = 0;

                if ($this->tableExists('pgsql', $partition)) {
                    try {
                        $query = DB::connection('pgsql')->table($partition);
                        $this->applyFilters($query, $filters);
                        $totalAlerts = $query->count();

                        $vmQuery = DB::connection('pgsql')->table($partition)
                            ->whereIn('status', ['O', 'C'])
                            ->where('sendtoclient', 'S');
                        $this->applyFilters($vmQuery, $filters);
                        $vmAlerts = $vmQuery->count();
                    } catch (\Exception $e) {
                        Log::warning("Failed to query partition {$partition}", ['error' => $e->getMessage()]);
                    }
                }

                $trend[] = [
                    'date' => $current->format('Y-m-d'),
                    'day' => $current->day,
                    'day_name' => $current->format('D'),
                    'total_alerts' => $totalAlerts,
                    'vm_alerts' => $vmAlerts,
                ];

                $current->addDay();
            }

            return $trend;
        } catch (\Exception $e) {
            Log::warning('Daily trend calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Top Sites - Sites with most alerts during the month
     */
    private function getTopSites($monthStart, $monthEnd, $filters): array
    {
        try {
            $alertCounts = [];
            $current = $monthStart->copy();

            while ($current->lte($monthEnd)) {
                $partition = 'alerts_' . $current->format('Y_m_d');
                
                if ($this->tableExists('pgsql', $partition)) {
                    try {
                        $query = DB::connection('pgsql')
                            ->table($partition)
                            ->select('panelid', DB::raw('COUNT(*) as count'))
                            ->whereNotNull('panelid')
                            ->where('panelid', '!=', '')
                            ->groupBy('panelid');
                        
                        $this->applyFilters($query, $filters);
                        $dayCounts = $query->get();

                        foreach ($dayCounts as $count) {
                            if (!isset($alertCounts[$count->panelid])) {
                                $alertCounts[$count->panelid] = 0;
                            }
                            $alertCounts[$count->panelid] += $count->count;
                        }
                    } catch (\Exception $e) {
                        Log::warning("Failed to query partition {$partition}", ['error' => $e->getMessage()]);
                    }
                }
                
                $current->addDay();
            }

            arsort($alertCounts);
            $topPanelIds = array_slice(array_keys($alertCounts), 0, 20, true);

            $sites = DB::connection('pgsql')
                ->table('sites')
                ->whereIn('NewPanelID', $topPanelIds)
                ->select('NewPanelID', 'ATMID', 'ATMShortName', 'Customer', 'City', 'Zone')
                ->get()
                ->keyBy('NewPanelID');

            $result = [];
            foreach ($topPanelIds as $panelId) {
                $site = $sites->get($panelId);
                $result[] = [
                    'panel_id' => $panelId,
                    'atmid' => $site->ATMID ?? 'Unknown',
                    'site_name' => $site->ATMShortName ?? 'Unknown',
                    'customer' => $site->Customer ?? 'Unknown',
                    'city' => $site->City ?? 'Unknown',
                    'zone' => $site->Zone ?? 'Unknown',
                    'alert_count' => $alertCounts[$panelId],
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::warning('Top sites calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Customer Breakdown
     */
    private function getCustomerBreakdown($monthStart, $monthEnd, $filters): array
    {
        try {
            $alertCounts = [];
            $current = $monthStart->copy();

            while ($current->lte($monthEnd)) {
                $partition = 'alerts_' . $current->format('Y_m_d');
                
                if ($this->tableExists('pgsql', $partition)) {
                    try {
                        $query = DB::connection('pgsql')
                            ->table($partition)
                            ->select('panelid', DB::raw('COUNT(*) as count'))
                            ->whereNotNull('panelid')
                            ->where('panelid', '!=', '')
                            ->groupBy('panelid');
                        
                        $this->applyFilters($query, $filters);
                        $dayCounts = $query->get()->keyBy('panelid');

                        $panelIds = $dayCounts->keys()->toArray();
                        if (!empty($panelIds)) {
                            $sites = DB::connection('pgsql')
                                ->table('sites')
                                ->whereIn('NewPanelID', $panelIds)
                                ->select('NewPanelID', 'Customer')
                                ->get();

                            foreach ($sites as $site) {
                                $customer = $site->Customer ?? 'Unknown';
                                $count = $dayCounts[$site->NewPanelID]->count ?? 0;
                                
                                if (!isset($alertCounts[$customer])) {
                                    $alertCounts[$customer] = 0;
                                }
                                $alertCounts[$customer] += $count;
                            }
                        }
                    } catch (\Exception $e) {
                        Log::warning("Failed to query partition {$partition}", ['error' => $e->getMessage()]);
                    }
                }
                
                $current->addDay();
            }

            arsort($alertCounts);
            $total = array_sum($alertCounts);
            
            return array_map(function($customer, $count) use ($total) {
                return [
                    'customer' => $customer,
                    'alert_count' => $count,
                    'percentage' => $total > 0 ? round(($count / $total) * 100, 2) : 0,
                ];
            }, array_keys($alertCounts), $alertCounts);
        } catch (\Exception $e) {
            Log::warning('Customer breakdown calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Zone Breakdown
     */
    private function getZoneBreakdown($monthStart, $monthEnd, $filters): array
    {
        try {
            $alertCounts = [];
            $current = $monthStart->copy();

            while ($current->lte($monthEnd)) {
                $partition = 'alerts_' . $current->format('Y_m_d');
                
                if ($this->tableExists('pgsql', $partition)) {
                    try {
                        $query = DB::connection('pgsql')
                            ->table($partition)
                            ->select('panelid', DB::raw('COUNT(*) as count'))
                            ->whereNotNull('panelid')
                            ->where('panelid', '!=', '')
                            ->groupBy('panelid');
                        
                        $this->applyFilters($query, $filters);
                        $dayCounts = $query->get()->keyBy('panelid');

                        $panelIds = $dayCounts->keys()->toArray();
                        if (!empty($panelIds)) {
                            $sites = DB::connection('pgsql')
                                ->table('sites')
                                ->whereIn('NewPanelID', $panelIds)
                                ->select('NewPanelID', 'Zone')
                                ->get();

                            foreach ($sites as $site) {
                                $zone = $site->Zone ?? 'Unknown';
                                $count = $dayCounts[$site->NewPanelID]->count ?? 0;
                                
                                if (!isset($alertCounts[$zone])) {
                                    $alertCounts[$zone] = 0;
                                }
                                $alertCounts[$zone] += $count;
                            }
                        }
                    } catch (\Exception $e) {
                        Log::warning("Failed to query partition {$partition}", ['error' => $e->getMessage()]);
                    }
                }
                
                $current->addDay();
            }

            arsort($alertCounts);
            $total = array_sum($alertCounts);
            
            return array_map(function($zone, $count) use ($total) {
                return [
                    'zone' => $zone,
                    'alert_count' => $count,
                    'percentage' => $total > 0 ? round(($count / $total) * 100, 2) : 0,
                ];
            }, array_keys($alertCounts), $alertCounts);
        } catch (\Exception $e) {
            Log::warning('Zone breakdown calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Alert Type Distribution
     */
    private function getAlertTypeDistribution($monthStart, $monthEnd, $filters): array
    {
        try {
            $alertTypes = [];
            $current = $monthStart->copy();

            while ($current->lte($monthEnd)) {
                $partition = 'alerts_' . $current->format('Y_m_d');
                
                if ($this->tableExists('pgsql', $partition)) {
                    try {
                        $query = DB::connection('pgsql')
                            ->table($partition)
                            ->select('alerttype', DB::raw('COUNT(*) as count'))
                            ->groupBy('alerttype');
                        
                        $this->applyFilters($query, $filters);
                        $types = $query->get();

                        foreach ($types as $type) {
                            $typeName = $type->alerttype ?? 'Unknown';
                            if (!isset($alertTypes[$typeName])) {
                                $alertTypes[$typeName] = 0;
                            }
                            $alertTypes[$typeName] += $type->count;
                        }
                    } catch (\Exception $e) {
                        Log::warning("Failed to query partition {$partition}", ['error' => $e->getMessage()]);
                    }
                }
                
                $current->addDay();
            }

            arsort($alertTypes);
            $total = array_sum($alertTypes);
            
            return array_slice(array_map(function($type, $count) use ($total) {
                return [
                    'type' => $type,
                    'count' => $count,
                    'percentage' => $total > 0 ? round(($count / $total) * 100, 2) : 0,
                ];
            }, array_keys($alertTypes), $alertTypes), 0, 15);
        } catch (\Exception $e) {
            Log::warning('Alert type distribution calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Performance Metrics
     */
    private function getPerformanceMetrics($monthStart, $monthEnd, $filters): array
    {
        try {
            $dailyCounts = [];
            $current = $monthStart->copy();

            while ($current->lte($monthEnd)) {
                $partition = 'alerts_' . $current->format('Y_m_d');
                
                if ($this->tableExists('pgsql', $partition)) {
                    try {
                        $query = DB::connection('pgsql')->table($partition);
                        $this->applyFilters($query, $filters);
                        $dailyCounts[] = $query->count();
                    } catch (\Exception $e) {
                        Log::warning("Failed to query partition {$partition}", ['error' => $e->getMessage()]);
                    }
                }
                
                $current->addDay();
            }

            if (empty($dailyCounts)) {
                return [
                    'peak_day_alerts' => 0,
                    'lowest_day_alerts' => 0,
                    'median_daily_alerts' => 0,
                    'std_deviation' => 0,
                ];
            }

            sort($dailyCounts);
            $count = count($dailyCounts);
            $median = $count % 2 == 0 
                ? ($dailyCounts[$count/2 - 1] + $dailyCounts[$count/2]) / 2 
                : $dailyCounts[floor($count/2)];

            $mean = array_sum($dailyCounts) / $count;
            $variance = array_sum(array_map(function($x) use ($mean) {
                return pow($x - $mean, 2);
            }, $dailyCounts)) / $count;
            $stdDev = sqrt($variance);

            return [
                'peak_day_alerts' => max($dailyCounts),
                'lowest_day_alerts' => min($dailyCounts),
                'median_daily_alerts' => round($median, 2),
                'std_deviation' => round($stdDev, 2),
            ];
        } catch (\Exception $e) {
            Log::warning('Performance metrics calculation failed', ['error' => $e->getMessage()]);
            return [
                'peak_day_alerts' => 0,
                'lowest_day_alerts' => 0,
                'median_daily_alerts' => 0,
                'std_deviation' => 0,
            ];
        }
    }

    /**
     * Month Comparison - This month vs last month
     */
    private function getMonthComparison($monthStart, $filters): array
    {
        try {
            $thisMonthEnd = $monthStart->copy()->endOfMonth();
            $lastMonthStart = $monthStart->copy()->subMonth()->startOfMonth();
            $lastMonthEnd = $lastMonthStart->copy()->endOfMonth();

            $thisMonth = $this->getMonthSummary($monthStart, $thisMonthEnd, $filters);
            $lastMonth = $this->getMonthSummary($lastMonthStart, $lastMonthEnd, $filters);

            return [
                'this_month' => $thisMonth,
                'last_month' => $lastMonth,
                'changes' => [
                    'total_alerts' => $this->calculateChange($lastMonth['total_alerts'], $thisMonth['total_alerts']),
                    'vm_alerts' => $this->calculateChange($lastMonth['vm_alerts'], $thisMonth['vm_alerts']),
                    'avg_daily' => $this->calculateChange($lastMonth['avg_daily_alerts'], $thisMonth['avg_daily_alerts']),
                ],
            ];
        } catch (\Exception $e) {
            Log::warning('Month comparison calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Site Reliability - Sites categorized by alert frequency
     */
    private function getSiteReliability($monthStart, $monthEnd, $filters): array
    {
        try {
            $siteAlertCounts = [];
            $current = $monthStart->copy();

            while ($current->lte($monthEnd)) {
                $partition = 'alerts_' . $current->format('Y_m_d');
                
                if ($this->tableExists('pgsql', $partition)) {
                    try {
                        $query = DB::connection('pgsql')
                            ->table($partition)
                            ->select('panelid', DB::raw('COUNT(*) as count'))
                            ->whereNotNull('panelid')
                            ->where('panelid', '!=', '')
                            ->groupBy('panelid');
                        
                        $this->applyFilters($query, $filters);
                        $counts = $query->get();

                        foreach ($counts as $count) {
                            if (!isset($siteAlertCounts[$count->panelid])) {
                                $siteAlertCounts[$count->panelid] = 0;
                            }
                            $siteAlertCounts[$count->panelid] += $count->count;
                        }
                    } catch (\Exception $e) {
                        Log::warning("Failed to query partition {$partition}", ['error' => $e->getMessage()]);
                    }
                }
                
                $current->addDay();
            }

            $excellent = 0; // 0-10 alerts
            $good = 0;      // 11-50 alerts
            $fair = 0;      // 51-100 alerts
            $poor = 0;      // 101+ alerts

            foreach ($siteAlertCounts as $count) {
                if ($count <= 10) $excellent++;
                elseif ($count <= 50) $good++;
                elseif ($count <= 100) $fair++;
                else $poor++;
            }

            $totalSites = DB::connection('pgsql')->table('sites')->count();
            $sitesWithAlerts = count($siteAlertCounts);
            $sitesWithoutAlerts = $totalSites - $sitesWithAlerts;

            return [
                'excellent' => $excellent,
                'good' => $good,
                'fair' => $fair,
                'poor' => $poor,
                'no_alerts' => $sitesWithoutAlerts,
                'total_sites' => $totalSites,
            ];
        } catch (\Exception $e) {
            Log::warning('Site reliability calculation failed', ['error' => $e->getMessage()]);
            return [
                'excellent' => 0,
                'good' => 0,
                'fair' => 0,
                'poor' => 0,
                'no_alerts' => 0,
                'total_sites' => 0,
            ];
        }
    }

    // Helper methods

    private function applyFilters($query, $filters)
    {
        if (!empty($filters['customer']) || !empty($filters['zone'])) {
            $sitesQuery = DB::connection('pgsql')->table('sites');
            
            if (!empty($filters['customer'])) {
                $sitesQuery->where('Customer', $filters['customer']);
            }
            if (!empty($filters['zone'])) {
                $sitesQuery->where('Zone', $filters['zone']);
            }
            
            $panelIds = $sitesQuery->pluck('NewPanelID')->toArray();
            
            if (!empty($panelIds)) {
                $query->whereIn('panelid', $panelIds);
            } else {
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
}
