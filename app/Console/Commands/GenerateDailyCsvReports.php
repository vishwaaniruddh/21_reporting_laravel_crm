<?php

namespace App\Console\Commands;

use App\Services\CsvReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Generate CSV reports for previous days automatically
 * 
 * This command should run daily at midnight to pre-generate
 * CSV reports for yesterday's data.
 */
class GenerateDailyCsvReports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reports:generate-csv 
                            {--date= : Specific date to generate (YYYY-MM-DD)}
                            {--days-back=1 : Number of days back to generate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate CSV reports for previous days';

    /**
     * Execute the console command.
     */
    public function handle(CsvReportService $csvService)
    {
        $this->info('Starting CSV report generation...');
        
        if ($this->option('date')) {
            // Generate for specific date
            $date = Carbon::parse($this->option('date'));
            $this->generateForDate($csvService, $date);
        } else {
            // Generate for yesterday (or multiple days back)
            $daysBack = (int) $this->option('days-back');
            
            for ($i = 1; $i <= $daysBack; $i++) {
                $date = Carbon::today()->subDays($i);
                $this->generateForDate($csvService, $date);
            }
        }
        
        $this->info('CSV report generation completed!');
        return 0;
    }
    
    /**
     * Generate report for a specific date
     */
    protected function generateForDate(CsvReportService $csvService, Carbon $date)
    {
        // Don't generate for today or future dates
        if ($date->isToday() || $date->isFuture()) {
            $this->warn("Skipping {$date->toDateString()} - only past dates are allowed");
            return;
        }
        
        $this->info("Generating report for {$date->toDateString()}...");
        
        try {
            // Check if already exists
            if ($csvService->reportExists($date)) {
                $this->warn("  Report already exists, skipping");
                return;
            }
            
            // Generate the report
            $startTime = microtime(true);
            $filepath = $csvService->generateReport($date);
            $duration = round(microtime(true) - $startTime, 2);
            
            // Get file size
            $fullPath = storage_path("app/public/{$filepath}");
            $fileSize = file_exists($fullPath) ? filesize($fullPath) : 0;
            $fileSizeMB = round($fileSize / 1024 / 1024, 2);
            
            $this->info("  ✓ Generated: {$filepath}");
            $this->info("  ✓ Size: {$fileSizeMB} MB");
            $this->info("  ✓ Time: {$duration} seconds");
            
            Log::info('CSV report generated successfully', [
                'date' => $date->toDateString(),
                'filepath' => $filepath,
                'size_mb' => $fileSizeMB,
                'duration_seconds' => $duration
            ]);
            
        } catch (\Exception $e) {
            $this->error("  ✗ Failed: {$e->getMessage()}");
            Log::error('Failed to generate CSV report', [
                'date' => $date->toDateString(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
