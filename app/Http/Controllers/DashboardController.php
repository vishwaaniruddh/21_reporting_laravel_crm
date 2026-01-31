<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * DashboardController - Dashboard statistics and metrics
 * 
 * Provides dashboard data including sites stats and alerts metrics
 */
class DashboardController extends Controller
{
    /**
     * GET /api/dashboard/stats
     * 
     * Get dashboard statistics
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            // Cache dashboard stats for 5 minutes
            $stats = Cache::remember('dashboard_stats', 300, function() {
                $today = Carbon::today();
                $todayStart = $today->format('Y-m-d 00:00:00');
                $todayEnd = $today->format('Y-m-d 23:59:59');
                
                // Get today's partition table names for both alerts and backalerts
                $alertsPartition = 'alerts_' . $today->format('Y_m_d');
                $backalertsPartition = 'backalerts_' . $today->format('Y_m_d');
                
                // Check if partitions exist
                $alertsPartitionExists = $this->tableExists('pgsql', $alertsPartition);
                $backalertsPartitionExists = $this->tableExists('pgsql', $backalertsPartition);
                
                // Sites Statistics
                $totalSites = DB::connection('pgsql')->table('sites')->count();
                $liveSites = DB::connection('pgsql')->table('sites')->where('live', 'Y')->count();
                $totalDVRSites = DB::connection('pgsql')->table('dvrsite')->count();
                $liveDVRSites = DB::connection('pgsql')->table('dvrsite')->where('live', 'Y')->count();
                
                // Today's Alerts Statistics (combined from both alerts and backalerts partitions)
                $todayAlerts = 0;
                $todayOpenAlerts = 0;
                $todayClosedAlerts = 0;
                $todayReactiveAlerts = 0;
                $todayVMAlerts = 0; // VM alerts: status IN ('O','C') AND sendtoclient = 'S'
                
                // Count from alerts partition
                if ($alertsPartitionExists) {
                    $todayAlerts += DB::connection('pgsql')
                        ->table($alertsPartition)
                        ->whereBetween('receivedtime', [$todayStart, $todayEnd])
                        ->count();
                    
                    $todayOpenAlerts += DB::connection('pgsql')
                        ->table($alertsPartition)
                        ->whereBetween('receivedtime', [$todayStart, $todayEnd])
                        ->where('status', 'O')
                        ->count();
                    
                    $todayClosedAlerts += DB::connection('pgsql')
                        ->table($alertsPartition)
                        ->whereBetween('receivedtime', [$todayStart, $todayEnd])
                        ->where('status', 'C')
                        ->count();
                    
                    // Reactive alerts (not ending with 'R')
                    $todayReactiveAlerts += DB::connection('pgsql')
                        ->table($alertsPartition)
                        ->whereBetween('receivedtime', [$todayStart, $todayEnd])
                        ->where('alarm', 'not like', '%R')
                        ->count();
                    
                    // VM alerts
                    $todayVMAlerts += DB::connection('pgsql')
                        ->table($alertsPartition)
                        ->whereBetween('receivedtime', [$todayStart, $todayEnd])
                        ->whereIn('status', ['O', 'C'])
                        ->where('sendtoclient', 'S')
                        ->count();
                }
                
                // Count from backalerts partition
                if ($backalertsPartitionExists) {
                    $todayAlerts += DB::connection('pgsql')
                        ->table($backalertsPartition)
                        ->whereBetween('receivedtime', [$todayStart, $todayEnd])
                        ->count();
                    
                    $todayOpenAlerts += DB::connection('pgsql')
                        ->table($backalertsPartition)
                        ->whereBetween('receivedtime', [$todayStart, $todayEnd])
                        ->where('status', 'O')
                        ->count();
                    
                    $todayClosedAlerts += DB::connection('pgsql')
                        ->table($backalertsPartition)
                        ->whereBetween('receivedtime', [$todayStart, $todayEnd])
                        ->where('status', 'C')
                        ->count();
                    
                    // Reactive alerts (not ending with 'R')
                    $todayReactiveAlerts += DB::connection('pgsql')
                        ->table($backalertsPartition)
                        ->whereBetween('receivedtime', [$todayStart, $todayEnd])
                        ->where('alarm', 'not like', '%R')
                        ->count();
                    
                    // VM alerts
                    $todayVMAlerts += DB::connection('pgsql')
                        ->table($backalertsPartition)
                        ->whereBetween('receivedtime', [$todayStart, $todayEnd])
                        ->whereIn('status', ['O', 'C'])
                        ->where('sendtoclient', 'S')
                        ->count();
                }
                
                // Top Alert Types Today (combined from both partitions)
                $topAlertTypes = [];
                $alertTypeCounts = [];
                
                if ($alertsPartitionExists) {
                    $alertTypes = DB::connection('pgsql')
                        ->table($alertsPartition)
                        ->select('alerttype', DB::raw('COUNT(*) as count'))
                        ->whereBetween('receivedtime', [$todayStart, $todayEnd])
                        ->whereNotNull('alerttype')
                        ->groupBy('alerttype')
                        ->get();
                    
                    foreach ($alertTypes as $type) {
                        if (!isset($alertTypeCounts[$type->alerttype])) {
                            $alertTypeCounts[$type->alerttype] = 0;
                        }
                        $alertTypeCounts[$type->alerttype] += $type->count;
                    }
                }
                
                if ($backalertsPartitionExists) {
                    $backAlertTypes = DB::connection('pgsql')
                        ->table($backalertsPartition)
                        ->select('alerttype', DB::raw('COUNT(*) as count'))
                        ->whereBetween('receivedtime', [$todayStart, $todayEnd])
                        ->whereNotNull('alerttype')
                        ->groupBy('alerttype')
                        ->get();
                    
                    foreach ($backAlertTypes as $type) {
                        if (!isset($alertTypeCounts[$type->alerttype])) {
                            $alertTypeCounts[$type->alerttype] = 0;
                        }
                        $alertTypeCounts[$type->alerttype] += $type->count;
                    }
                }
                
                // Sort and get top 5
                arsort($alertTypeCounts);
                $topAlertTypes = array_slice($alertTypeCounts, 0, 5, true);
                $topAlertTypes = array_map(function($count, $type) {
                    return ['alerttype' => $type, 'count' => $count];
                }, $topAlertTypes, array_keys($topAlertTypes));
                
                // Alerts by Status Today (combined from both partitions)
                $alertsByStatus = [];
                $statusCounts = [];
                
                if ($alertsPartitionExists) {
                    $statuses = DB::connection('pgsql')
                        ->table($alertsPartition)
                        ->select('status', DB::raw('COUNT(*) as count'))
                        ->whereBetween('receivedtime', [$todayStart, $todayEnd])
                        ->whereNotNull('status')
                        ->groupBy('status')
                        ->get();
                    
                    foreach ($statuses as $status) {
                        if (!isset($statusCounts[$status->status])) {
                            $statusCounts[$status->status] = 0;
                        }
                        $statusCounts[$status->status] += $status->count;
                    }
                }
                
                if ($backalertsPartitionExists) {
                    $backStatuses = DB::connection('pgsql')
                        ->table($backalertsPartition)
                        ->select('status', DB::raw('COUNT(*) as count'))
                        ->whereBetween('receivedtime', [$todayStart, $todayEnd])
                        ->whereNotNull('status')
                        ->groupBy('status')
                        ->get();
                    
                    foreach ($backStatuses as $status) {
                        if (!isset($statusCounts[$status->status])) {
                            $statusCounts[$status->status] = 0;
                        }
                        $statusCounts[$status->status] += $status->count;
                    }
                }
                
                $alertsByStatus = array_map(function($count, $status) {
                    return ['status' => $status, 'count' => $count];
                }, $statusCounts, array_keys($statusCounts));
                
                // Hourly Alerts Distribution (Last 24 hours) - combined from both partitions
                $hourlyAlerts = [];
                $hourlyCounts = array_fill(0, 24, 0);
                
                if ($alertsPartitionExists) {
                    $hourly = DB::connection('pgsql')
                        ->table($alertsPartition)
                        ->select(
                            DB::raw("EXTRACT(HOUR FROM receivedtime) as hour"),
                            DB::raw('COUNT(*) as count')
                        )
                        ->whereBetween('receivedtime', [$todayStart, $todayEnd])
                        ->groupBy('hour')
                        ->get();
                    
                    foreach ($hourly as $h) {
                        $hourlyCounts[(int)$h->hour] += $h->count;
                    }
                }
                
                if ($backalertsPartitionExists) {
                    $backHourly = DB::connection('pgsql')
                        ->table($backalertsPartition)
                        ->select(
                            DB::raw("EXTRACT(HOUR FROM receivedtime) as hour"),
                            DB::raw('COUNT(*) as count')
                        )
                        ->whereBetween('receivedtime', [$todayStart, $todayEnd])
                        ->groupBy('hour')
                        ->get();
                    
                    foreach ($backHourly as $h) {
                        $hourlyCounts[(int)$h->hour] += $h->count;
                    }
                }
                
                // Convert to array format
                for ($i = 0; $i < 24; $i++) {
                    $hourlyAlerts[] = ['hour' => $i, 'count' => $hourlyCounts[$i]];
                }
                
                // Sites by Customer (Top 5)
                $sitesByCustomer = DB::connection('pgsql')
                    ->table('sites')
                    ->select('Customer', DB::raw('COUNT(*) as count'))
                    ->whereNotNull('Customer')
                    ->where('Customer', '!=', '')
                    ->groupBy('Customer')
                    ->orderByDesc('count')
                    ->limit(5)
                    ->get()
                    ->toArray();
                
                // Sites by Bank (Top 5)
                $sitesByBank = DB::connection('pgsql')
                    ->table('sites')
                    ->select('Bank', DB::raw('COUNT(*) as count'))
                    ->whereNotNull('Bank')
                    ->where('Bank', '!=', '')
                    ->groupBy('Bank')
                    ->orderByDesc('count')
                    ->limit(5)
                    ->get()
                    ->toArray();
                
                return [
                    'sites' => [
                        'total' => $totalSites,
                        'live' => $liveSites,
                        'offline' => $totalSites - $liveSites,
                        'live_percentage' => $totalSites > 0 ? round(($liveSites / $totalSites) * 100, 1) : 0,
                    ],
                    'dvr_sites' => [
                        'total' => $totalDVRSites,
                        'live' => $liveDVRSites,
                        'offline' => $totalDVRSites - $liveDVRSites,
                        'live_percentage' => $totalDVRSites > 0 ? round(($liveDVRSites / $totalDVRSites) * 100, 1) : 0,
                    ],
                    'today_alerts' => [
                        'total' => $todayAlerts,
                        'open' => $todayOpenAlerts,
                        'closed' => $todayClosedAlerts,
                        'reactive' => $todayReactiveAlerts,
                        'non_reactive' => $todayAlerts - $todayReactiveAlerts,
                        'vm_alerts' => $todayVMAlerts, // VM alerts with status IN ('O','C') AND sendtoclient = 'S'
                    ],
                    'top_alert_types' => $topAlertTypes,
                    'alerts_by_status' => $alertsByStatus,
                    'hourly_alerts' => $hourlyAlerts,
                    'sites_by_customer' => $sitesByCustomer,
                    'sites_by_bank' => $sitesByBank,
                    'partitions' => [
                        'alerts_exists' => $alertsPartitionExists,
                        'backalerts_exists' => $backalertsPartitionExists,
                        'alerts_table' => $alertsPartition,
                        'backalerts_table' => $backalertsPartition,
                    ],
                    'date' => $today->format('Y-m-d'),
                ];
            });
            
            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch dashboard stats', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FETCH_ERROR',
                    'message' => 'Failed to fetch dashboard statistics',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }
    
    /**
     * Check if a table exists in the database
     */
    private function tableExists(string $connection, string $tableName): bool
    {
        try {
            return DB::connection($connection)
                ->table('partition_registry')
                ->where('table_name', $tableName)
                ->exists();
        } catch (\Exception $e) {
            return false;
        }
    }
}
