<?php

namespace App\Http\Controllers;

use App\Models\Postgres\PgReport;
use App\Models\Client;
use App\Models\Postgres\PgSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;

class AlertsController extends Controller
{
    public function show($id)
    {
        $alert = PgReport::on('pgsql')
            ->leftJoin('sites', function($join) {
                $join->on('alerts.panelid', '=', DB::raw('"sites"."OldPanelID"'))
                     ->orOn('alerts.panelid', '=', DB::raw('"sites"."NewPanelID"'));
            })
            ->select([
                'alerts.*',
                DB::raw('CASE 
                    WHEN alerts.closedtime IS NOT NULL AND alerts.receivedtime IS NOT NULL 
                    THEN EXTRACT(EPOCH FROM (alerts.closedtime::timestamp - alerts.receivedtime::timestamp))/3600 
                    ELSE 0 
                    END as aging'), // Calculate aging in hours
                'sites.Customer',
                'sites.Zone as site_zone',
                'sites.ATMID',
                'sites.SiteAddress',
                'sites.City',
                'sites.State',
                'sites.DVRIP',
                'sites.Panel_Make',
                'sites.Bank'
            ])
            ->findOrFail($id);
        return view('reports.show', compact('alert'));
    }

    private function exportToExcel($query)
    {
        try {
            // Add debugging
            \Log::info('Starting export process');
            
            // Increase memory and execution time limits
            ini_set('memory_limit', '1024M'); // 1GB memory limit
            ini_set('max_execution_time', 300); // 5 minutes
            set_time_limit(300); // 5 minutes
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set headers
        $headers = [
            'Client Name', 'Incident Number', 'Region', 'ATMID', 'Address', 'City', 'State',
            'Zone', 'Alarm', 'Incident Category', 'Alarm Message', 'Incident Date Time',
            'Alarm Received Date Time', 'Close Date Time', 'DVRIP', 'Panel Make',
            'Panel ID', 'Bank', 'Reactive', 'Closed By', 'Closed Date', 'Aging',
            'Remark', 'Send IP', 'Testing By Service Team', 'Testing Remark'
        ];

        $sheet->fromArray([$headers], NULL, 'A1');

        // Add data using chunks to prevent memory issues
        $row = 2;
        $chunkSize = 500; // Process 500 records at a time to reduce memory usage
        
        // Optimize the query for better performance with PostgreSQL
        $query = $query->select([
            'alerts.id',
            'alerts.panelid',
            'alerts.zone',
            'alerts.alarm',
            'alerts.alerttype',
            'alerts.createtime',
            'alerts.receivedtime',
            'alerts.closedtime',
            'alerts.closedBy',
            'alerts.comment',
            'alerts.sendip',
            DB::raw('CASE 
                WHEN alerts.closedtime IS NOT NULL AND alerts.receivedtime IS NOT NULL 
                THEN EXTRACT(EPOCH FROM (alerts.closedtime::timestamp - alerts.receivedtime::timestamp))/3600 
                ELSE 0 
                END as aging'), // Calculate aging in hours
            'sites.Customer',
            'sites.Zone as site_zone',
            'sites.ATMID',
            'sites.SiteAddress',
            'sites.City',
            'sites.State',
            'sites.DVRIP',
            'sites.Panel_Make',
            'sites.Bank'
        ]);

        // Check if query has any data
        $totalRecords = $query->count();
        \Log::info("Total records to export: {$totalRecords}");
        
        if ($totalRecords == 0) {
            throw new \Exception('No records found to export');
        }
        
        $totalProcessed = 0;
        $query->chunk($chunkSize, function($reports) use (&$row, $sheet, &$totalProcessed) {
            foreach ($reports as $report) {
                $sheet->fromArray([[
                    $report->Customer ?? '',
                    $report->id,
                    $report->site_zone ?? '',
                    $report->ATMID ?? '',
                    $report->SiteAddress ?? '',
                    $report->City ?? '',
                    $report->State ?? '',
                    $report->zone,
                    $report->alarm,
                    $report->alerttype,
                    str_ends_with($report->alarm, 'R') ? $report->alerttype . ' Restoral' : $report->alerttype,
                    $report->createtime,
                    $report->receivedtime,
                    $report->closedtime,
                    $report->DVRIP ?? '',
                    $report->Panel_Make ?? '',
                    $report->panelid,
                    $report->Bank ?? '',
                    str_ends_with($report->alarm, 'R') ? 'Non-Reactive' : 'Reactive',
                    $report->closedBy,
                    $report->closedtime,
                    number_format($report->aging, 2),
                    $report->comment,
                    $report->sendip,
                    '', // Empty for testing_by_service_team
                    '' // Empty for testing_remark
                ]], NULL, 'A' . $row);
                $row++;
                $totalProcessed++;
            }
            
            // Log progress every 1000 records
            if ($totalProcessed % 1000 == 0) {
                \Log::info("Processed {$totalProcessed} records");
            }
        });

        // Auto-size columns
        foreach (range('A', 'Z') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Create file
        \Log::info("Creating Excel file with {$totalProcessed} records");
        $writer = new Xlsx($spreadsheet);
        $filename = 'reports_' . date('Y-m-d_His') . '.xlsx';
        
        // Clear any output buffers
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers for proper download
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        
        $writer->save('php://output');
        exit;
        } catch (\Exception $e) {
            // Log the error
            \Log::error('Export failed: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            // Return error response
            return response()->json([
                'error' => 'Export failed: ' . $e->getMessage()
            ], 500);
        }
    }

    private function exportToCsv($query)
    {
        try {
            // Add debugging
            \Log::info('Starting CSV export process');
            
            // Increase memory and execution time limits
            ini_set('memory_limit', '1024M'); // 1GB memory limit
            ini_set('max_execution_time', 300); // 5 minutes
            set_time_limit(300); // 5 minutes
            
            // Optimize the query for better performance with PostgreSQL
            $query = $query->select([
                'alerts.id',
                'alerts.panelid',
                'alerts.zone',
                'alerts.alarm',
                'alerts.alerttype',
                'alerts.createtime',
                'alerts.receivedtime',
                'alerts.closedtime',
                'alerts.closedBy',
                'alerts.comment',
                'alerts.sendip',
                DB::raw('CASE 
                    WHEN alerts.closedtime IS NOT NULL AND alerts.receivedtime IS NOT NULL 
                    THEN EXTRACT(EPOCH FROM (alerts.closedtime::timestamp - alerts.receivedtime::timestamp))/3600 
                    ELSE 0 
                END as aging'), // Calculate aging in hours
                'sites.Customer',
                'sites.Zone as site_zone',
                'sites.ATMID',
                'sites.SiteAddress',
                'sites.City',
                'sites.State',
                'sites.DVRIP',
                'sites.Panel_Make',
                'sites.Bank'
            ]);

            // Check if query has any data
            $totalRecords = $query->count();
            \Log::info("Total records to export: {$totalRecords}");
            
            if ($totalRecords == 0) {
                throw new \Exception('No records found to export');
            }
            
            // Clear any output buffers
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            // Set headers for proper download
            $filename = 'reports_' . date('Y-m-d_His') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: max-age=0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            
            // Create output stream
            $output = fopen('php://output', 'w');
            
            // Write UTF-8 BOM for proper Excel compatibility
            fwrite($output, "\xEF\xBB\xBF");
            
            // Write headers
            $headers = [
                'Client Name', 'Incident Number', 'Region', 'ATMID', 'Address', 'City', 'State',
                'Zone', 'Alarm', 'Incident Category', 'Alarm Message', 'Incident Date Time',
                'Alarm Received Date Time', 'Close Date Time', 'DVRIP', 'Panel Make',
                'Panel ID', 'Bank', 'Reactive', 'Closed By', 'Closed Date', 'Aging',
                'Remark', 'Send IP', 'Testing By Service Team', 'Testing Remark'
            ];
            fputcsv($output, $headers);
            
            // Process data in chunks
            $chunkSize = 1000;
            $totalProcessed = 0;
            
            $query->chunk($chunkSize, function($reports) use ($output, &$totalProcessed) {
                foreach ($reports as $report) {
                    $row = [
                        $report->Customer ?? '',
                        $report->id,
                        $report->site_zone ?? '',
                        $report->ATMID ?? '',
                        $report->SiteAddress ?? '',
                        $report->City ?? '',
                        $report->State ?? '',
                        $report->zone,
                        $report->alarm,
                        $report->alerttype,
                        str_ends_with($report->alarm, 'R') ? $report->alerttype . ' Restoral' : $report->alerttype,
                        $report->createtime,
                        $report->receivedtime,
                        $report->closedtime,
                        $report->DVRIP ?? '',
                        $report->Panel_Make ?? '',
                        $report->panelid,
                        $report->Bank ?? '',
                        str_ends_with($report->alarm, 'R') ? 'Non-Reactive' : 'Reactive',
                        $report->closedBy,
                        $report->closedtime,
                        number_format($report->aging, 2),
                        $report->comment,
                        $report->sendip,
                        '', // Empty for testing_by_service_team
                        '' // Empty for testing_remark
                    ];
                    
                    fputcsv($output, $row);
                    $totalProcessed++;
                }
                
                // Log progress every 1000 records
                if ($totalProcessed % 1000 == 0) {
                    \Log::info("Processed {$totalProcessed} records");
                }
            });
            
            fclose($output);
            \Log::info("CSV export completed with {$totalProcessed} records");
            exit;
            
        } catch (\Exception $e) {
            // Log the error
            \Log::error('CSV Export failed: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            // Return error response
            return response()->json([
                'error' => 'CSV Export failed: ' . $e->getMessage()
            ], 500);
        }
    }

        public function index(Request $request)
        {
            // Cache frequently used data
            $customers = cache()->remember('customers', 3600, function() {
                return Client::all();
            });
            
            $panelMakes = cache()->remember('panel_makes', 3600, function() {
                return PgSite::on('pgsql')->distinct()->pluck('Panel_Make');
            });

            // Base query with optimization using PostgreSQL
            $query = PgReport::on('pgsql')->leftJoin('sites', function($join) {
                $join->on('alerts.panelid', '=', DB::raw('"sites"."OldPanelID"'))
                    ->orOn('alerts.panelid', '=', DB::raw('"sites"."NewPanelID"'));
            })
            ->select([
                'alerts.id',
                'alerts.panelid',
                'alerts.zone as alert_zone',
                'alerts.alarm',
                'alerts.alerttype',
                'alerts.createtime',
                'alerts.receivedtime',
                'alerts.closedtime',
                'alerts.closedBy',
                'alerts.comment',
                'alerts.sendip',
                DB::raw('CASE 
                    WHEN alerts.closedtime IS NOT NULL AND alerts.receivedtime IS NOT NULL 
                    THEN EXTRACT(EPOCH FROM (alerts.closedtime::timestamp - alerts.receivedtime::timestamp))/3600 
                    ELSE 0 
                    END as aging'), // Calculate aging in hours
                'sites.Customer',
                'sites.Zone as site_zone',
                'sites.ATMID',
                'sites.SiteAddress',
                'sites.City',
                'sites.State',
                'sites.DVRIP',
                'sites.Panel_Make',
                'sites.Bank'
            ]);

            if ($request->filled('panelid')) {
                $query->where('panelid', 'like', '%' . $request->panelid . '%');
            }

            // Site related filters
            if ($request->filled('customer')) {
                $query->where(function($q) use ($request) {
                    $q->whereExists(function($subquery) use ($request) {
                        $subquery->from('sites')
                            ->whereRaw('"alerts"."panelid" = "sites"."OldPanelID"')
                            ->where('Customer', $request->customer);
                    })->orWhereExists(function($subquery) use ($request) {
                        $subquery->from('sites')
                            ->whereRaw('"alerts"."panelid" = "sites"."NewPanelID"')
                            ->where('Customer', $request->customer);
                    });
                });
            }

            if ($request->filled('panel')) {
                $query->where(function($q) use ($request) {
                    $q->whereExists(function($subquery) use ($request) {
                        $subquery->from('sites')
                            ->whereRaw('"alerts"."panelid" = "sites"."OldPanelID"')
                            ->where('Panel_Make', $request->panel);
                    })->orWhereExists(function($subquery) use ($request) {
                        $subquery->from('sites')
                            ->whereRaw('"alerts"."panelid" = "sites"."NewPanelID"')
                            ->where('Panel_Make', $request->panel);
                    });
                });
            }

            if ($request->filled('atmid')) {
                $query->where(function($q) use ($request) {
                    $q->whereExists(function($subquery) use ($request) {
                        $subquery->from('sites')
                            ->whereRaw('"alerts"."panelid" = "sites"."OldPanelID"')
                            ->where('ATMID', 'ilike', '%' . $request->atmid . '%');
                    })->orWhereExists(function($subquery) use ($request) {
                        $subquery->from('sites')
                            ->whereRaw('"alerts"."panelid" = "sites"."NewPanelID"')
                            ->where('ATMID', 'ilike', '%' . $request->atmid . '%');
                    });
                });
            }

            if ($request->filled('dvrip')) {
                $query->where(function($q) use ($request) {
                    $q->whereExists(function($subquery) use ($request) {
                        $subquery->from('sites')
                            ->whereRaw('"alerts"."panelid" = "sites"."OldPanelID"')
                            ->where('DVRIP', 'ilike', '%' . $request->dvrip . '%');
                    })->orWhereExists(function($subquery) use ($request) {
                        $subquery->from('sites')
                            ->whereRaw('"alerts"."panelid" = "sites"."NewPanelID"')
                            ->where('DVRIP', 'ilike', '%' . $request->dvrip . '%');
                    });
                });
            }

            if ($request->filled('from_date')) {
                $query->where(function($q) use ($request) {
                    if ($request->filled('from_date')) {
                        $fromDate = date('Y-m-d 00:00:00', strtotime($request->from_date));
                        $q->where('receivedtime', '>=', $fromDate);
                    }
                    
                    if ($request->filled('from_date')) {
                        $toDate = date('Y-m-d 23:59:59', strtotime($request->from_date));
                        $q->where('receivedtime', '<=', $toDate);
                    }
                });
            }

            // Set higher time limit for all operations
            set_time_limit(300); // 5 minutes
            
            // Clone query for count to prevent interference with main query
            $countQuery = clone $query;
            
            if ($request->has('export')) {
                // Add some debugging
                \Log::info('Export requested with filters: ' . json_encode($request->all()));
                
                $exportType = $request->get('export');
                
                if ($exportType === 'csv') {
                    return $this->exportToCsv($query);
                } else {
                    return $this->exportToExcel($query);
                }
            }

            // Optimize count query by selecting only id
            $totalRecords = $countQuery->select('alerts.id')->count();

            // Optimize main query by selecting only needed fields
            $reports = $query->select([
                'alerts.id',
                'alerts.panelid',
                'alerts.zone',
                'alerts.alarm',
                'alerts.alerttype',
                'alerts.createtime',
                'alerts.receivedtime',
                'alerts.closedtime',
                'alerts.closedBy',
                'alerts.comment',
                'alerts.sendip',
                DB::raw('CASE 
                    WHEN alerts.closedtime IS NOT NULL AND alerts.receivedtime IS NOT NULL 
                    THEN EXTRACT(EPOCH FROM (alerts.closedtime::timestamp - alerts.receivedtime::timestamp))/3600 
                    ELSE 0 
                    END as aging'), // Calculate aging in hours
                'sites.Customer',
                'sites.Zone as site_zone',
                'sites.ATMID',
                'sites.SiteAddress',
                'sites.City',
                'sites.State',
                'sites.DVRIP',
                'sites.Panel_Make',
                'sites.Bank'
            ])
            ->orderBy('alerts.id', 'desc') // Order by ID in descending order (newest first)
            ->paginate($request->get('per_page', 50));

            return view('reports.viewalerts', compact('reports', 'customers', 'panelMakes', 'totalRecords'));
        }
}
