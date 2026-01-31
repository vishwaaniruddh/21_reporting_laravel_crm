<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * ExcelReportService
 * 
 * Generates and manages pre-generated Excel reports for past dates.
 * Reports are generated automatically at midnight for the previous day.
 * 
 * Features:
 * - Automatic generation for past dates
 * - Caching to avoid regeneration
 * - Organized storage by date
 * - Download API endpoint
 */
class ExcelReportService
{
    private PartitionQueryRouter $partitionRouter;
    private string $storageDir = 'reports/excel';
    
    public function __construct(?PartitionQueryRouter $partitionRouter = null)
    {
        $this->partitionRouter = $partitionRouter ?? new PartitionQueryRouter();
    }
    
    /**
     * Generate Excel report for a specific date
     * 
     * @param Carbon $date The date to generate report for
     * @param array $filters Optional filters (NOT USED - generates all data for the date)
     * @return string Path to generated file
     */
    public function generateReport(Carbon $date, array $filters = []): string
    {
        // Build filename (without filters - always generate full report)
        $filename = $this->buildFilename($date, []);
        $filepath = "{$this->storageDir}/{$filename}";
        
        // Check if file already exists
        if (Storage::disk('public')->exists($filepath)) {
            Log::info('Excel report already exists', ['filepath' => $filepath]);
            return $filepath;
        }
        
        Log::info('Generating Excel report', [
            'date' => $date->toDateString(),
        ]);
        
        // Increase memory limit for Excel generation
        ini_set('memory_limit', '1024M');
        set_time_limit(600); // 10 minutes
        
        try {
            // Create Excel file using same logic as CSV export
            $fullPath = $this->createExcelFileFromController($date);
            
            Log::info('Excel report generated successfully', [
                'filepath' => $filepath,
            ]);
            
            return $filepath;
            
        } catch (\Exception $e) {
            Log::error('Failed to generate Excel report', [
                'date' => $date->toDateString(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Check if report exists for a date
     * 
     * @param Carbon $date The date to check
     * @param array $filters Optional filters
     * @return bool True if report exists
     */
    public function reportExists(Carbon $date, array $filters = []): bool
    {
        $filename = $this->buildFilename($date, $filters);
        $filepath = "{$this->storageDir}/{$filename}";
        
        return Storage::disk('public')->exists($filepath);
    }
    
    /**
     * Get report file path if it exists
     * 
     * @param Carbon $date The date
     * @param array $filters Optional filters
     * @return string|null File path or null if doesn't exist
     */
    public function getReportPath(Carbon $date, array $filters = []): ?string
    {
        $filename = $this->buildFilename($date, $filters);
        $filepath = "{$this->storageDir}/{$filename}";
        
        if (Storage::disk('public')->exists($filepath)) {
            return $filepath;
        }
        
        return null;
    }
    
    /**
     * Get public URL for report download
     * 
     * @param Carbon $date The date
     * @param array $filters Optional filters
     * @return string|null Public URL or null if doesn't exist
     */
    public function getReportUrl(Carbon $date, array $filters = []): ?string
    {
        $filepath = $this->getReportPath($date, $filters);
        
        if ($filepath) {
            return Storage::disk('public')->url($filepath);
        }
        
        return null;
    }
    
    /**
     * Build filename for report (no filters - always full report)
     * 
     * @param Carbon $date The date
     * @param array $filters Optional filters (ignored)
     * @return string Filename
     */
    private function buildFilename(Carbon $date, array $filters = []): string
    {
        $dateStr = $date->format('Y-m-d');
        return "alerts_report_{$dateStr}.xlsx";
    }
    
    /**
     * Build router filters from request filters
     * 
     * @param array $filters Request filters
     * @return array Router filters
     */
    private function buildRouterFilters(array $filters): array
    {
        $routerFilters = [];
        
        // Get panel IDs from sites filters
        if (!empty($filters['panel_type']) || !empty($filters['customer']) || 
            !empty($filters['dvrip']) || !empty($filters['atmid'])) {
            
            $panelIds = $this->getPanelIdsFromSitesFilters($filters);
            
            if (!empty($panelIds)) {
                $routerFilters['panel_ids'] = $panelIds;
            }
        }
        
        if (!empty($filters['panelid'])) {
            $routerFilters['panel_id'] = $filters['panelid'];
        }
        
        return $routerFilters;
    }
    
    /**
     * Get panel IDs from sites-based filters
     * 
     * @param array $filters Filters
     * @return array Panel IDs
     */
    private function getPanelIdsFromSitesFilters(array $filters): array
    {
        $sitesQuery = DB::connection('pgsql')->table('sites');
        
        if (!empty($filters['dvrip'])) {
            $sitesQuery->where('DVRIP', 'ilike', '%' . $filters['dvrip'] . '%');
        }
        if (!empty($filters['customer'])) {
            $sitesQuery->where('Customer', $filters['customer']);
        }
        if (!empty($filters['panel_type'])) {
            $sitesQuery->where('Panel_Make', $filters['panel_type']);
        }
        if (!empty($filters['atmid'])) {
            $sitesQuery->where('ATMID', 'ilike', '%' . $filters['atmid'] . '%');
        }

        $panelIds = $sitesQuery->select('OldPanelID', 'NewPanelID')->get();
        return $panelIds->pluck('OldPanelID')->merge($panelIds->pluck('NewPanelID'))->filter()->unique()->values()->toArray();
    }
    
    /**
     * Enrich alerts with sites data
     * 
     * @param array $alerts Alerts array
     * @return array Enriched alerts
     */
    private function enrichWithSites(array $alerts): array
    {
        if (empty($alerts)) {
            return [];
        }
        
        // Get unique panel IDs
        $panelIds = array_unique(array_column($alerts, 'panelid'));
        
        // Fetch sites data
        $sites = DB::connection('pgsql')
            ->table('sites')
            ->whereIn('OldPanelID', $panelIds)
            ->orWhereIn('NewPanelID', $panelIds)
            ->get()
            ->keyBy('OldPanelID');
        
        // Enrich alerts
        return array_map(function($alert) use ($sites) {
            $alertArray = is_object($alert) ? (array) $alert : $alert;
            $panelId = $alertArray['panelid'] ?? null;
            $site = $sites->get($panelId);
            
            return array_merge($alertArray, [
                'Customer' => $site->Customer ?? '',
                'Bank' => $site->Bank ?? '',
                'ATMID' => $site->ATMID ?? '',
                'ATMShortName' => $site->ATMShortName ?? '',
                'SiteAddress' => $site->SiteAddress ?? '',
                'DVRIP' => $site->DVRIP ?? '',
                'Panel_Make' => $site->Panel_Make ?? '',
                'zon' => $site->zon ?? '',
                'City' => $site->City ?? '',
                'State' => $site->State ?? '',
                'site_zone' => $site->Zone ?? '', // Add site_zone for Region column
                'testing_by_service_team' => $alertArray['testing_by_service_team'] ?? '',
                'testing_remark' => $alertArray['testing_remark'] ?? '',
            ]);
        }, $alerts);
    }
    
    /**
     * Create Excel file using same logic as CSV export
     * 
     * @param Carbon $date Report date
     * @return string Full path to created file
     */
    private function createExcelFileFromController(Carbon $date): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set title
        $sheet->setTitle('Alerts Report');
        
        // Add header - Match screen columns exactly (27 columns)
        $headers = [
            '#', 'Client', 'Incident #', 'Region', 'ATM ID', 'Address', 'City', 'State',
            'Zone', 'Alarm', 'Category', 'Message', 'Created', 'Received', 'Closed',
            'DVR IP', 'Panel', 'Panel ID', 'Bank', 'Type', 'Closed By', 'Closed Date',
            'Aging (hrs)', 'Remark', 'Send IP', 'Testing', 'Testing Remark'
        ];
        
        $sheet->fromArray($headers, null, 'A1');
        
        // Style header
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
        ];
        
        $sheet->getStyle('A1:AA1')->applyFromArray($headerStyle);
        
        // Auto-size columns (27 columns: A-AA)
        foreach (range('A', 'Z') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('AA')->setAutoSize(true);
        
        // Query data using PartitionQueryRouter
        $row = 2;
        $serialNumber = 1;
        $totalRecords = 0;
        
        $startDate = $date->copy()->startOfDay();
        $endDate = $date->copy()->endOfDay();
        
        // Use partition router to query data in smaller chunks
        $chunkSize = 100; // Reduced from 500 to avoid memory issues
        $offset = 0;
        
        while (true) {
            // Query chunk via partition router
            $options = [
                'limit' => $chunkSize,
                'offset' => $offset,
                'order_by' => 'id',
                'order_direction' => 'DESC',
            ];
            
            try {
                $alerts = $this->partitionRouter->queryDateRange($startDate, $endDate, [], $options, ['alerts', 'backalerts']);
            } catch (\Exception $e) {
                Log::error('Failed to query partition for Excel generation', [
                    'error' => $e->getMessage(),
                    'offset' => $offset
                ]);
                break;
            }
            
            if ($alerts->isEmpty()) {
                break; // No more data
            }
            
            // Convert to array and enrich with sites data
            $alertsArray = $alerts->map(function($record) {
                return (array) $record;
            })->toArray();
            
            $enriched = $this->enrichWithSitesForExcel($alertsArray);
            
            // Write to Excel
            foreach ($enriched as $alert) {
                $alert = (object) $alert;
                $isRestoral = str_ends_with($alert->alarm ?? '', 'R');
                $message = $isRestoral ? ($alert->alerttype ?? '') . ' Restoral' : ($alert->alerttype ?? '');
                $type = $isRestoral ? 'Non-Reactive' : 'Reactive';
                
                $sheet->fromArray([
                    $serialNumber,                      // #
                    $alert->Customer ?? '',             // Client
                    $alert->id ?? '',                   // Incident #
                    $alert->site_zone ?? '',            // Region
                    $alert->ATMID ?? '',                // ATM ID
                    $alert->SiteAddress ?? '',          // Address
                    $alert->City ?? '',                 // City
                    $alert->State ?? '',                // State
                    $alert->zone ?? '',                 // Zone
                    $alert->alarm ?? '',                // Alarm
                    $alert->alerttype ?? '',            // Category
                    $message,                           // Message
                    $alert->createtime ?? '',           // Created
                    $alert->receivedtime ?? '',         // Received
                    $alert->closedtime ?? '',           // Closed
                    $alert->DVRIP ?? '',                // DVR IP
                    $alert->Panel_Make ?? '',           // Panel
                    $alert->panelid ?? '',              // Panel ID
                    $alert->Bank ?? '',                 // Bank
                    $type,                              // Type
                    $alert->closedBy ?? '',             // Closed By
                    $alert->closedtime ?? '',           // Closed Date
                    number_format($alert->aging ?? 0, 2), // Aging (hrs)
                    $alert->comment ?? '',              // Remark
                    $alert->sendip ?? '',               // Send IP
                    '',                                 // Testing (empty for now)
                    '',                                 // Testing Remark (empty for now)
                ], null, "A{$row}");
                
                $row++;
                $serialNumber++;
                $totalRecords++;
            }
            
            // Log progress
            if ($totalRecords % 500 == 0) {
                Log::info("Excel generation progress: {$totalRecords} records processed");
            }
            
            // Safety limit: max 50,000 records (reduced from 100k)
            if ($serialNumber > 50000) {
                Log::warning('Excel report generation stopped at 50,000 records limit', [
                    'date' => $date->toDateString(),
                    'total_records' => $totalRecords
                ]);
                break;
            }
            
            // Move offset forward by actual records fetched (not fixed chunkSize)
            $offset += $alerts->count();
            
            // Free memory aggressively
            unset($alerts, $alertsArray, $enriched);
            gc_collect_cycles();
        }
        
        Log::info('Excel report data collection complete', [
            'total_records' => $totalRecords,
            'date' => $date->toDateString()
        ]);
        
        // Add borders to data (27 columns: A-AA)
        if ($row > 2) {
            $sheet->getStyle("A2:AA" . ($row - 1))->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ]);
        }
        
        // Save file
        $filename = $this->buildFilename($date, []);
        $directory = storage_path("app/public/{$this->storageDir}");
        
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $fullPath = "{$directory}/{$filename}";
        
        $writer = new Xlsx($spreadsheet);
        $writer->save($fullPath);
        
        // Free memory
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        
        return $fullPath;
    }
    
    /**
     * Enrich alerts with sites data for Excel export
     * Same logic as controller's enrichWithSites method
     * 
     * @param array $alerts Alerts array
     * @return array Enriched alerts
     */
    private function enrichWithSitesForExcel(array $alerts): array
    {
        if (empty($alerts)) return [];

        // Get unique panel IDs
        $panelIds = collect($alerts)->map(function($alert) {
            if (is_object($alert)) {
                return $alert->panelid ?? null;
            }
            return $alert['panelid'] ?? null;
        })->unique()->filter()->values()->toArray();
        
        if (empty($panelIds)) return $alerts;

        // Fetch sites data for these panels - FIX: Properly group OR conditions
        $sites = DB::connection('pgsql')
            ->table('sites')
            ->where(function($query) use ($panelIds) {
                $query->whereIn('OldPanelID', $panelIds)
                      ->orWhereIn('NewPanelID', $panelIds);
            })
            ->select(['OldPanelID', 'NewPanelID', 'Customer', 'Zone', 'ATMID', 'SiteAddress', 'City', 'State', 'DVRIP', 'Panel_Make', 'Bank'])
            ->get();

        // Create lookup by panel ID
        $siteLookup = [];
        foreach ($sites as $site) {
            if ($site->OldPanelID) $siteLookup[$site->OldPanelID] = $site;
            if ($site->NewPanelID) $siteLookup[$site->NewPanelID] = $site;
        }

        // Enrich alerts
        return collect($alerts)->map(function($alert) use ($siteLookup) {
            // Convert to object if it's an array
            if (is_array($alert)) {
                $alert = (object) $alert;
            }
            
            $site = $siteLookup[$alert->panelid] ?? null;
            $alert->Customer = $site->Customer ?? null;
            $alert->site_zone = $site->Zone ?? null;
            $alert->ATMID = $site->ATMID ?? null;
            $alert->SiteAddress = $site->SiteAddress ?? null;
            $alert->City = $site->City ?? null;
            $alert->State = $site->State ?? null;
            $alert->DVRIP = $site->DVRIP ?? null;
            $alert->Panel_Make = $site->Panel_Make ?? null;
            $alert->Bank = $site->Bank ?? null;
            $alert->testing_by_service_team = '';
            $alert->testing_remark = '';
            return $alert;
        })->toArray();
    }
    
    /**
     * Create Excel file from alerts data with streaming approach
     * 
     * @param Carbon $startDate Start date
     * @param Carbon $endDate End date
     * @param array $routerFilters Router filters
     * @param Carbon $date Report date
     * @param array $filters Filters used
     * @return string Full path to created file
     */
    private function createExcelFileStreaming(Carbon $startDate, Carbon $endDate, array $routerFilters, Carbon $date, array $filters): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set title
        $sheet->setTitle('Alerts Report');
        
        // Add header - STREAMING VERSION - Match screen columns exactly (27 columns)
        $headers = [
            '#', 'Client', 'Incident #', 'Region', 'ATM ID', 'Address', 'City', 'State',
            'Zone', 'Alarm', 'Category', 'Message', 'Created', 'Received', 'Closed',
            'DVR IP', 'Panel', 'Panel ID', 'Bank', 'Type', 'Closed By', 'Closed Date',
            'Aging (hrs)', 'Remark', 'Send IP', 'Testing', 'Testing Remark'
        ];
        
        $sheet->fromArray($headers, null, 'A1');
        
        // Style header
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
        ];
        
        $sheet->getStyle('A1:AA1')->applyFromArray($headerStyle);
        
        // Auto-size columns (27 columns: A-AA)
        foreach (range('A', 'Z') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('AA')->setAutoSize(true);
        
        // Query data in chunks and write to Excel
        $row = 2;
        $serialNumber = 1;
        $chunkSize = 100; // Reduced from 500 to 100 for better memory management
        $offset = 0;
        $totalRecords = 0;
        
        while (true) {
            // Query chunk
            $options = [
                'limit' => $chunkSize,
                'offset' => $offset,
                'order_by' => 'receivedtime',
                'order_direction' => 'DESC',
            ];
            
            $alerts = $this->partitionRouter->queryDateRange($startDate, $endDate, $routerFilters, $options, ['alerts', 'backalerts']);
            
            if ($alerts->isEmpty()) {
                break; // No more data
            }
            
            // Convert to array and enrich
            $alertsArray = $alerts->map(function($record) {
                return (array) $record;
            })->toArray();
            
            $enrichedAlerts = $this->enrichWithSites($alertsArray);
            
            // Write to Excel
            foreach ($enrichedAlerts as $alert) {
                $isRestoral = isset($alert['alarm']) && str_ends_with($alert['alarm'], 'R');
                $message = $isRestoral ? ($alert['alerttype'] ?? '') . ' Restoral' : ($alert['alerttype'] ?? '');
                $type = $isRestoral ? 'Non-Reactive' : 'Reactive';
                $closedDate = !empty($alert['closedtime']) ? date('M d, Y', strtotime($alert['closedtime'])) : '';
                
                $sheet->fromArray([
                    $serialNumber,                          // # (Serial)
                    $alert['Customer'] ?? '',               // Client
                    $alert['id'] ?? '',                     // Incident #
                    $alert['site_zone'] ?? '',              // Region
                    $alert['ATMID'] ?? '',                  // ATM ID
                    $alert['SiteAddress'] ?? '',            // Address
                    $alert['City'] ?? '',                   // City
                    $alert['State'] ?? '',                  // State
                    $alert['zone'] ?? '',                   // Zone
                    $alert['alarm'] ?? '',                  // Alarm
                    $alert['alerttype'] ?? '',              // Category
                    $message,                               // Message
                    $alert['createtime'] ?? '',             // Created
                    $alert['receivedtime'] ?? '',           // Received
                    $alert['closedtime'] ?? '',             // Closed
                    $alert['DVRIP'] ?? '',                  // DVR IP
                    $alert['Panel_Make'] ?? '',             // Panel
                    $alert['panelid'] ?? '',                // Panel ID
                    $alert['Bank'] ?? '',                   // Bank
                    $type,                                  // Type
                    $alert['closedBy'] ?? '',               // Closed By
                    $closedDate,                            // Closed Date
                    $this->calculateAging($alert),          // Aging (hrs)
                    $alert['comment'] ?? '',                // Remark
                    $alert['sendip'] ?? '',                 // Send IP
                    $alert['testing_by_service_team'] ?? '', // Testing
                    $alert['testing_remark'] ?? '',         // Testing Remark
                ], null, "A{$row}");
                
                $row++;
                $serialNumber++;
            }
            
            $recordCount = count($enrichedAlerts);
            $totalRecords += $recordCount;
            
            // Free memory
            unset($alerts, $alertsArray, $enrichedAlerts);
            gc_collect_cycles(); // Force garbage collection
            
            // Move offset forward by actual records fetched (not fixed chunkSize)
            $offset += $alerts->count();
            
            // Log progress every 1000 records
            if ($totalRecords > 0 && $totalRecords % 1000 == 0) {
                Log::info("Excel generation progress: {$totalRecords} records processed");
            }
            
            // Safety limit: max 10,000 records (reduced from 50k for memory)
            if ($serialNumber > 10000) {
                Log::warning('Excel report generation stopped at 10,000 records limit', [
                    'date' => $date->toDateString(),
                    'total_records' => $totalRecords
                ]);
                break;
            }
        }
        
        Log::info('Excel report data collection complete', [
            'total_records' => $totalRecords,
            'date' => $date->toDateString()
        ]);
        
        // Add borders to data (27 columns: A-AA)
        if ($row > 2) {
            $sheet->getStyle("A2:AA" . ($row - 1))->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ]);
        }
        
