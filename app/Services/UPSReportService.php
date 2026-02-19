<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * UPS Report Service
 * 
 * Handles Mains/UPS Failure reports by querying partitioned alerts and backalerts tables
 * 
 * Panel Types and their zones/alarms:
 * - RASS: zones 029,030 with alarm AT/AR
 * - SMART-I: zones 001,002 with alarm BA/BR
 * - Securico (securico_gx4816, sec_sbi): zones 551,552 with alarm BA/BR
 * - SEC: zones 027,028 with alarm BA/BR
 */
class UPSReportService
{
    /**
     * Get UPS reports with pagination
     */
    public function getUPSReports(array $filters, int $perPage = 25, int $page = 1)
    {
        $fromDate = $filters['from_date'];
        $toDate = $filters['to_date'] ?? $fromDate;

        // Get partition table names for the date range
        $partitionTables = $this->getPartitionTables($fromDate, $toDate);

        if (empty($partitionTables)) {
            return [
                'data' => [],
                'pagination' => [
                    'total' => 0,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => 0,
                    'from' => 0,
                    'to' => 0
                ]
            ];
        }

        // Get all UPS alerts from different panel types
        $query = $this->buildUPSQuery($filters, $fromDate, $toDate, $partitionTables);

        // Get total count
        $totalQuery = "SELECT COUNT(*) as total FROM ({$query}) as ups_count";
        $total = DB::connection('pgsql')->selectOne($totalQuery)->total;

        // Add pagination
        $offset = ($page - 1) * $perPage;
        $query .= " LIMIT {$perPage} OFFSET {$offset}";

        // Execute query
        $results = DB::connection('pgsql')->select($query);

        // Process results to add restore times
        $processedResults = $this->processUPSResults($results, $fromDate, $toDate, $partitionTables);

        return [
            'data' => $processedResults,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => ceil($total / $perPage),
                'from' => $offset + 1,
                'to' => min($offset + $perPage, $total)
            ]
        ];
    }

    /**
     * Get partition table names for date range (both alerts and backalerts)
     */
    protected function getPartitionTables(string $fromDate, string $toDate): array
    {
        try {
            $tables = [];
            $currentDate = new \DateTime($fromDate);
            $endDate = new \DateTime($toDate);

            while ($currentDate <= $endDate) {
                $dateStr = $currentDate->format('Y_m_d');
                
                // Check for alerts partition
                $alertsTable = 'alerts_' . $dateStr;
                $alertsExists = DB::connection('pgsql')->selectOne("
                    SELECT EXISTS (
                        SELECT FROM pg_tables 
                        WHERE schemaname = 'public' 
                        AND tablename = ?
                    ) as exists
                ", [$alertsTable]);

                if ($alertsExists->exists) {
                    $tables[] = $alertsTable;
                }

                // Check for backalerts partition
                $backalertsTable = 'backalerts_' . $dateStr;
                $backalertsExists = DB::connection('pgsql')->selectOne("
                    SELECT EXISTS (
                        SELECT FROM pg_tables 
                        WHERE schemaname = 'public' 
                        AND tablename = ?
                    ) as exists
                ", [$backalertsTable]);

                if ($backalertsExists->exists) {
                    $tables[] = $backalertsTable;
                }

                $currentDate->modify('+1 day');
            }

            return $tables;

        } catch (\Exception $e) {
            Log::error('Get Partition Tables Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Build the main UPS query combining all panel types
     */
    protected function buildUPSQuery(array $filters, string $fromDate, string $toDate, array $partitionTables): string
    {
        $allQueries = [];

        // Build queries for each partition table
        foreach ($partitionTables as $partitionTable) {
            $queries = [];

            // RASS panels - zones 029,030 with alarm AT
            $queries[] = $this->buildPanelQuery(
                ['RASS', 'rass_boi', 'rass_pnb', 'rass_sbi'],
                ['029', '030'],
                'AT',
                $filters,
                $fromDate,
                $toDate,
                $partitionTable
            );

            // SMART-I panels - zones 001,002 with alarm BA
            $queries[] = $this->buildPanelQuery(
                ['SMART -I', 'SMART -IN', 'smarti_boi', 'smarti_pnb'],
                ['001', '002'],
                'BA',
                $filters,
                $fromDate,
                $toDate,
                $partitionTable
            );

            // Securico panels - zones 551,552 with alarm BA
            $queries[] = $this->buildPanelQuery(
                ['securico_gx4816', 'sec_sbi'],
                ['551', '552'],
                'BA',
                $filters,
                $fromDate,
                $toDate,
                $partitionTable
            );

            // SEC panels - zones 027,028 with alarm BA
            $queries[] = $this->buildPanelQuery(
                ['SEC'],
                ['027', '028'],
                'BA',
                $filters,
                $fromDate,
                $toDate,
                $partitionTable
            );

            $allQueries = array_merge($allQueries, $queries);
        }

        // Combine all queries with UNION ALL
        return '(' . implode(') UNION ALL (', $allQueries) . ') ORDER BY createtime DESC';
    }

    /**
     * Build query for specific panel type
     */
    protected function buildPanelQuery(
        array $panelMakes,
        array $zones,
        string $alarm,
        array $filters,
        string $fromDate,
        string $toDate,
        string $partitionTable
    ): string {
        $panelMakesStr = "'" . implode("','", $panelMakes) . "'";
        $zonesStr = "'" . implode("','", $zones) . "'";

        // Determine if this is alerts or backalerts partition
        $isBackalerts = strpos($partitionTable, 'backalerts_') === 0;
        $closedByColumn = $isBackalerts ? 'a.closedby' : 'a."closedBy"';

        $query = "
            SELECT 
                s.\"Customer\",
                s.\"Bank\",
                s.\"ATMID\",
                s.\"ATMShortName\",
                s.\"SiteAddress\",
                s.\"DVRIP\",
                s.\"Panel_Make\",
                s.\"Zone\" as site_zone,
                s.\"City\",
                s.\"State\",
                a.id,
                a.panelid,
                a.createtime,
                a.receivedtime,
                a.comment,
                a.zone,
                a.alarm,
                {$closedByColumn} as closedby,
                a.closedtime,
                '{$alarm}' as alarm_type,
                '{$zones[0]}' as eb_zone,
                '{$zones[1]}' as ups_zone
            FROM sites s
            INNER JOIN {$partitionTable} a ON (s.\"OldPanelID\" = a.panelid OR s.\"NewPanelID\" = a.panelid)
            WHERE s.\"Panel_Make\" IN ({$panelMakesStr})
                AND a.zone IN ({$zonesStr})
                AND a.alarm = '{$alarm}'
        ";

        // Add filters
        if (!empty($filters['panelid'])) {
            $panelid = pg_escape_string($filters['panelid']);
            $query .= " AND a.panelid = '{$panelid}'";
        }

        if (!empty($filters['atmid'])) {
            $atmid = pg_escape_string($filters['atmid']);
            $query .= " AND s.\"ATMID\" = '{$atmid}'";
        }

        if (!empty($filters['dvrip'])) {
            $dvrip = pg_escape_string($filters['dvrip']);
            $query .= " AND s.\"DVRIP\" = '{$dvrip}'";
        }

        if (!empty($filters['customer'])) {
            $customer = pg_escape_string($filters['customer']);
            $query .= " AND s.\"Customer\" = '{$customer}'";
        }

        return $query;
    }

    /**
     * Process results to add restore times
     */
    protected function processUPSResults(array $results, string $fromDate, string $toDate, array $partitionTables): array
    {
        $processed = [];

        foreach ($results as $row) {
            $item = (array) $row;
            
            // Determine restore alarm based on alarm type
            $restoreAlarm = $item['alarm_type'] === 'AT' ? 'AR' : 'BR';

            // Get EB Power restore time (first zone)
            if ($item['zone'] === $item['eb_zone']) {
                $item['eb_fail_date'] = date('Y-m-d', strtotime($item['createtime']));
                $item['eb_fail_time'] = date('H:i:s', strtotime($item['createtime']));
                $item['ups_available_date'] = $item['eb_fail_date'];
                $item['ups_available_time'] = $item['eb_fail_time'];
                
                // Find EB restore
                $ebRestore = $this->findRestoreTime(
                    $item['panelid'],
                    $item['eb_zone'],
                    $restoreAlarm,
                    $item['createtime'],
                    $toDate,
                    $partitionTables
                );
                $item['eb_restore_date'] = $ebRestore['date'];
                $item['eb_restore_time'] = $ebRestore['time'];
            } else {
                $item['eb_fail_date'] = '-';
                $item['eb_fail_time'] = '-';
                $item['ups_available_date'] = '-';
                $item['ups_available_time'] = '-';
                $item['eb_restore_date'] = '-';
                $item['eb_restore_time'] = '-';
            }

            // Get UPS Power restore time (second zone)
            if ($item['zone'] === $item['ups_zone']) {
                $item['ups_fail_date'] = date('Y-m-d', strtotime($item['createtime']));
                $item['ups_fail_time'] = date('H:i:s', strtotime($item['createtime']));
                
                // Find UPS restore
                $upsRestore = $this->findRestoreTime(
                    $item['panelid'],
                    $item['ups_zone'],
                    $restoreAlarm,
                    $item['createtime'],
                    $toDate,
                    $partitionTables
                );
                $item['ups_restore_date'] = $upsRestore['date'];
                $item['ups_restore_time'] = $upsRestore['time'];
            } else {
                $item['ups_fail_date'] = '-';
                $item['ups_fail_time'] = '-';
                $item['ups_restore_date'] = '-';
                $item['ups_restore_time'] = '-';
            }

            $processed[] = $item;
        }

        return $processed;
    }

    /**
     * Find restore time for a specific alert
     */
    protected function findRestoreTime(
        string $panelid,
        string $zone,
        string $restoreAlarm,
        string $afterTime,
        string $toDate,
        array $partitionTables
    ): array {
        try {
            // Search across all partition tables
            foreach ($partitionTables as $partitionTable) {
                $query = "
                    SELECT createtime
                    FROM {$partitionTable}
                    WHERE panelid = :panelid
                        AND zone = :zone
                        AND alarm = :alarm
                        AND createtime > :after_time
                    ORDER BY createtime ASC
                    LIMIT 1
                ";

                $result = DB::connection('pgsql')->selectOne($query, [
                    'panelid' => $panelid,
                    'zone' => $zone,
                    'alarm' => $restoreAlarm,
                    'after_time' => $afterTime
                ]);

                if ($result) {
                    return [
                        'date' => date('Y-m-d', strtotime($result->createtime)),
                        'time' => date('H:i:s', strtotime($result->createtime))
                    ];
                }
            }

            return ['date' => '-', 'time' => '-'];

        } catch (\Exception $e) {
            Log::error('Find Restore Time Error: ' . $e->getMessage());
            return ['date' => '-', 'time' => '-'];
        }
    }

    /**
     * Get filter options
     */
    public function getFilterOptions(): array
    {
        try {
            // Get unique customers from sites
            $customers = DB::connection('pgsql')
                ->table('sites')
                ->select('Customer')
                ->distinct()
                ->whereNotNull('Customer')
                ->where('Customer', '!=', '')
                ->orderBy('Customer')
                ->pluck('Customer')
                ->toArray();

            return [
                'customers' => $customers
            ];

        } catch (\Exception $e) {
            Log::error('UPS Filter Options Error: ' . $e->getMessage());
            return [
                'customers' => []
            ];
        }
    }

    /**
     * Export to CSV
     */
    public function exportToCsv(array $filters)
    {
        $fromDate = $filters['from_date'];
        $toDate = $filters['to_date'] ?? $fromDate;

        // Get partition tables
        $partitionTables = $this->getPartitionTables($fromDate, $toDate);

        if (empty($partitionTables)) {
            // Return empty CSV if no partitions found
            $filename = "ups_report_{$fromDate}_to_{$toDate}.csv";
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ];

            $callback = function () {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['No data found for the selected date range']);
                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        }

        $query = $this->buildUPSQuery($filters, $fromDate, $toDate, $partitionTables);
        $results = DB::connection('pgsql')->select($query);
        $processedResults = $this->processUPSResults($results, $fromDate, $toDate, $partitionTables);

        $filename = "ups_report_{$fromDate}_to_{$toDate}.csv";

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($processedResults) {
            $file = fopen('php://output', 'w');

            // CSV Headers
            fputcsv($file, [
                'Client',
                'Bank Name',
                'Incident Id',
                'Circle',
                'Location',
                'Address',
                'ATMID',
                'Full Address',
                'DVRIP',
                'Incident Date Time',
                'EB Power Failure Alert Received date',
                'EB Power Failure Alert Received Time',
                'UPS Power Available Alert Received Date',
                'UPS Power Available Alert Received time',
                'UPS Power Failure Alert Received Date',
                'UPS Power Failure Alert Received time',
                'UPS Power Restore Alert Received Date',
                'UPS Power Restore Alert Received time',
                'EB Power Available Alert Received date',
                'EB Power Available Alert Received time'
            ]);

            // CSV Data
            foreach ($processedResults as $row) {
                fputcsv($file, [
                    $row['Customer'] ?? '',
                    $row['Bank'] ?? '',
                    $row['id'] ?? '',
                    $row['site_zone'] ?? '',
                    ($row['City'] ?? '') . ', ' . ($row['State'] ?? ''),
                    $row['ATMShortName'] ?? '',
                    $row['ATMID'] ?? '',
                    $row['SiteAddress'] ?? '',
                    $row['DVRIP'] ?? '',
                    $row['createtime'] ?? '',
                    $row['eb_fail_date'] ?? '-',
                    $row['eb_fail_time'] ?? '-',
                    $row['ups_available_date'] ?? '-',
                    $row['ups_available_time'] ?? '-',
                    $row['ups_fail_date'] ?? '-',
                    $row['ups_fail_time'] ?? '-',
                    $row['ups_restore_date'] ?? '-',
                    $row['ups_restore_time'] ?? '-',
                    $row['eb_restore_date'] ?? '-',
                    $row['eb_restore_time'] ?? '-'
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
