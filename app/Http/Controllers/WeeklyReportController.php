<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WeeklyReportController - Weekly operational reports
 * 
 * Provides comprehensive weekly breakdown with trends and patterns
 */
class WeeklyReportController extends Controller
{
    /**
     * GET /api/reports/weekly
     * 
     * Get comprehensive weekly report
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'week_start' => 'nullable|date',
                'customer' => 'nullable|string',
                'zone' => 'nullable|string',
            ]);

            // Default to current week (Monday to Sunday)
            $weekStart = isset($validated['week_start']) 
                ? Carbon::parse($validated['week_start'])->startOfWeek()
                : Carbon::now()->startOfWeek();
            
            $weekEnd = $weekStart->copy()->endOfWeek();

            $filters = [
                'customer' => $validated['customer'] ?? null,
                'zone' => $validated['zone'] ?? null,
            ];

            $data = [
                'week_summary' => $this->getWeekSummary($weekStart, $weekEnd, $filters),
                'daily_breakdown' => $this->getDailyBreakdown($weekStart, $weekEnd, $filters),
                'top_sites' => $this->getTopSites($weekStart, $weekEnd, $filters),
                'customer_breakdown' => $this->getCustomerBreakdown($weekStart, $weekEnd, $filters),
                'zone_breakdown' => $this->getZoneBreakdown($weekStart, $weekEnd, $filters),
                'alert_type_trends' => $this->getAlertTypeTrends($weekStart, $weekEnd, $filters),
                'patterns' => $this->getPatterns($weekStart, $weekEnd, $filters),
                'site_health_trends' => $this->getSiteHealthTrends($weekStart, $weekEnd, $filters),
                'week_comparison' => $this->getWeekComparison($weekStart, $filters),
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
                'meta' => [
                    'week_start' => $weekStart->format('Y-m-d'),
                    'week_end' => $weekEnd->format('Y-m-d'),
                    'generated_at' => Carbon::now()->toIso8601String(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch weekly report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'REPORT_ERROR',
                    'message' => 'Failed to fetch weekly report',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Week Summary - Key metrics for the week
     */
    private function getWeekSummary($weekStart, $weekEnd, $filters): array
    {
        try {
            $totalAlerts = 0;
            $vmAlerts = 0;
            $dailyCounts = [];

            // Get data for each day
            $current = $weekStart->copy();
            while ($current->lte($weekEnd)) {
                $partition = 'alerts_' . $current->format('Y_m_d');
                
                if ($this->tableExists('pgsql', $partition)) {
                    try {
                        $query = DB::connection('pgsql')->table($partition);
                        $this->applyFilters($query, $filters);
                        $dayTotal = $query->count();
                        $totalAlerts += $dayTotal;
                        $dailyCounts[$current->format('Y-m-d')] = $dayTotal;

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

            $avgDaily = count($dailyCounts) > 0 ? $totalAlerts / count($dailyCounts) : 0;
            $peakDay = count($dailyCounts) > 0 ? array_keys($dailyCounts, max($dailyCounts))[0] : null;
            $quietestDay = count($dailyCounts) > 0 ? array_keys($dailyCounts, min($dailyCounts))[0] : null;

            return [
                'total_alerts' => $totalAlerts,
                'vm_alerts' => $vmAlerts,
                'avg_daily_alerts' => round($avgDaily, 2),
                'peak_day' => $peakDay,
                'peak_day_count' => $peakDay ? $dailyCounts[$peakDay] : 0,
                'quietest_day' => $quietestDay,
                'quietest_day_count' => $quietestDay ? $dailyCounts[$quietestDay] : 0,
                'days_with_data' => count($dailyCounts),
            ];
        } catch (\Exception $e) {
            Log::warning('Week summary calculation failed', ['error' => $e->getMessage()]);
            return [
                'total_alerts' => 0,
                'vm_alerts' => 0,
                'avg_daily_alerts' => 0,
                'peak_day' => null,
                'peak_day_count' => 0,
                'quietest_day' => null,
                'quietest_day_count' => 0,
                'days_with_data' => 0,
            ];
        }
    }

    /**
     * Daily Breakdown - Day by day comparison
     */
    private function getDailyBreakdown($weekStart, $weekEnd, $filters): array
    {
        try {
            $breakdown = [];
            $current = $weekStart->copy();

            while ($current->lte($weekEnd)) {
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

                $breakdown[] = [
                    'date' => $current->format('Y-m-d'),
                    'day_name' => $current->format('l'),
                    'total_alerts' => $totalAlerts,
                    'vm_alerts' => $vmAlerts,
                ];

                $current->addDay();
            }

            // Calculate day-to-day changes
            for ($i = 1; $i < count($breakdown); $i++) {
                $prev = $breakdown[$i - 1]['total_alerts'];
                $curr = $breakdown[$i]['total_alerts'];
                $breakdown[$i]['change_from_previous'] = $this->calculateChange($prev, $curr);
            }

            return $breakdown;
        } catch (\Exception $e) {
            Log::warning('Daily breakdown calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Top Sites - Sites with most alerts during the week
     */
    private function getTopSites($weekStart, $weekEnd, $filters): array
    {
        try {
            $alertCounts = [];
            $current = $weekStart->copy();

            // Aggregate alerts across all days
            while ($current->lte($weekEnd)) {
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

            // Sort and get top 10
            arsort($alertCounts);
            $topPanelIds = array_slice(array_keys($alertCounts), 0, 10, true);

            // Get site details
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
    private function getCustomerBreakdown($weekStart, $weekEnd, $filters): array
    {
        try {
            $alertCounts = [];
            $current = $weekStart->copy();

            while ($current->lte($weekEnd)) {
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

                        // Get customer mapping
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
            
            return array_map(function($customer, $count) {
                return [
                    'customer' => $customer,
                    'alert_count' => $count,
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
    private function getZoneBreakdown($weekStart, $weekEnd, $filters): array
    {
        try {
            $alertCounts = [];
            $current = $weekStart->copy();

            while ($current->lte($weekEnd)) {
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

                        // Get zone mapping
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
            
            return array_map(function($zone, $count) {
                return [
                    'zone' => $zone,
                    'alert_count' => $count,
                ];
            }, array_keys($alertCounts), $alertCounts);
        } catch (\Exception $e) {
            Log::warning('Zone breakdown calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Alert Type Trends
     */
    private function getAlertTypeTrends($weekStart, $weekEnd, $filters): array
    {
        try {
            $alertTypes = [];
            $current = $weekStart->copy();

            while ($current->lte($weekEnd)) {
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
            }, array_keys($alertTypes), $alertTypes), 0, 10);
        } catch (\Exception $e) {
            Log::warning('Alert type trends calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Patterns - Busiest day and hour
     */
    private function getPatterns($weekStart, $weekEnd, $filters): array
    {
        try {
            $dayOfWeekCounts = array_fill(0, 7, 0);
            $hourCounts = array_fill(0, 24, 0);
            $current = $weekStart->copy();

            while ($current->lte($weekEnd)) {
                $partition = 'alerts_' . $current->format('Y_m_d');
                
                if ($this->tableExists('pgsql', $partition)) {
                    try {
                        // Day of week pattern
                        $query = DB::connection('pgsql')->table($partition);
                        $this->applyFilters($query, $filters);
                        $dayCount = $query->count();
                        $dayOfWeekCounts[$current->dayOfWeek] += $dayCount;

                        // Hour pattern
                        $hourQuery = DB::connection('pgsql')
                            ->table($partition)
                            ->select(DB::raw('EXTRACT(HOUR FROM receivedtime) as hour'), DB::raw('COUNT(*) as count'))
                            ->groupBy('hour');
                        $this->applyFilters($hourQuery, $filters);
                        $hours = $hourQuery->get();

                        foreach ($hours as $hour) {
                            $hourCounts[(int)$hour->hour] += $hour->count;
                        }
                    } catch (\Exception $e) {
                        Log::warning("Failed to query partition {$partition}", ['error' => $e->getMessage()]);
                    }
                }
                
                $current->addDay();
            }

            $busiestDayIndex = array_keys($dayOfWeekCounts, max($dayOfWeekCounts))[0];
            $busiestHour = array_keys($hourCounts, max($hourCounts))[0];

            $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

            return [
                'busiest_day_of_week' => $dayNames[$busiestDayIndex],
                'busiest_day_count' => $dayOfWeekCounts[$busiestDayIndex],
                'busiest_hour' => $busiestHour,
                'busiest_hour_count' => $hourCounts[$busiestHour],
                'day_of_week_distribution' => array_map(function($day, $count) use ($dayNames) {
                    return [
                        'day' => $dayNames[$day],
                        'count' => $count,
                    ];
                }, array_keys($dayOfWeekCounts), $dayOfWeekCounts),
            ];
        } catch (\Exception $e) {
            Log::warning('Patterns calculation failed', ['error' => $e->getMessage()]);
            return [
                'busiest_day_of_week' => 'Unknown',
                'busiest_day_count' => 0,
                'busiest_hour' => 0,
                'busiest_hour_count' => 0,
                'day_of_week_distribution' => [],
            ];
        }
    }

    /**
     * Site Health Trends - Sites with increasing/decreasing alerts
     */
    private function getSiteHealthTrends($weekStart, $weekEnd, $filters): array
    {
        try {
            $siteAlertsByDay = [];
            $current = $weekStart->copy();

            // Collect daily counts per site
            while ($current->lte($weekEnd)) {
                $partition = 'alerts_' . $current->format('Y_m_d');
                $dateKey = $current->format('Y-m-d');
                
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
                            if (!isset($siteAlertsByDay[$count->panelid])) {
                                $siteAlertsByDay[$count->panelid] = [];
                            }
                            $siteAlertsByDay[$count->panelid][$dateKey] = $count->count;
                        }
                    } catch (\Exception $e) {
                        Log::warning("Failed to query partition {$partition}", ['error' => $e->getMessage()]);
                    }
                }
                
                $current->addDay();
            }

            // Calculate trends
            $increasing = [];
            $decreasing = [];
            $consistent = [];

            foreach ($siteAlertsByDay as $panelId => $dailyCounts) {
                if (count($dailyCounts) < 2) continue;

                $values = array_values($dailyCounts);
                $firstHalf = array_slice($values, 0, ceil(count($values) / 2));
                $secondHalf = array_slice($values, ceil(count($values) / 2));

                $firstAvg = array_sum($firstHalf) / count($firstHalf);
                $secondAvg = array_sum($secondHalf) / count($secondHalf);

                $change = $this->calculateChange($firstAvg, $secondAvg);

                if ($change > 20) {
                    $increasing[$panelId] = ['change' => $change, 'total' => array_sum($values)];
                } elseif ($change < -20) {
                    $decreasing[$panelId] = ['change' => $change, 'total' => array_sum($values)];
                } else {
                    $consistent[$panelId] = ['total' => array_sum($values)];
                }
            }

            // Sort and limit
            uasort($increasing, function($a, $b) { return $b['total'] - $a['total']; });
            uasort($decreasing, function($a, $b) { return $b['total'] - $a['total']; });
            uasort($consistent, function($a, $b) { return $b['total'] - $a['total']; });

            return [
                'increasing_alerts' => count($increasing),
                'decreasing_alerts' => count($decreasing),
                'consistent_sites' => count($consistent),
                'top_increasing' => array_slice(array_keys($increasing), 0, 5),
                'top_decreasing' => array_slice(array_keys($decreasing), 0, 5),
            ];
        } catch (\Exception $e) {
            Log::warning('Site health trends calculation failed', ['error' => $e->getMessage()]);
            return [
                'increasing_alerts' => 0,
                'decreasing_alerts' => 0,
                'consistent_sites' => 0,
                'top_increasing' => [],
                'top_decreasing' => [],
            ];
        }
    }

    /**
     * Week Comparison - This week vs last week
     */
    private function getWeekComparison($weekStart, $filters): array
    {
        try {
            $thisWeekEnd = $weekStart->copy()->endOfWeek();
            $lastWeekStart = $weekStart->copy()->subWeek();
            $lastWeekEnd = $lastWeekStart->copy()->endOfWeek();

            $thisWeek = $this->getWeekSummary($weekStart, $thisWeekEnd, $filters);
            $lastWeek = $this->getWeekSummary($lastWeekStart, $lastWeekEnd, $filters);

            return [
                'this_week' => $thisWeek,
                'last_week' => $lastWeek,
                'changes' => [
                    'total_alerts' => $this->calculateChange($lastWeek['total_alerts'], $thisWeek['total_alerts']),
                    'vm_alerts' => $this->calculateChange($lastWeek['vm_alerts'], $thisWeek['vm_alerts']),
                    'avg_daily' => $this->calculateChange($lastWeek['avg_daily_alerts'], $thisWeek['avg_daily_alerts']),
                ],
            ];
        } catch (\Exception $e) {
            Log::warning('Week comparison calculation failed', ['error' => $e->getMessage()]);
            return [];
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
