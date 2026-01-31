<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class PostgresDashboardService
{
    /**
     * PartitionManager service for partition table operations
     */
    private PartitionManager $partitionManager;
    
    /**
     * Create a new PostgresDashboardService instance
     * 
     * @param PartitionManager|null $partitionManager Optional PartitionManager instance
     */
    public function __construct(?PartitionManager $partitionManager = null)
    {
        $this->partitionManager = $partitionManager ?? new PartitionManager();
    }
    
    /**
     * Calculate current shift based on server time
     * 
     * Shift 1: 07:00 - 14:59
     * Shift 2: 15:00 - 22:59
     * Shift 3: 23:00 - 06:59 (spans midnight)
     * 
     * @return int Shift number (1, 2, or 3)
     */
    public function getCurrentShift(): int
    {
        $now = Carbon::now();
        $hour = $now->hour;
        $minute = $now->minute;
        
        // Convert to minutes since midnight for easier comparison
        $currentMinutes = ($hour * 60) + $minute;
        
        // Shift 1: 07:00 (420 min) to 14:59 (899 min)
        if ($currentMinutes >= 420 && $currentMinutes <= 899) {
            return 1;
        }
        
        // Shift 2: 15:00 (900 min) to 22:59 (1379 min)
        if ($currentMinutes >= 900 && $currentMinutes <= 1379) {
            return 2;
        }
        
        // Shift 3: 23:00 (1380 min) to 06:59 (419 min)
        // This includes 23:00-23:59 and 00:00-06:59
        return 3;
    }
    
    /**
     * Get shift time range
     * 
     * @param int $shift Shift number (1, 2, or 3)
     * @return array ['start' => Carbon, 'end' => Carbon]
     */
    public function getShiftTimeRange(int $shift): array
    {
        $now = Carbon::now();
        
        switch ($shift) {
            case 1:
                // Shift 1: 07:00 - 14:59 (same day)
                return [
                    'start' => $now->copy()->setTime(7, 0, 0),
                    'end' => $now->copy()->setTime(14, 59, 59)
                ];
                
            case 2:
                // Shift 2: 15:00 - 22:59 (same day)
                return [
                    'start' => $now->copy()->setTime(15, 0, 0),
                    'end' => $now->copy()->setTime(22, 59, 59)
                ];
                
            case 3:
                // Shift 3: 23:00 today to 06:59 tomorrow (spans midnight)
                $currentHour = $now->hour;
                
                if ($currentHour >= 23) {
                    // Currently in the 23:00-23:59 portion (today to tomorrow)
                    return [
                        'start' => $now->copy()->setTime(23, 0, 0),
                        'end' => $now->copy()->addDay()->setTime(6, 59, 59)
                    ];
                } else {
                    // Currently in the 00:00-06:59 portion (yesterday to today)
                    return [
                        'start' => $now->copy()->subDay()->setTime(23, 0, 0),
                        'end' => $now->copy()->setTime(6, 59, 59)
                    ];
                }
                
            default:
                throw new \InvalidArgumentException("Invalid shift number: {$shift}. Must be 1, 2, or 3.");
        }
    }
    
    /**
     * Get partition table names for a given shift
     * 
     * For Shift 1 and 2: Returns single partition for current date
     * For Shift 3: Returns two partitions (current date and next date) since it spans midnight
     * 
     * Requirements: 1.2, 6.1, 6.2, 6.3
     * 
     * @param int $shift Shift number (1, 2, or 3)
     * @return array Array of partition table names
     */
    private function getPartitionTablesForShift(int $shift): array
    {
        $timeRange = $this->getShiftTimeRange($shift);
        $startDate = $timeRange['start'];
        $endDate = $timeRange['end'];
        
        // Get partition table name for start date
        $startPartition = $this->partitionManager->getPartitionTableName($startDate);
        
        // For Shift 1 and 2, only one partition is needed (same day)
        if ($shift === 1 || $shift === 2) {
            return [$startPartition];
        }
        
        // For Shift 3, we need two partitions (spans midnight)
        // Get partition table name for end date (next day)
        $endPartition = $this->partitionManager->getPartitionTableName($endDate);
        
        // Return both partitions (current date and next date)
        return [$startPartition, $endPartition];
    }
    
    /**
     * Query partition tables for alert counts for a specific terminal
     * 
     * Queries PostgreSQL partitions with receivedtime filter for a specific terminal.
     * Matches alerts where terminal appears in EITHER sendip OR sip2.
     * Counts both regular alerts (status='O' or 'C') and critical alerts (critical_alerts='y').
     * 
     * Requirements: 1.3, 3.1, 3.2, 3.3, 6.4
     * 
     * @param array $partitionTables Array of partition table names
     * @param string $terminal Terminal IP address
     * @param Carbon $startTime Range start time
     * @param Carbon $endTime Range end time
     * @return array Alert counts for the terminal
     */
    private function queryPartitionsForTerminal(array $partitionTables, string $terminal, Carbon $startTime, Carbon $endTime): array
    {
        // Filter out non-existent partitions
        $existingPartitions = array_filter($partitionTables, function($table) {
            return $this->partitionManager->partitionTableExists($table);
        });
        
        // If no partitions exist, return zeros
        if (empty($existingPartitions)) {
            return [
                'open' => 0,
                'close' => 0,
                'criticalopen' => 0,
                'criticalClose' => 0
            ];
        }
        
        // Build UNION query for all existing partitions
        $unionQueries = [];
        $bindings = [];
        
        foreach ($existingPartitions as $tableName) {
            $unionQueries[] = "
                SELECT 
                    SUM(CASE WHEN status = 'O' THEN 1 ELSE 0 END) AS open_count,
                    SUM(CASE WHEN status = 'C' THEN 1 ELSE 0 END) AS close_count,
                    SUM(CASE WHEN status = 'O' AND critical_alerts = 'y' THEN 1 ELSE 0 END) AS critical_open_count,
                    SUM(CASE WHEN status = 'C' AND critical_alerts = 'y' THEN 1 ELSE 0 END) AS critical_close_count
                FROM {$tableName}
                WHERE receivedtime >= ? 
                    AND receivedtime <= ?
                    AND (sendip = ? OR sip2 = ?)
                    AND (status = 'O' OR status = 'C')
            ";
            
            // Add bindings for this partition
            $bindings[] = $startTime->toDateTimeString();
            $bindings[] = $endTime->toDateTimeString();
            $bindings[] = $terminal;
            $bindings[] = $terminal;
        }
        
        // Combine all queries with UNION ALL and sum the results
        $fullQuery = implode(' UNION ALL ', $unionQueries);
        
        // Wrap in outer query to aggregate across partitions
        $finalQuery = "
            SELECT 
                SUM(open_count) as open_count,
                SUM(close_count) as close_count,
                SUM(critical_open_count) as critical_open_count,
                SUM(critical_close_count) as critical_close_count
            FROM ({$fullQuery}) as combined
        ";
        
        try {
            $result = DB::connection('pgsql')->selectOne($finalQuery, $bindings);
            
            return [
                'open' => (int)($result->open_count ?? 0),
                'close' => (int)($result->close_count ?? 0),
                'criticalopen' => (int)($result->critical_open_count ?? 0),
                'criticalClose' => (int)($result->critical_close_count ?? 0)
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to query partitions for terminal', [
                'partitions' => $existingPartitions,
                'terminal' => $terminal,
                'start_time' => $startTime->toDateTimeString(),
                'end_time' => $endTime->toDateTimeString(),
                'error' => $e->getMessage()
            ]);
            
            // Return zeros on error
            return [
                'open' => 0,
                'close' => 0,
                'criticalopen' => 0,
                'criticalClose' => 0
            ];
        }
    }
    
    /**
     * Enrich terminal data with usernames from MySQL
     * 
     * Uses the userid already present in terminal data to query loginusers table.
     * Applies ucwords() capitalization to usernames.
     * 
     * Requirements: 1.4, 7.1, 7.2, 7.3, 7.4
     * 
     * @param Collection $terminalData Terminal alert counts with userid
     * @return Collection Enriched data with usernames
     */
    private function enrichWithUsernames(Collection $terminalData): Collection
    {
        // If no terminal data, return empty collection
        if ($terminalData->isEmpty()) {
            return collect();
        }
        
        // Extract unique userids from the data
        $userids = $terminalData->pluck('userid')->unique()->filter()->values();
        
        if ($userids->isEmpty()) {
            Log::warning('No valid userids found in terminal data for username enrichment');
            return $terminalData;
        }
        
        try {
            // Query MySQL loginusers table for userid-to-name mapping
            $userMap = DB::connection('mysql')
                ->table('loginusers')
                ->whereIn('id', $userids)
                ->select('id', 'name')
                ->get()
                ->keyBy('id');
            
            // Enrich terminal data with usernames
            return $terminalData->map(function($item) use ($userMap) {
                $userid = $item->userid;
                
                // Check if userid exists in loginusers
                if (!isset($userMap[$userid])) {
                    $item->username = null;
                    return $item;
                }
                
                // Get username and apply ucwords() capitalization
                $username = $userMap[$userid]->name;
                $item->username = ucwords(strtolower($username));
                
                // Remove userid from final output (not needed in response)
                unset($item->userid);
                
                return $item;
            });
            
        } catch (\Exception $e) {
            Log::error('Failed to enrich terminal data with usernames', [
                'userids' => $userids->toArray(),
                'error' => $e->getMessage()
            ]);
            
            // On error, return terminal data with null usernames
            return $terminalData->map(function($item) {
                $item->username = null;
                unset($item->userid);
                return $item;
            });
        }
    }
    
    /**
     * Calculate grand totals across all terminals
     * 
     * Sums open, close, total, criticalOpen, criticalClose, and totalCritical
     * across all terminals to produce grand total values.
     * 
     * Requirements: 1.5, 4.5
     * 
     * @param Collection $terminalData Collection of terminal data with alert counts
     * @return array Array with grand total values
     */
    public function calculateGrandTotals(Collection $terminalData): array
    {
        // Initialize grand totals
        $grandTotals = [
            'grandtotalOpenAlerts' => 0,
            'grandtotalCloseAlerts' => 0,
            'grandtotalAlerts' => 0,
            'grandtoalCriticalOpen' => 0,
            'grandtotalCloseCriticalAlert' => 0,
            'grandtotalCritical' => 0
        ];
        
        // If no terminal data, return zeros
        if ($terminalData->isEmpty()) {
            return $grandTotals;
        }
        
        // Sum up all values across terminals
        foreach ($terminalData as $terminal) {
            $grandTotals['grandtotalOpenAlerts'] += $terminal->open ?? 0;
            $grandTotals['grandtotalCloseAlerts'] += $terminal->close ?? 0;
            $grandTotals['grandtotalAlerts'] += $terminal->total ?? 0;
            $grandTotals['grandtoalCriticalOpen'] += $terminal->criticalopen ?? 0;
            $grandTotals['grandtotalCloseCriticalAlert'] += $terminal->criticalClose ?? 0;
            $grandTotals['grandtotalCritical'] += $terminal->totalCritical ?? 0;
        }
        
        return $grandTotals;
    }
    
    /**
     * Get alert distribution for dashboard display
     * 
     * Main method that orchestrates the entire dashboard data retrieval process:
     * 1. Auto-detect shift if not provided
     * 2. Get shift time range
     * 3. Get partition tables for shift
     * 4. Get list of active terminals from MySQL alertscount
     * 5. For each terminal, query partitions for alert counts
     * 6. Enrich with usernames
     * 7. Calculate grand totals
     * 
     * Requirements: 1.1, 1.2, 1.3, 1.4, 1.5
     * 
     * @param int|null $shift Optional shift override (1, 2, or 3). Auto-detected if null.
     * @return array Complete dashboard data with counts, totals, shift info, and time range
     */
    public function getAlertDistribution(?int $shift = null): array
    {
        // Step 1: Auto-detect shift if not provided
        if ($shift === null) {
            $shift = $this->getCurrentShift();
            Log::info('Auto-detected current shift', ['shift' => $shift]);
        }
        
        // Validate shift parameter
        if (!in_array($shift, [1, 2, 3])) {
            throw new \InvalidArgumentException("Invalid shift number: {$shift}. Must be 1, 2, or 3.");
        }
        
        // Step 2: Get shift time range
        $timeRange = $this->getShiftTimeRange($shift);
        $startTime = $timeRange['start'];
        $endTime = $timeRange['end'];
        
        Log::info('Processing dashboard data for shift', [
            'shift' => $shift,
            'start_time' => $startTime->toDateTimeString(),
            'end_time' => $endTime->toDateTimeString()
        ]);
        
        // Step 3: Get partition tables for shift
        $partitionTables = $this->getPartitionTablesForShift($shift);
        
        Log::info('Partition tables selected for shift', [
            'shift' => $shift,
            'partitions' => $partitionTables
        ]);
        
        // Step 4: Get list of active terminals from MySQL alertscount
        try {
            $terminals = DB::connection('mysql')
                ->table('alertscount')
                ->where('status', 1)
                ->select('ip as terminal', 'userid')
                ->get();
            
            Log::info('Retrieved active terminals from alertscount', [
                'terminal_count' => $terminals->count()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to retrieve terminals from alertscount', [
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('Failed to retrieve terminal list: ' . $e->getMessage());
        }
        
        // Step 5: For each terminal, query partitions for alert counts
        $terminalData = collect();
        
        foreach ($terminals as $terminalInfo) {
            $terminal = $terminalInfo->terminal;
            $userid = $terminalInfo->userid;
            
            // Query partitions for this terminal's alert counts
            $counts = $this->queryPartitionsForTerminal($partitionTables, $terminal, $startTime, $endTime);
            
            // Calculate totals
            $totalOpen = $counts['open'];
            $totalClose = $counts['close'];
            $totalAlerts = $totalOpen + $totalClose;
            $criticalOpen = $counts['criticalopen'];
            $criticalClose = $counts['criticalClose'];
            $totalCritical = $criticalOpen + $criticalClose;
            
            // Create terminal data object
            $terminalData->push((object)[
                'terminal' => $terminal,
                'userid' => $userid,
                'username' => null, // Will be enriched later
                'open' => $totalOpen,
                'close' => $totalClose,
                'total' => $totalAlerts,
                'criticalopen' => $criticalOpen,
                'criticalClose' => $criticalClose,
                'totalCritical' => $totalCritical
            ]);
        }
        
        // Step 6: Enrich with usernames
        $enrichedData = $this->enrichWithUsernames($terminalData);
        
        // Step 7: Calculate grand totals
        $grandTotals = $this->calculateGrandTotals($enrichedData);
        
        // Format the final response
        $response = array_merge(
            [
                'data' => $enrichedData->values()->toArray(),
                'shift' => $shift,
                'shift_time_range' => [
                    'start' => $startTime->toDateTimeString(),
                    'end' => $endTime->toDateTimeString()
                ]
            ],
            $grandTotals
        );
        
        Log::info('Dashboard data successfully generated', [
            'shift' => $shift,
            'terminal_count' => $enrichedData->count(),
            'grand_total_alerts' => $grandTotals['grandtotalAlerts']
        ]);
        
        return $response;
    }
    
    /**
     * Get detailed alerts for a specific terminal and status
     * 
     * Queries PostgreSQL partitions for detailed alert information filtered by
     * terminal, status, and shift. Joins with MySQL sites table to enrich with
     * location data (ATMID, Zone, City).
     * 
     * Requirements: 5.2, 5.3, 5.4, 9.4
     * 
     * @param string $terminal Terminal IP address
     * @param string $status Status filter (open, close, total, criticalopen, criticalClose, totalCritical)
     * @param int $shift Shift number (1, 2, or 3)
     * @return Collection Alert details with site information
     */
    public function getAlertDetails(string $terminal, string $status, int $shift): Collection
    {
        // Validate shift parameter
        if (!in_array($shift, [1, 2, 3])) {
            throw new \InvalidArgumentException("Invalid shift number: {$shift}. Must be 1, 2, or 3.");
        }
        
        // Get shift time range
        $timeRange = $this->getShiftTimeRange($shift);
        $startTime = $timeRange['start'];
        $endTime = $timeRange['end'];
        
        // Get partition tables for shift
        $partitionTables = $this->getPartitionTablesForShift($shift);
        
        // Filter out non-existent partitions
        $existingPartitions = array_filter($partitionTables, function($table) {
            return $this->partitionManager->partitionTableExists($table);
        });
        
        // If no partitions exist, return empty collection
        if (empty($existingPartitions)) {
            Log::warning('No existing partitions found for alert details query', [
                'requested_partitions' => $partitionTables,
                'terminal' => $terminal,
                'status' => $status,
                'shift' => $shift
            ]);
            return collect();
        }
        
        // Build status filter based on status type
        $statusFilter = $this->buildStatusFilter($status);
        
        // Build UNION query for all existing partitions
        $unionQueries = [];
        $bindings = [];
        
        foreach ($existingPartitions as $tableName) {
            $unionQueries[] = "
                SELECT 
                    id,
                    panelid,
                    receivedtime,
                    alerttype,
                    comment,
                    \"closedBy\",
                    closedtime
                FROM {$tableName}
                WHERE receivedtime >= ? 
                    AND receivedtime <= ?
                    AND (sendip = ? OR sip2 = ?)
                    {$statusFilter}
            ";
            
            // Add bindings for this partition
            $bindings[] = $startTime->toDateTimeString();
            $bindings[] = $endTime->toDateTimeString();
            $bindings[] = $terminal;
            $bindings[] = $terminal;
        }
        
        // Combine all queries with UNION ALL
        $fullQuery = implode(' UNION ALL ', $unionQueries);
        
        try {
            // Execute PostgreSQL query to get alerts
            $alerts = DB::connection('pgsql')->select($fullQuery, $bindings);
            
            Log::info('Successfully queried partitions for alert details', [
                'partitions' => $existingPartitions,
                'terminal' => $terminal,
                'status' => $status,
                'shift' => $shift,
                'result_count' => count($alerts)
            ]);
            
            // Convert to collection
            $alertsCollection = collect($alerts);
            
            // If no alerts found, return empty collection
            if ($alertsCollection->isEmpty()) {
                return collect();
            }
            
            // Enrich with site data from MySQL
            return $this->enrichWithSiteData($alertsCollection);
            
        } catch (\Exception $e) {
            Log::error('Failed to query partitions for alert details', [
                'partitions' => $existingPartitions,
                'terminal' => $terminal,
                'status' => $status,
                'shift' => $shift,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('Failed to fetch alert details from partitions: ' . $e->getMessage());
        }
    }
    
    /**
     * Build status filter SQL clause based on status type
     * 
     * @param string $status Status type
     * @return string SQL WHERE clause fragment
     */
    private function buildStatusFilter(string $status): string
    {
        switch (strtolower($status)) {
            case 'open':
                return "AND status = 'O'";
                
            case 'close':
                return "AND status = 'C'";
                
            case 'total':
                return "AND (status = 'O' OR status = 'C')";
                
            case 'criticalopen':
                return "AND status = 'O' AND critical_alerts = 'y'";
                
            case 'criticalclose':
                return "AND status = 'C' AND critical_alerts = 'y'";
                
            case 'totalcritical':
                return "AND (status = 'O' OR status = 'C') AND critical_alerts = 'y'";
                
            default:
                // Default to all alerts if status is unrecognized
                Log::warning('Unrecognized status type, defaulting to all alerts', [
                    'status' => $status
                ]);
                return "AND (status = 'O' OR status = 'C')";
        }
    }
    
    /**
     * Enrich alert data with site information from MySQL
     * 
     * Performs LEFT JOIN with MySQL sites table to add ATMID, Zone, and City
     * information based on panelid matching OldPanelID or NewPanelID.
     * 
     * Requirements: 5.3, 9.4
     * 
     * @param Collection $alerts Collection of alerts from PostgreSQL
     * @return Collection Enriched alerts with site data
     */
    private function enrichWithSiteData(Collection $alerts): Collection
    {
        // Extract unique panel IDs
        $panelIds = $alerts->pluck('panelid')->unique()->filter()->values();
        
        // If no panel IDs, return alerts with null site data
        if ($panelIds->isEmpty()) {
            return $alerts->map(function($alert) {
                $alert->ATMID = null;
                $alert->Zone = null;
                $alert->City = null;
                return $alert;
            });
        }
        
        try {
            // Query MySQL sites table for matching panel IDs
            // Match on either OldPanelID or NewPanelID
            $sites = DB::connection('mysql')
                ->table('sites')
                ->where(function($query) use ($panelIds) {
                    $query->whereIn('OldPanelID', $panelIds)
                          ->orWhereIn('NewPanelID', $panelIds);
                })
                ->select('OldPanelID', 'NewPanelID', 'ATMID', 'Zone', 'City')
                ->get();
            
            // Create a map of panelid to site data
            $siteMap = [];
            foreach ($sites as $site) {
                // Map both OldPanelID and NewPanelID to the same site data
                if ($site->OldPanelID) {
                    $siteMap[$site->OldPanelID] = $site;
                }
                if ($site->NewPanelID) {
                    $siteMap[$site->NewPanelID] = $site;
                }
            }
            
            // Enrich alerts with site data
            return $alerts->map(function($alert) use ($siteMap) {
                $panelid = $alert->panelid;
                
                if (isset($siteMap[$panelid])) {
                    $site = $siteMap[$panelid];
                    $alert->ATMID = $site->ATMID;
                    $alert->Zone = $site->Zone;
                    $alert->City = $site->City;
                } else {
                    // No matching site found, set to null
                    $alert->ATMID = null;
                    $alert->Zone = null;
                    $alert->City = null;
                }
                
                return $alert;
            });
            
        } catch (\Exception $e) {
            Log::error('Failed to enrich alerts with site data', [
                'panel_ids' => $panelIds->toArray(),
                'error' => $e->getMessage()
            ]);
            
            // On error, return alerts with null site data
            return $alerts->map(function($alert) {
                $alert->ATMID = null;
                $alert->Zone = null;
                $alert->City = null;
                return $alert;
            });
        }
    }
    
}
