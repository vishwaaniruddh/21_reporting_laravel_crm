<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * ExecutiveDashboardController - Executive Dashboard with 20 Data Points
 * 
 * Provides comprehensive metrics for executive-level decision making
 * with real-time data and predictive analytics.
 */
class ExecutiveDashboardController extends Controller
{
    /**
     * GET /api/dashboard/executive
     * 
     * Get all executive dashboard metrics
     */
    public function index(Request $request): JsonResponse
    {
        // Remove execution time limit for large date ranges
        set_time_limit(300); // 5 minutes max
        
        try {
            $validated = $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
                'refresh' => 'nullable|in:true,false,1,0',
            ]);

            $startDate = $validated['start_date'] ?? Carbon::now()->subDays(30)->format('Y-m-d');
            $endDate = $validated['end_date'] ?? Carbon::now()->format('Y-m-d');
            $refresh = filter_var($request->input('refresh', false), FILTER_VALIDATE_BOOLEAN);

            $cacheKey = "executive_dashboard_{$startDate}_{$endDate}";
            $cacheDuration = 300; // 5 minutes

            if ($refresh) {
                Cache::forget($cacheKey);
            }

            $data = Cache::remember($cacheKey, $cacheDuration, function () use ($startDate, $endDate) {
                return [
                    'health_score' => $this->calculateHealthScore($startDate, $endDate),
                    'critical_metrics' => $this->getCriticalMetrics($startDate, $endDate),
                    'alert_trends' => $this->getAlertTrends($startDate, $endDate),
                    'site_status' => $this->getSiteStatusDistribution(),
                    'top_sites' => $this->getTopSitesByAlerts($startDate, $endDate),
                    'response_time' => $this->getResponseTimeAnalysis($startDate, $endDate),
                    'customer_health' => $this->getCustomerHealthMatrix($startDate, $endDate),
                    'revenue_by_customer' => $this->getRevenueByCustomer($startDate, $endDate),
                    'team_performance' => $this->getTeamPerformance($startDate, $endDate),
                    'sla_compliance' => $this->getSLAComplianceByPriority($startDate, $endDate),
                    'critical_issues' => $this->getActiveCriticalIssues(),
                    'down_communication' => $this->getDownCommunicationSummary(),
                    'peak_hours' => $this->getPeakHoursHeatmap($startDate, $endDate),
                    'month_comparison' => $this->getMonthOverMonthComparison(),
                    'billing_status' => $this->getBillingStatus(),
                    'failure_predictions' => $this->getFailurePredictions(),
                    'regional_performance' => $this->getRegionalPerformance($startDate, $endDate),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'meta' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'generated_at' => Carbon::now()->toIso8601String(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch executive dashboard', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DASHBOARD_ERROR',
                    'message' => 'Failed to fetch executive dashboard',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * 1. Calculate Overall Health Score (0-100)
     */
    private function calculateHealthScore($startDate, $endDate): array
    {
        $uptime = $this->getUptimePercentage($startDate, $endDate);
        $sla = $this->getSLACompliancePercentage($startDate, $endDate);
        $responseTime = $this->getResponseTimeScore($startDate, $endDate);

        $healthScore = ($uptime * 0.4) + ($sla * 0.4) + ($responseTime * 0.2);

        return [
            'score' => round($healthScore, 1),
            'status' => $healthScore >= 90 ? 'excellent' : ($healthScore >= 75 ? 'good' : ($healthScore >= 60 ? 'fair' : 'poor')),
            'components' => [
                'uptime' => round($uptime, 1),
                'sla' => round($sla, 1),
                'response_time' => round($responseTime, 1),
            ],
        ];
    }

    /**
     * 2-4. Critical Metrics Cards
     */
    private function getCriticalMetrics($startDate, $endDate): array
    {
        try {
            // Total sites from PostgreSQL
            $totalSites = DB::connection('pgsql')->table('sites')->count();

            // Active alerts (not closed) from partitioned tables
            $activeAlerts = 0;
            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);
            $partitions = $this->getPartitionTablesForDateRange($start, $end);
            
            foreach ($partitions as $partition) {
                try {
                    $count = DB::connection('pgsql')
                        ->table($partition)
                        ->whereNotIn('status', ['close', 'closed', 'Close', 'Closed'])
                        ->count();
                    $activeAlerts += $count;
                } catch (\Exception $e) {
                    Log::warning("Failed to count alerts in {$partition}", ['error' => $e->getMessage()]);
                }
            }

            // Today's alerts count - use AlertsReportController logic
            $todayAlerts = $this->getTodayAlertsCount();

            // VM alerts count - use VMAlertController logic
            $vmAlerts = $this->getVMAlertCount();

            // Last 15 minutes alerts
            $last15MinAlerts = $this->getLast15MinAlertsCount();

            // Uptime percentage
            $uptimePercent = $this->getUptimePercentage($startDate, $endDate);

            // SLA compliance
            $slaCompliance = $this->getSLACompliancePercentage($startDate, $endDate);

            return [
                'total_sites' => $totalSites,
                'active_alerts' => $activeAlerts,
                'today_alerts' => $todayAlerts,
                'vm_alerts' => $vmAlerts,
                'last_15min_alerts' => $last15MinAlerts,
                'uptime_percent' => round($uptimePercent, 2),
                'sla_compliance' => round($slaCompliance, 2),
            ];
        } catch (\Exception $e) {
            Log::warning('Critical metrics calculation failed', ['error' => $e->getMessage()]);
            return [
                'total_sites' => 0,
                'active_alerts' => 0,
                'today_alerts' => 0,
                'vm_alerts' => 0,
                'last_15min_alerts' => 0,
                'uptime_percent' => 100,
                'sla_compliance' => 100,
            ];
        }
    }

    /**
     * Get today's alerts count using same logic as alerts-reports
     * Includes both alerts and backalerts partitions
     */
    private function getTodayAlertsCount(): int
    {
        try {
            $today = Carbon::today();
            $alertsPartition = 'alerts_' . $today->format('Y_m_d');
            $backalertsPartition = 'backalerts_' . $today->format('Y_m_d');
            
            $count = 0;
            
            // Count alerts partition
            if ($this->tableExists('pgsql', $alertsPartition)) {
                $count += DB::connection('pgsql')
                    ->table($alertsPartition)
                    ->count();
            }
            
            // Count backalerts partition
            if ($this->tableExists('pgsql', $backalertsPartition)) {
                $count += DB::connection('pgsql')
                    ->table($backalertsPartition)
                    ->count();
            }
            
            return $count;
        } catch (\Exception $e) {
            Log::warning("Failed to count today's alerts", ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Get VM alerts count using same logic as vm-alerts endpoint
     * VM alerts are filtered by: status IN ('O','C') AND sendtoclient = 'S'
     * Includes both alerts and backalerts partitions
     */
    private function getVMAlertCount(): int
    {
        try {
            $today = Carbon::today();
            $alertsPartition = 'alerts_' . $today->format('Y_m_d');
            $backalertsPartition = 'backalerts_' . $today->format('Y_m_d');
            
            $count = 0;
            
            // Count VM alerts from alerts partition
            if ($this->tableExists('pgsql', $alertsPartition)) {
                $count += DB::connection('pgsql')
                    ->table($alertsPartition)
                    ->whereIn('status', ['O', 'C'])
                    ->where('sendtoclient', 'S')
                    ->count();
            }
            
            // Count VM alerts from backalerts partition
            if ($this->tableExists('pgsql', $backalertsPartition)) {
                $count += DB::connection('pgsql')
                    ->table($backalertsPartition)
                    ->whereIn('status', ['O', 'C'])
                    ->where('sendtoclient', 'S')
                    ->count();
            }
            
            return $count;
        } catch (\Exception $e) {
            Log::warning("Failed to count VM alerts", ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Get last 15 minutes alerts count
     * Includes both alerts and backalerts partitions
     */
    private function getLast15MinAlertsCount(): int
    {
        try {
            $fifteenMinutesAgo = Carbon::now()->subMinutes(15);
            $alertsPartition = 'alerts_' . Carbon::now()->format('Y_m_d');
            $backalertsPartition = 'backalerts_' . Carbon::now()->format('Y_m_d');
            
            $count = 0;
            
            // Count from alerts partition
            if ($this->tableExists('pgsql', $alertsPartition)) {
                $count += DB::connection('pgsql')
                    ->table($alertsPartition)
                    ->where('receivedtime', '>=', $fifteenMinutesAgo)
                    ->count();
            }
            
            // Count from backalerts partition
            if ($this->tableExists('pgsql', $backalertsPartition)) {
                $count += DB::connection('pgsql')
                    ->table($backalertsPartition)
                    ->where('receivedtime', '>=', $fifteenMinutesAgo)
                    ->count();
            }
            
            return $count;
        } catch (\Exception $e) {
            Log::warning("Failed to count last 15 min alerts", ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * 5. Alert Trends (Last 30 days)
     * Shows Total Alerts vs VM Alerts daily comparison
     */
    private function getAlertTrends($startDate, $endDate): array
    {
        try {
            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);
            
            // Get list of partition tables for the date range
            $partitions = $this->getPartitionTablesForDateRange($start, $end);
            
            if (empty($partitions)) {
                return [];
            }
            
            $allTrends = [];
            
            // Query each partition table
            foreach ($partitions as $partition) {
                try {
                    // Get total alerts count by date
                    $totalAlerts = DB::connection('pgsql')
                        ->table($partition)
                        ->select(
                            DB::raw('DATE(receivedtime) as date'),
                            DB::raw('COUNT(*) as total')
                        )
                        ->whereBetween('receivedtime', [$startDate, $endDate])
                        ->groupBy('date')
                        ->get();
                    
                    // Get VM alerts count by date (status IN ('O','C') AND sendtoclient = 'S')
                    $vmAlerts = DB::connection('pgsql')
                        ->table($partition)
                        ->select(
                            DB::raw('DATE(receivedtime) as date'),
                            DB::raw('COUNT(*) as vm_count')
                        )
                        ->whereIn('status', ['O', 'C'])
                        ->where('sendtoclient', 'S')
                        ->whereBetween('receivedtime', [$startDate, $endDate])
                        ->groupBy('date')
                        ->get()
                        ->keyBy('date');
                    
                    foreach ($totalAlerts as $alert) {
                        $dateKey = $alert->date;
                        if (!isset($allTrends[$dateKey])) {
                            $allTrends[$dateKey] = [
                                'date' => $dateKey,
                                'total' => 0,
                                'vm_alerts' => 0,
                            ];
                        }
                        $allTrends[$dateKey]['total'] += $alert->total;
                        
                        // Add VM alerts count if exists
                        if (isset($vmAlerts[$dateKey])) {
                            $allTrends[$dateKey]['vm_alerts'] += $vmAlerts[$dateKey]->vm_count;
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to query partition {$partition}", ['error' => $e->getMessage()]);
                }
            }
            
            // Sort by date
            ksort($allTrends);
            
            return array_values($allTrends);
        } catch (\Exception $e) {
            Log::warning('Alert trends calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * 6. Site Status Distribution
     * Checks active/inactive status from sites, dvrsite, and dvronline tables
     * Returns detailed breakdown by table
     */
    private function getSiteStatusDistribution(): array
    {
        try {
            // PostgreSQL sites table (live='Y' is active)
            $pgSitesActive = DB::connection('pgsql')
                ->table('sites')
                ->where('live', 'Y')
                ->count();
            
            $pgSitesInactive = DB::connection('pgsql')
                ->table('sites')
                ->where(function($query) {
                    $query->where('live', '!=', 'Y')
                          ->orWhereNull('live');
                })
                ->count();
            
            $pgSitesTotal = $pgSitesActive + $pgSitesInactive;
            
            // MySQL dvrsite table (live='Y' is active)
            $dvrSitesActive = DB::connection('mysql')
                ->table('dvrsite')
                ->where('live', 'Y')
                ->count();
            
            $dvrSitesInactive = DB::connection('mysql')
                ->table('dvrsite')
                ->where(function($query) {
                    $query->where('live', '!=', 'Y')
                          ->orWhereNull('live');
                })
                ->count();
            
            $dvrSitesTotal = $dvrSitesActive + $dvrSitesInactive;
            
            // MySQL dvronline table (Status='Y' is active)
            $dvronlineActive = DB::connection('mysql')
                ->table('dvronline')
                ->where('Status', 'Y')
                ->count();
            
            $dvronlineInactive = DB::connection('mysql')
                ->table('dvronline')
                ->where(function($query) {
                    $query->where('Status', '!=', 'Y')
                          ->orWhereNull('Status');
                })
                ->count();
            
            $dvronlineTotal = $dvronlineActive + $dvronlineInactive;
            
            // Total active and inactive sites
            $totalActive = $pgSitesActive + $dvrSitesActive + $dvronlineActive;
            $totalInactive = $pgSitesInactive + $dvrSitesInactive + $dvronlineInactive;
            $totalSites = $totalActive + $totalInactive;

            return [
                'online' => $totalActive,
                'offline' => $totalInactive,
                'maintenance' => 0,
                'total' => $totalSites,
                'breakdown' => [
                    'sites' => [
                        'active' => $pgSitesActive,
                        'inactive' => $pgSitesInactive,
                        'total' => $pgSitesTotal,
                    ],
                    'dvrsite' => [
                        'active' => $dvrSitesActive,
                        'inactive' => $dvrSitesInactive,
                        'total' => $dvrSitesTotal,
                    ],
                    'dvronline' => [
                        'active' => $dvronlineActive,
                        'inactive' => $dvronlineInactive,
                        'total' => $dvronlineTotal,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            Log::warning('Site status distribution calculation failed', ['error' => $e->getMessage()]);
            return [
                'online' => 0,
                'offline' => 0,
                'maintenance' => 0,
                'total' => 0,
                'breakdown' => [
                    'sites' => ['active' => 0, 'inactive' => 0, 'total' => 0],
                    'dvrsite' => ['active' => 0, 'inactive' => 0, 'total' => 0],
                    'dvronline' => ['active' => 0, 'inactive' => 0, 'total' => 0],
                ],
            ];
        }
    }

    /**
     * 7. Top 10 Sites by Alert Volume
     * Queries partitioned alert tables and joins with sites
     */
    private function getTopSitesByAlerts($startDate, $endDate): array
    {
        try {
            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);
            $partitions = $this->getPartitionTablesForDateRange($start, $end);
            
            if (empty($partitions)) {
                return [];
            }
            
            $alertCounts = [];
            
            // Query each partition and aggregate by panelid
            foreach ($partitions as $partition) {
                try {
                    $counts = DB::connection('pgsql')
                        ->table($partition)
                        ->select('panelid', DB::raw('COUNT(*) as alert_count'))
                        ->whereBetween('receivedtime', [$startDate, $endDate])
                        ->whereNotNull('panelid')
                        ->where('panelid', '!=', '')
                        ->groupBy('panelid')
                        ->get();
                    
                    foreach ($counts as $count) {
                        if (!isset($alertCounts[$count->panelid])) {
                            $alertCounts[$count->panelid] = 0;
                        }
                        $alertCounts[$count->panelid] += $count->alert_count;
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to query partition {$partition}", ['error' => $e->getMessage()]);
                }
            }
            
            // Sort by alert count and get top 10
            arsort($alertCounts);
            $topPanelIds = array_slice(array_keys($alertCounts), 0, 10, true);
            
            // Get site details
            $sites = DB::connection('pgsql')
                ->table('sites')
                ->whereIn('NewPanelID', $topPanelIds)
                ->select('NewPanelID', 'ATMID', 'ATMShortName', 'Customer', 'City')
                ->get()
                ->keyBy('NewPanelID');
            
            $result = [];
            foreach ($topPanelIds as $panelId) {
                $site = $sites->get($panelId);
                $result[] = [
                    'site_id' => $panelId,
                    'atmid' => $site->ATMID ?? 'Unknown',
                    'name' => $site->ATMShortName ?? 'Unknown',
                    'customer' => $site->Customer ?? 'Unknown',
                    'city' => $site->City ?? 'Unknown',
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
     * 8. Response Time Analysis
     */
    private function getResponseTimeAnalysis($startDate, $endDate): array
    {
        try {
            // Check if tickets table exists
            if (!$this->tableExists('pgsql', 'tickets')) {
                return $this->getMockResponseTimeData($startDate, $endDate);
            }

            $responseData = DB::connection('pgsql')
                ->table('tickets')
                ->select(
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('AVG(EXTRACT(EPOCH FROM (first_response_at - created_at))/60) as avg_response_minutes'),
                    DB::raw('COUNT(*) as ticket_count')
                )
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNotNull('first_response_at')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            $slaTarget = 30; // 30 minutes SLA target

            return $responseData->map(function ($item) use ($slaTarget) {
                return [
                    'date' => $item->date,
                    'avg_response_time' => round($item->avg_response_minutes, 2),
                    'ticket_count' => $item->ticket_count,
                    'sla_breach' => $item->avg_response_minutes > $slaTarget,
                ];
            })->toArray();
        } catch (\Exception $e) {
            Log::warning('Response time analysis failed', ['error' => $e->getMessage()]);
            return $this->getMockResponseTimeData($startDate, $endDate);
        }
    }

    /**
     * 9. Customer Health Matrix
     * Calculates health metrics per customer using partitioned alert tables
     */
    private function getCustomerHealthMatrix($startDate, $endDate): array
    {
        try {
            $customers = DB::connection('pgsql')
                ->table('sites')
                ->select('Customer')
                ->distinct()
                ->whereNotNull('Customer')
                ->where('Customer', '!=', '')
                ->get();

            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);
            $partitions = $this->getPartitionTablesForDateRange($start, $end);
            
            $healthMatrix = [];

            foreach ($customers as $customer) {
                // Get sites for this customer
                $customerSites = DB::connection('pgsql')
                    ->table('sites')
                    ->where('Customer', $customer->Customer)
                    ->select('NewPanelID')
                    ->pluck('NewPanelID')
                    ->toArray();
                
                $siteCount = count($customerSites);
                
                if ($siteCount == 0) {
                    continue;
                }
                
                // Count alerts for this customer's sites from partitioned tables
                $alertCount = 0;
                foreach ($partitions as $partition) {
                    try {
                        $count = DB::connection('pgsql')
                            ->table($partition)
                            ->whereIn('panelid', $customerSites)
                            ->whereBetween('receivedtime', [$startDate, $endDate])
                            ->count();
                        $alertCount += $count;
                    } catch (\Exception $e) {
                        Log::warning("Failed to query partition {$partition}", ['error' => $e->getMessage()]);
                    }
                }

                $slaCompliance = $this->getCustomerSLACompliance($customer->Customer, $startDate, $endDate);
                $healthScore = $this->calculateCustomerHealth($siteCount, $alertCount, $slaCompliance);

                $healthMatrix[] = [
                    'customer' => $customer->Customer,
                    'site_count' => $siteCount,
                    'alert_count' => $alertCount,
                    'sla_compliance' => round($slaCompliance, 1),
                    'health_score' => round($healthScore, 1),
                    'status' => $healthScore >= 80 ? 'healthy' : ($healthScore >= 60 ? 'warning' : 'critical'),
                ];
            }

            // Sort by health score
            usort($healthMatrix, function ($a, $b) {
                return $b['health_score'] <=> $a['health_score'];
            });

            return array_slice($healthMatrix, 0, 10); // Top 10 customers
        } catch (\Exception $e) {
            Log::warning('Customer health matrix calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * 10. Revenue by Customer
     */
    private function getRevenueByCustomer($startDate, $endDate): array
    {
        // Mock data - replace with actual billing table
        $revenueData = DB::connection('pgsql')
            ->table('sites')
            ->select('Customer', DB::raw('COUNT(*) * 1000 as estimated_revenue'))
            ->whereNotNull('Customer')
            ->where('Customer', '!=', '')
            ->groupBy('Customer')
            ->orderByDesc('estimated_revenue')
            ->limit(10)
            ->get();

        $totalRevenue = $revenueData->sum('estimated_revenue');

        return $revenueData->map(function ($item) use ($totalRevenue) {
            return [
                'customer' => $item->Customer,
                'revenue' => $item->estimated_revenue,
                'percentage' => round(($item->estimated_revenue / $totalRevenue) * 100, 1),
            ];
        })->toArray();
    }

    /**
     * 11. Team Performance
     */
    private function getTeamPerformance($startDate, $endDate): array
    {
        try {
            if (!$this->tableExists('pgsql', 'tickets')) {
                return [];
            }

            $performance = DB::connection('pgsql')
                ->table('tickets')
                ->select(
                    'assigned_to',
                    DB::raw('COUNT(*) as total_tickets'),
                    DB::raw('COUNT(CASE WHEN status = \'resolved\' THEN 1 END) as resolved_tickets'),
                    DB::raw('AVG(EXTRACT(EPOCH FROM (resolved_at - created_at))/3600) as avg_resolution_hours'),
                    DB::raw('AVG(EXTRACT(EPOCH FROM (first_response_at - created_at))/60) as avg_first_response_minutes')
                )
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNotNull('assigned_to')
                ->groupBy('assigned_to')
                ->get();

            // Get user details
            $userIds = $performance->pluck('assigned_to')->toArray();
            $users = DB::connection('pgsql')
                ->table('users')
                ->whereIn('id', $userIds)
                ->select('id', 'name', 'email')
                ->get()
                ->keyBy('id');

            return $performance->map(function ($item) use ($users) {
                $user = $users->get($item->assigned_to);
                return [
                    'user_id' => $item->assigned_to,
                    'name' => $user->name ?? 'Unknown',
                    'email' => $user->email ?? '',
                    'total_tickets' => $item->total_tickets,
                    'resolved_tickets' => $item->resolved_tickets,
                    'resolution_rate' => round(($item->resolved_tickets / $item->total_tickets) * 100, 1),
                    'avg_resolution_hours' => round($item->avg_resolution_hours, 2),
                    'avg_first_response_minutes' => round($item->avg_first_response_minutes, 2),
                ];
            })->toArray();
        } catch (\Exception $e) {
            Log::warning('Team performance calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * 12. SLA Compliance by Priority
     */
    private function getSLAComplianceByPriority($startDate, $endDate): array
    {
        try {
            if (!$this->tableExists('pgsql', 'tickets')) {
                return [];
            }

            $slaTargets = [
                'critical' => 15, // 15 minutes
                'high' => 30,     // 30 minutes
                'medium' => 60,   // 1 hour
                'low' => 120,     // 2 hours
            ];

            $compliance = [];

            foreach ($slaTargets as $priority => $targetMinutes) {
                $tickets = DB::connection('pgsql')
                    ->table('tickets')
                    ->select(
                        DB::raw('COUNT(*) as total'),
                        DB::raw('COUNT(CASE WHEN EXTRACT(EPOCH FROM (first_response_at - created_at))/60 <= ' . $targetMinutes . ' THEN 1 END) as within_sla')
                    )
                    ->where('priority', $priority)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->whereNotNull('first_response_at')
                    ->first();

                $compliancePercent = $tickets->total > 0 
                    ? ($tickets->within_sla / $tickets->total) * 100 
                    : 100;

                $compliance[] = [
                    'priority' => $priority,
                    'total_tickets' => $tickets->total,
                    'within_sla' => $tickets->within_sla,
                    'compliance_percent' => round($compliancePercent, 1),
                    'target_minutes' => $targetMinutes,
                ];
            }

            return $compliance;
        } catch (\Exception $e) {
            Log::warning('SLA compliance by priority calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * 13. Active Critical Issues
     * Queries recent partitioned tables for critical alerts
     */
    private function getActiveCriticalIssues(): array
    {
        try {
            // Get partitions for last 7 days
            $end = Carbon::now();
            $start = Carbon::now()->subDays(7);
            $partitions = $this->getPartitionTablesForDateRange($start, $end);
            
            if (empty($partitions)) {
                return [];
            }
            
            $criticalIssues = [];
            
            // Query each partition for critical alerts
            foreach ($partitions as $partition) {
                try {
                    $alerts = DB::connection('pgsql')
                        ->table($partition)
                        ->select('id', 'panelid', 'alerttype', 'alarm', 'receivedtime', 'status')
                        ->where('priority', 'critical')
                        ->whereNotIn('status', ['close', 'closed', 'Close', 'Closed'])
                        ->orderByDesc('receivedtime')
                        ->limit(10)
                        ->get();
                    
                    foreach ($alerts as $alert) {
                        $criticalIssues[] = $alert;
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to query critical issues from {$partition}", ['error' => $e->getMessage()]);
                }
            }
            
            // Sort by receivedtime and limit to 10
            usort($criticalIssues, function($a, $b) {
                return strtotime($b->receivedtime) - strtotime($a->receivedtime);
            });
            $criticalIssues = array_slice($criticalIssues, 0, 10);
            
            // Get site details
            $panelIds = array_unique(array_column($criticalIssues, 'panelid'));
            $sites = DB::connection('pgsql')
                ->table('sites')
                ->whereIn('NewPanelID', $panelIds)
                ->select('NewPanelID', 'ATMID', 'ATMShortName', 'Customer')
                ->get()
                ->keyBy('NewPanelID');
            
            return array_map(function ($alert) use ($sites) {
                $site = $sites->get($alert->panelid);
                return [
                    'id' => $alert->id,
                    'atmid' => $site->ATMID ?? 'Unknown',
                    'site_name' => $site->ATMShortName ?? 'Unknown',
                    'customer' => $site->Customer ?? 'Unknown',
                    'alert_type' => $alert->alerttype ?? 'Unknown',
                    'message' => $alert->alarm ?? 'No message',
                    'created_at' => $alert->receivedtime,
                    'status' => $alert->status,
                    'duration' => Carbon::parse($alert->receivedtime)->diffForHumans(),
                ];
            }, $criticalIssues);
        } catch (\Exception $e) {
            Log::warning('Critical issues calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * 14. Down Communication Summary
     */
    private function getDownCommunicationSummary(): array
    {
        $todayDate = date('Y-m-d');

        $downComm = DB::connection('mysql')
            ->table('down_communication')
            ->select('atm_id', 'panel_id', 'dc_date')
            ->get();

        $downSites = 0;
        $totalDowntime = 0;
        $criticalSites = 0;

        foreach ($downComm as $dc) {
            $dc_date_only = is_null($dc->dc_date) ? null : date('Y-m-d', strtotime($dc->dc_date));
            
            if ($dc_date_only !== $todayDate) {
                $downSites++;
                
                if ($dc_date_only) {
                    $downtime = Carbon::parse($dc_date_only)->diffInHours(Carbon::now());
                    $totalDowntime += $downtime;
                    
                    if ($downtime > 24) {
                        $criticalSites++;
                    }
                }
            }
        }

        $avgDowntime = $downSites > 0 ? $totalDowntime / $downSites : 0;

        return [
            'down_sites' => $downSites,
            'avg_downtime_hours' => round($avgDowntime, 1),
            'critical_sites' => $criticalSites,
            'impact_level' => $criticalSites > 10 ? 'high' : ($criticalSites > 5 ? 'medium' : 'low'),
        ];
    }

    /**
     * 15. Peak Hours Heatmap
     * Analyzes alert patterns by day of week and hour from partitioned tables
     */
    private function getPeakHoursHeatmap($startDate, $endDate): array
    {
        try {
            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);
            $partitions = $this->getPartitionTablesForDateRange($start, $end);
            
            if (empty($partitions)) {
                return [
                    'matrix' => array_fill(0, 7, array_fill(0, 24, 0)),
                    'days' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
                    'hours' => range(0, 23),
                ];
            }
            
            // Create 7x24 matrix
            $matrix = array_fill(0, 7, array_fill(0, 24, 0));
            
            // Query each partition
            foreach ($partitions as $partition) {
                try {
                    $heatmapData = DB::connection('pgsql')
                        ->table($partition)
                        ->select(
                            DB::raw('EXTRACT(DOW FROM receivedtime) as day_of_week'),
                            DB::raw('EXTRACT(HOUR FROM receivedtime) as hour'),
                            DB::raw('COUNT(*) as alert_count')
                        )
                        ->whereBetween('receivedtime', [$startDate, $endDate])
                        ->groupBy('day_of_week', 'hour')
                        ->get();

                    foreach ($heatmapData as $data) {
                        $matrix[(int)$data->day_of_week][(int)$data->hour] += $data->alert_count;
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to query partition {$partition} for heatmap", ['error' => $e->getMessage()]);
                }
            }

            return [
                'matrix' => $matrix,
                'days' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
                'hours' => range(0, 23),
            ];
        } catch (\Exception $e) {
            Log::warning('Peak hours heatmap calculation failed', ['error' => $e->getMessage()]);
            return [
                'matrix' => array_fill(0, 7, array_fill(0, 24, 0)),
                'days' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
                'hours' => range(0, 23),
            ];
        }
    }

    /**
     * 16. Month-over-Month Comparison
     */
    private function getMonthOverMonthComparison(): array
    {
        $currentMonth = Carbon::now()->startOfMonth();
        $previousMonth = Carbon::now()->subMonth()->startOfMonth();

        $currentMetrics = $this->getMonthMetrics($currentMonth->format('Y-m-d'), Carbon::now()->format('Y-m-d'));
        $previousMetrics = $this->getMonthMetrics($previousMonth->format('Y-m-d'), $currentMonth->subDay()->format('Y-m-d'));

        return [
            'current_month' => $currentMetrics,
            'previous_month' => $previousMetrics,
            'changes' => [
                'alerts' => $this->calculatePercentChange($previousMetrics['total_alerts'], $currentMetrics['total_alerts']),
                'tickets' => $this->calculatePercentChange($previousMetrics['total_tickets'], $currentMetrics['total_tickets']),
                'sla_compliance' => $this->calculatePercentChange($previousMetrics['sla_compliance'], $currentMetrics['sla_compliance']),
                'uptime' => $this->calculatePercentChange($previousMetrics['uptime'], $currentMetrics['uptime']),
            ],
        ];
    }

    /**
     * 17. Revenue & Cost Analysis
     */
    private function getRevenueAndCostAnalysis($startDate, $endDate): array
    {
        try {
            // Mock data - replace with actual billing/cost tables
            $totalSites = DB::connection('pgsql')->table('sites')->count();
            
            // Count total alerts from partitioned tables
            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);
            $partitions = $this->getPartitionTablesForDateRange($start, $end);
            
            $totalAlerts = 0;
            foreach ($partitions as $partition) {
                try {
                    $count = DB::connection('pgsql')
                        ->table($partition)
                        ->whereBetween('receivedtime', [$startDate, $endDate])
                        ->count();
                    $totalAlerts += $count;
                } catch (\Exception $e) {
                    Log::warning("Failed to count alerts in {$partition}", ['error' => $e->getMessage()]);
                }
            }

            $monthlyRevenue = $totalSites * 1000; // $1000 per site
            $costPerAlert = 50; // $50 per alert handling
            $totalCost = $totalAlerts * $costPerAlert;
            $profit = $monthlyRevenue - $totalCost;
            $profitMargin = $monthlyRevenue > 0 ? ($profit / $monthlyRevenue) * 100 : 0;

            return [
                'monthly_revenue' => $monthlyRevenue,
                'total_cost' => $totalCost,
                'cost_per_alert' => $costPerAlert,
                'profit' => $profit,
                'profit_margin' => round($profitMargin, 1),
            ];
        } catch (\Exception $e) {
            Log::warning('Revenue analysis calculation failed', ['error' => $e->getMessage()]);
            return [
                'monthly_revenue' => 0,
                'total_cost' => 0,
                'cost_per_alert' => 50,
                'profit' => 0,
                'profit_margin' => 0,
            ];
        }
    }

    /**
     * 18. Billing Status
     */
    private function getBillingStatus(): array
    {
        // Mock data - replace with actual billing table
        return [
            'outstanding_invoices' => 150000,
            'collected_this_month' => 450000,
            'pending_renewals' => 25,
            'overdue_amount' => 35000,
        ];
    }

    /**
     * 19. Failure Predictions
     * Analyzes sites with increasing alert patterns from partitioned tables
     */
    private function getFailurePredictions(): array
    {
        try {
            // Get partitions for last 7 days
            $end = Carbon::now();
            $start = Carbon::now()->subDays(7);
            $partitions = $this->getPartitionTablesForDateRange($start, $end);
            
            if (empty($partitions)) {
                return [];
            }
            
            $alertCounts = [];
            $lastAlerts = [];
            
            // Query each partition and aggregate by panelid
            foreach ($partitions as $partition) {
                try {
                    $counts = DB::connection('pgsql')
                        ->table($partition)
                        ->select(
                            'panelid',
                            DB::raw('COUNT(*) as recent_alerts'),
                            DB::raw('MAX(receivedtime) as last_alert')
                        )
                        ->where('receivedtime', '>=', $start)
                        ->whereNotNull('panelid')
                        ->where('panelid', '!=', '')
                        ->groupBy('panelid')
                        ->get();
                    
                    foreach ($counts as $count) {
                        if (!isset($alertCounts[$count->panelid])) {
                            $alertCounts[$count->panelid] = 0;
                            $lastAlerts[$count->panelid] = $count->last_alert;
                        }
                        $alertCounts[$count->panelid] += $count->recent_alerts;
                        if (strtotime($count->last_alert) > strtotime($lastAlerts[$count->panelid])) {
                            $lastAlerts[$count->panelid] = $count->last_alert;
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to query partition {$partition}", ['error' => $e->getMessage()]);
                }
            }
            
            // Filter sites with more than 5 alerts
            $predictions = [];
            foreach ($alertCounts as $panelId => $count) {
                if ($count > 5) {
                    $predictions[] = [
                        'panelid' => $panelId,
                        'recent_alerts' => $count,
                        'last_alert' => $lastAlerts[$panelId],
                    ];
                }
            }
            
            // Sort by alert count
            usort($predictions, function($a, $b) {
                return $b['recent_alerts'] - $a['recent_alerts'];
            });
            $predictions = array_slice($predictions, 0, 10);
            
            // Get site details
            $panelIds = array_column($predictions, 'panelid');
            $sites = DB::connection('pgsql')
                ->table('sites')
                ->whereIn('NewPanelID', $panelIds)
                ->select('NewPanelID', 'ATMID', 'ATMShortName', 'Customer')
                ->get()
                ->keyBy('NewPanelID');
            
            return array_map(function ($item) use ($sites) {
                $site = $sites->get($item['panelid']);
                return [
                    'site_id' => $item['panelid'],
                    'atmid' => $site->ATMID ?? 'Unknown',
                    'site_name' => $site->ATMShortName ?? 'Unknown',
                    'customer' => $site->Customer ?? 'Unknown',
                    'recent_alerts' => $item['recent_alerts'],
                    'last_alert' => $item['last_alert'],
                    'risk_level' => $item['recent_alerts'] > 10 ? 'high' : 'medium',
                    'recommendation' => 'Schedule proactive maintenance',
                ];
            }, $predictions);
        } catch (\Exception $e) {
            Log::warning('Failure predictions calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * 20. Regional Performance
     * Calculates performance metrics per region using partitioned alert tables
     */
    private function getRegionalPerformance($startDate, $endDate): array
    {
        try {
            $regions = DB::connection('pgsql')
                ->table('sites')
                ->select('Zone')
                ->distinct()
                ->whereNotNull('Zone')
                ->where('Zone', '!=', '')
                ->get();

            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);
            $partitions = $this->getPartitionTablesForDateRange($start, $end);
            
            $performance = [];

            foreach ($regions as $region) {
                // Get sites for this region
                $regionSites = DB::connection('pgsql')
                    ->table('sites')
                    ->where('Zone', $region->Zone)
                    ->select('NewPanelID')
                    ->pluck('NewPanelID')
                    ->toArray();
                
                $siteCount = count($regionSites);
                
                if ($siteCount == 0) {
                    continue;
                }
                
                // Count alerts for this region's sites from partitioned tables
                $alertCount = 0;
                foreach ($partitions as $partition) {
                    try {
                        $count = DB::connection('pgsql')
                            ->table($partition)
                            ->whereIn('panelid', $regionSites)
                            ->whereBetween('receivedtime', [$startDate, $endDate])
                            ->count();
                        $alertCount += $count;
                    } catch (\Exception $e) {
                        Log::warning("Failed to query partition {$partition}", ['error' => $e->getMessage()]);
                    }
                }

                $avgResponseTime = 0;
                if ($this->tableExists('pgsql', 'tickets')) {
                    $avgResponseTime = DB::connection('pgsql')
                        ->table('tickets')
                        ->join('sites', 'tickets.site_id', '=', 'sites.id')
                        ->where('sites.Zone', $region->Zone)
                        ->whereBetween('tickets.created_at', [$startDate, $endDate])
                        ->whereNotNull('tickets.first_response_at')
                        ->avg(DB::raw('EXTRACT(EPOCH FROM (first_response_at - tickets.created_at))/60'));
                }

                $performance[] = [
                    'region' => $region->Zone,
                    'site_count' => $siteCount,
                    'alert_count' => $alertCount,
                    'alerts_per_site' => $siteCount > 0 ? round($alertCount / $siteCount, 2) : 0,
                    'avg_response_time' => round($avgResponseTime ?? 0, 2),
                    'performance_score' => $this->calculateRegionalScore($siteCount, $alertCount, $avgResponseTime),
                ];
            }

            // Sort by performance score
            usort($performance, function ($a, $b) {
                return $b['performance_score'] <=> $a['performance_score'];
            });

            return $performance;
        } catch (\Exception $e) {
            Log::warning('Regional performance calculation failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // Helper methods

    private function getUptimePercentage($startDate, $endDate): float
    {
        $totalSites = DB::connection('pgsql')->table('sites')->count();
        $downSites = DB::connection('mysql')
            ->table('down_communication')
            ->where(function($query) {
                $query->whereNull('dc_date')
                      ->orWhere('dc_date', '!=', date('Y-m-d'));
            })
            ->count();

        return $totalSites > 0 ? (($totalSites - $downSites) / $totalSites) * 100 : 100;
    }

    private function getSLACompliancePercentage($startDate, $endDate): float
    {
        try {
            if (!$this->tableExists('pgsql', 'tickets')) {
                return 100.0; // Default to 100% if no tickets table
            }

            $tickets = DB::connection('pgsql')
                ->table('tickets')
                ->select(
                    DB::raw('COUNT(*) as total'),
                    DB::raw('COUNT(CASE WHEN EXTRACT(EPOCH FROM (first_response_at - created_at))/60 <= 30 THEN 1 END) as within_sla')
                )
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNotNull('first_response_at')
                ->first();

            return $tickets->total > 0 ? ($tickets->within_sla / $tickets->total) * 100 : 100;
        } catch (\Exception $e) {
            Log::warning('SLA compliance calculation failed', ['error' => $e->getMessage()]);
            return 100.0;
        }
    }

    private function getResponseTimeScore($startDate, $endDate): float
    {
        try {
            if (!$this->tableExists('pgsql', 'tickets')) {
                return 100.0;
            }

            $avgResponseTime = DB::connection('pgsql')
                ->table('tickets')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNotNull('first_response_at')
                ->avg(DB::raw('EXTRACT(EPOCH FROM (first_response_at - created_at))/60'));

            $targetTime = 30; // 30 minutes target
            $score = max(0, 100 - (($avgResponseTime - $targetTime) / $targetTime) * 100);

            return min(100, max(0, $score));
        } catch (\Exception $e) {
            Log::warning('Response time score calculation failed', ['error' => $e->getMessage()]);
            return 100.0;
        }
    }

    private function getCustomerSLACompliance($customer, $startDate, $endDate): float
    {
        try {
            if (!$this->tableExists('pgsql', 'tickets')) {
                return 100.0;
            }

            $tickets = DB::connection('pgsql')
                ->table('tickets')
                ->join('sites', 'tickets.site_id', '=', 'sites.id')
                ->where('sites.Customer', $customer)
                ->whereBetween('tickets.created_at', [$startDate, $endDate])
                ->whereNotNull('tickets.first_response_at')
                ->select(
                    DB::raw('COUNT(*) as total'),
                    DB::raw('COUNT(CASE WHEN EXTRACT(EPOCH FROM (first_response_at - tickets.created_at))/60 <= 30 THEN 1 END) as within_sla')
                )
                ->first();

            return $tickets->total > 0 ? ($tickets->within_sla / $tickets->total) * 100 : 100;
        } catch (\Exception $e) {
            Log::warning('Customer SLA compliance calculation failed', ['error' => $e->getMessage()]);
            return 100.0;
        }
    }

    private function calculateCustomerHealth($siteCount, $alertCount, $slaCompliance): float
    {
        $alertsPerSite = $siteCount > 0 ? $alertCount / $siteCount : 0;
        $alertScore = max(0, 100 - ($alertsPerSite * 10));
        
        return ($alertScore * 0.5) + ($slaCompliance * 0.5);
    }

    private function getMonthMetrics($startDate, $endDate): array
    {
        try {
            $totalTickets = 0;
            if ($this->tableExists('pgsql', 'tickets')) {
                $totalTickets = DB::connection('pgsql')
                    ->table('tickets')
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->count();
            }
            
            // Count alerts from partitioned tables
            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);
            $partitions = $this->getPartitionTablesForDateRange($start, $end);
            
            $totalAlerts = 0;
            foreach ($partitions as $partition) {
                try {
                    $count = DB::connection('pgsql')
                        ->table($partition)
                        ->whereBetween('receivedtime', [$startDate, $endDate])
                        ->count();
                    $totalAlerts += $count;
                } catch (\Exception $e) {
                    Log::warning("Failed to count alerts in {$partition}", ['error' => $e->getMessage()]);
                }
            }

            return [
                'total_alerts' => $totalAlerts,
                'total_tickets' => $totalTickets,
                'sla_compliance' => $this->getSLACompliancePercentage($startDate, $endDate),
                'uptime' => $this->getUptimePercentage($startDate, $endDate),
            ];
        } catch (\Exception $e) {
            Log::warning('Month metrics calculation failed', ['error' => $e->getMessage()]);
            return [
                'total_alerts' => 0,
                'total_tickets' => 0,
                'sla_compliance' => 100,
                'uptime' => 100,
            ];
        }
    }

    private function calculatePercentChange($old, $new): float
    {
        if ($old == 0) return $new > 0 ? 100 : 0;
        return round((($new - $old) / $old) * 100, 1);
    }

    private function calculateRegionalScore($siteCount, $alertCount, $avgResponseTime): float
    {
        $alertsPerSite = $siteCount > 0 ? $alertCount / $siteCount : 0;
        $alertScore = max(0, 100 - ($alertsPerSite * 10));
        $responseScore = max(0, 100 - (($avgResponseTime - 30) / 30) * 100);
        
        return ($alertScore * 0.6) + ($responseScore * 0.4);
    }

    /**
     * Check if a table exists in the database
     */
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

    /**
     * Get mock response time data when tickets table doesn't exist
     */
    private function getMockResponseTimeData($startDate, $endDate): array
    {
        return [];
    }

    /**
     * Get partition table names for a date range
     * Includes both alerts_* and backalerts_* partitions
     */
    private function getPartitionTablesForDateRange($startDate, $endDate): array
    {
        try {
            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);
            $partitions = [];
            
            // Generate partition table names for each date in range
            $current = $start->copy();
            while ($current->lte($end)) {
                // Check alerts partition
                $alertsTable = 'alerts_' . $current->format('Y_m_d');
                if ($this->tableExists('pgsql', $alertsTable)) {
                    $partitions[] = $alertsTable;
                }
                
                // Check backalerts partition
                $backalertsTable = 'backalerts_' . $current->format('Y_m_d');
                if ($this->tableExists('pgsql', $backalertsTable)) {
                    $partitions[] = $backalertsTable;
                }
                
                $current->addDay();
            }
            
            return $partitions;
        } catch (\Exception $e) {
            Log::warning('Failed to get partition tables', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
