<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * CsvReportService
 * 
 * Generates and manages pre-generated CSV reports for past dates.
 * Reports are generated automatically at midnight for the previous day.
 * 
 * Features:
 * - Automatic generation for past dates
 * - Caching to avoid regeneration
 * - Organized storage by date
 * - Instant download for pre-generated files
 */
class CsvReportService
{
    private PartitionQueryRouter $partitionRouter;
    private string $storageDir = 'reports/csv';
    
    public function __construct(?PartitionQueryRouter $partitionRouter = null)
    {
        $this->partitionRouter = $partitionRouter ?? new PartitionQueryRouter();
    }
    
    /**
     * Generate CSV report for a specific date
     * 
     * @param Carbon $date The date to generate report for
     * @return string Path to generated file
     */
    public function generateReport(Carbon $date): string
    {
        // Build filename
        $filename = $this->buildFilename($date);
        $filepath = "{$this->storageDir}/{$filename}";
        
        // Check if file already exists
        if (Storage::disk('public')->exists($filepath)) {
            Log::info('CSV report already exists', ['filepath' => $filepath]);
            return $filepath;
        }
        
        Log::info('Generating CSV report', [
            'date' => $date->toDateString(),
        ]);
        
        // Increase limits for large datasets
        ini_set('memory_limit', '1024M');
        set_time_limit(600); // 10 minutes
        
        try {
            // Create CSV file
            $fullPath = $this->createCsvFile($date);
            
            Log::info('CSV report generated successfully', [
                'filepath' => $filepath,
                'size' => file_exists($fullPath) ? filesize($fullPath) : 0
            ]);
            
            return $filepath;
            
        } catch (\Exception $e) {
            Log::error('Failed to generate CSV report', [
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
     * @return bool True if report exists
     */
    public function reportExists(Carbon $date): bool
    {
        $filename = $this->buildFilename($date);
        $filepath = "{$this->storageDir}/{$filename}";
        
        return Storage::disk('public')->exists($filepath);
    }
    
    /**
     * Get report file path if it exists
     * 
     * @param Carbon $date The date
     * @return string|null File path or null if doesn't exist
     */
    public function getReportPath(Carbon $date): ?string
    {
        $filename = $this->buildFilename($date);
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
     * @return string|null Public URL or null if doesn't exist
     */
    public function getReportUrl(Carbon $date): ?string
    {
        $filepath = $this->getReportPath($date);
        
        if ($filepath) {
            return Storage::disk('public')->url($filepath);
        }
        
        return null;
    }
    
    /**
     * Build filename for report
     * 
     * @param Carbon $date The date
     * @return string Filename
     */
    private function buildFilename(Carbon $date): string
    {
        $dateStr = $date->format('Y-m-d');
        return "alerts_report_{$dateStr}.csv";
    }
    
    /**
     * Create CSV file for a date
     * 
     * @param Carbon $date Report date
     * @return string Full path to created file
     */
    private function createCsvFile(Carbon $date): string
    {
        $startDate = $date->copy()->startOfDay();
        $endDate = $date->copy()->endOfDay();
        
        // Create directory if it doesn't exist
        $directory = storage_path("app/public/{$this->storageDir}");
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $filename = $this->buildFilename($date);
        $fullPath = "{$directory}/{$filename}";
        
        // Open file for writing
        $file = fopen($fullPath, 'w');
        
        // Write BOM for UTF-8
        fwrite($file, "\xEF\xBB\xBF");
        
        // Write header
        fputcsv($file, [
            '#', 'Client', 'Incident #', 'Region', 'ATM ID', 'Address', 'City', 'State',
            'Zone', 'Alarm', 'Category', 'Message', 'Created', 'Received', 'Closed',
            'DVR IP', 'Panel', 'Panel ID', 'Bank', 'Type', 'Closed By', 'Closed Date',
            'Aging (hrs)', 'Remark', 'Send IP', 'Testing', 'Testing Remark'
        ]);
        
        // Query and write data in chunks
        $rowNumber = 1;
        $totalRecords = 0;
        $chunkSize = 1000;
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
                $results = $this->partitionRouter->queryDateRange($startDate, $endDate, [], $options, ['alerts', 'backalerts']);
            } catch (\Exception $e) {
                Log::error('Failed to query partition for CSV generation', [
                    'error' => $e->getMessage(),
                    'offset' => $offset
                ]);
                break;
            }
            
            if ($results->isEmpty()) {
                break; // No more data
            }
            
            // Convert to array and enrich with sites data
            $alertsArray = $results->map(function($record) {
                return (array) $record;
            })->toArray();
            
            $enriched = $this->enrichWithSites($alertsArray);
            
            // Write to CSV
            foreach ($enriched as $alert) {
                $alert = (object) $alert;
                $isRestoral = str_ends_with($alert->alarm ?? '', 'R');
                $message = $isRestoral ? ($alert->alerttype ?? '') . ' Restoral' : ($alert->alerttype ?? '');
                $type = $isRestoral ? 'Non-Reactive' : 'Reactive';
                
                fputcsv($file, [
                    $rowNumber,                         // #
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
                    '',                                 // Testing
                    '',                                 // Testing Remark
                ]);
                
                $rowNumber++;
                $totalRecords++;
            }
            
            // Log progress every 10k records
            if ($totalRecords % 10000 == 0) {
                Log::info("CSV generation progress: {$totalRecords} records processed");
            }
            
            // Move offset forward by actual records fetched (not fixed chunkSize)
            $offset += $results->count();
            
            // Free memory
            unset($results, $alertsArray, $enriched);
            if ($totalRecords % 5000 == 0) {
                gc_collect_cycles();
            }
        }
        
        fclose($file);
        
        Log::info('CSV file creation complete', [
            'total_records' => $totalRecords,
            'date' => $date->toDateString(),
            'file_size' => filesize($fullPath)
        ]);
        
        return $fullPath;
    }
    
    /**
     * Enrich alerts with sites data
     * 
     * @param array $alerts Alerts array
     * @return array Enriched alerts
     */
    private function enrichWithSites(array $alerts): array
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

        // Fetch sites data for these panels
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
}
