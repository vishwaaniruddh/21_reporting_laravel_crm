<?php

namespace App\Console\Commands;

use App\Services\ExcelReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * GenerateDailyExcelReports Command
 * 
 * Generates Excel reports for past dates that don't have reports yet.
 * Should be run daily (typically at midnight) to generate yesterday's report.
 * 
 * Usage:
 * - php artisan reports:generate-excel (generates missing reports for last 7 days)
 * - php artisan reports:generate-excel --days=30 (generates missing reports for last 30 days)
 * - php artisan reports:generate-excel --date=2026-01-08 (generates report for specific date)
 */
class GenerateDailyExcelReports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reports:generate-excel
                            {--days=7 : Number of days back to check for missing reports}
                            {--date= : Specific date to generate report for (YYYY-MM-DD)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate Excel reports for past dates (not current date)';

    /**
     * Execute the console command.
     */
    public function handle(ExcelReportService $excelService): int
    {
        $this->info('Starting Excel report generation...');
        
        // Check if specific date was provided
        if ($this->option('date')) {
            return $this->generateForSpecificDate($excelService, $this->option('date'));
        }
        
        // Generate missing reports for past days
        $daysBack = (int) $this->option('days');
        
        $this->info("Checking for missing reports in the last {$daysBack} days...");
        
        $result = $excelService->generateMissingReports($daysBack);
        
        // Display results
        $this->newLine();
        
        if (!empty($result['generated'])) {
            $this->info('✓ Generated reports for:');
            foreach ($result['generated'] as $date) {
                $this->line("  - {$date}");
            }
        }
        
        if (!empty($result['skipped'])) {
            $this->comment('⊘ Skipped (already exists):');
            foreach ($result['skipped'] as $date) {
                $this->line("  - {$date}");
            }
        }
        
        if (!empty($result['failed'])) {
            $this->error('✗ Failed:');
            foreach ($result['failed'] as $failure) {
                $this->line("  - {$failure['date']}: {$failure['error']}");
            }
        }
        
        $this->newLine();
        $this->info('Summary:');
        $this->table(
            ['Status', 'Count'],
            [
                ['Generated', count($result['generated'])],
                ['Skipped', count($result['skipped'])],
                ['Failed', count($result['failed'])],
            ]
        );
        
        return empty($result['failed']) ? Command::SUCCESS : Command::FAILURE;
    }
    
    /**
     * Generate report for a specific date
     * 
     * @param ExcelReportService $excelService
     * @param string $dateStr Date string (YYYY-MM-DD)
     * @return int Command exit code
     */
    protected function generateForSpecificDate(ExcelReportService $excelService, string $dateStr): int
    {
        try {
            $date = Carbon::parse($dateStr);
            
            // Check if date is today or future
            if ($date->isToday() || $date->isFuture()) {
                $this->error("Cannot generate report for current or future date: {$dateStr}");
                $this->comment('Excel reports are only generated for past dates.');
                return Command::FAILURE;
            }
            
            $this->info("Generating Excel report for {$date->toDateString()}...");
            
            // Check if report already exists
            if ($excelService->reportExists($date)) {
                $this->comment('Report already exists for this date.');
                
                if (!$this->confirm('Do you want to regenerate it?', false)) {
                    $this->info('Skipped.');
                    return Command::SUCCESS;
                }
            }
            
            // Generate report
            $filepath = $excelService->generateReport($date);
            
            $this->info("✓ Report generated successfully!");
            $this->line("  Path: {$filepath}");
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Failed to generate report: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