        // Save file
        $filename = $this->buildFilename($date, $filters);
        $directory = storage_path("app/public/{$this->storageDir}");
        
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $fullPath = "{$directory}/{$filename}";
        
        $writer = new Xlsx($spreadsheet);
        $writer->save($fullPath);
        
        // Free memory
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        
        return $fullPath;
    }
    
    /**
     * Create Excel file from alerts data
     * 
     * @param array $alerts Enriched alerts data
     * @param Carbon $date Report date
     * @param array $filters Filters used
     * @return string Full path to created file
     */
    private function createExcelFile(array $alerts, Carbon $date, array $filters): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set title
        $sheet->setTitle('Alerts Report');
        
        // Add header
        $headers = [
            'S.No', 'Panel ID', 'Customer', 'Bank', 'ATMID', 'ATM Short Name',
            'Site Address', 'DVR IP', 'Panel Make', 'Zone Name', 'City', 'State',
            'Zone', 'Alarm', 'Alert Type', 'Create Time', 'Received Time',
            'Closed Time', 'Closed By', 'Comment', 'Send IP', 'Aging (Hours)'
        ];
        
        $sheet->fromArray($headers, null, 'A1');
        
        // Style header
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
        ];
        
        $sheet->getStyle('A1:V1')->applyFromArray($headerStyle);
        
        // Auto-size columns
        foreach (range('A', 'V') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Add data
        $row = 2;
        foreach ($alerts as $index => $alert) {
            $sheet->fromArray([
                $index + 1,
                $alert['panelid'] ?? '',
                $alert['Customer'] ?? '',
                $alert['Bank'] ?? '',
                $alert['ATMID'] ?? '',
                $alert['ATMShortName'] ?? '',
                $alert['SiteAddress'] ?? '',
                $alert['DVRIP'] ?? '',
                $alert['Panel_Make'] ?? '',
                $alert['zon'] ?? '',
                $alert['City'] ?? '',
                $alert['State'] ?? '',
                $alert['zone'] ?? '',
                $alert['alarm'] ?? '',
                $alert['alerttype'] ?? '',
                $alert['createtime'] ?? '',
                $alert['receivedtime'] ?? '',
                $alert['closedtime'] ?? '',
                $alert['closedBy'] ?? '',
                $alert['comment'] ?? '',
                $alert['sendip'] ?? '',
                $this->calculateAging($alert),
            ], null, "A{$row}");
            
            $row++;
        }
        
        // Add borders to data
        if ($row > 2) {
            $sheet->getStyle("A2:V" . ($row - 1))->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ]);
        }
        
        // Save file
        $filename = $this->buildFilename($date, $filters);
        $directory = storage_path("app/public/{$this->storageDir}");
        
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $fullPath = "{$directory}/{$filename}";
        
        $writer = new Xlsx($spreadsheet);
        $writer->save($fullPath);
        
        return $fullPath;
    }
    
    /**
     * Calculate aging in hours
     * 
     * @param array $alert Alert data
     * @return float Aging in hours
     */
    private function calculateAging(array $alert): float
    {
        if (empty($alert['closedtime']) || empty($alert['receivedtime'])) {
            return 0;
        }
        
        try {
            $received = Carbon::parse($alert['receivedtime']);
            $closed = Carbon::parse($alert['closedtime']);
            return round($received->diffInSeconds($closed) / 3600, 2);
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * Generate reports for all past dates that don't have reports yet
     * 
     * This should be run daily to generate reports for yesterday
     * 
     * @param int $daysBack How many days back to check (default: 7)
     * @return array Summary of generated reports
     */
    public function generateMissingReports(int $daysBack = 7): array
    {
        $generated = [];
        $skipped = [];
        $failed = [];
        
        $today = Carbon::today();
        
        for ($i = 1; $i <= $daysBack; $i++) {
            $date = $today->copy()->subDays($i);
            
            // Check if report exists
            if ($this->reportExists($date)) {
                $skipped[] = $date->toDateString();
                continue;
            }
            
            try {
                $this->generateReport($date);
                $generated[] = $date->toDateString();
            } catch (\Exception $e) {
                $failed[] = [
                    'date' => $date->toDateString(),
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return [
            'generated' => $generated,
            'skipped' => $skipped,
            'failed' => $failed
        ];
    }
}
