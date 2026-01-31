<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * MySQL File System Backup Worker
 * 
 * Copies MySQL physical data files to backup location with date-organized structure.
 * This worker runs daily to backup specific MySQL table files.
 * 
 * CONFIGURATION (Edit these values):
 * - Source directory: Change $sourceDirectory property
 * - Backup directory: Change $backupDirectory property
 * - Files to backup: Change $filesToBackup array
 * - Backup time: Use --backup-time option or scheduler
 */
class MysqlFileBackupWorker extends Command
{
    /**
     * ============================================
     * CONFIGURATION - EDIT THESE VALUES
     * ============================================
     */
    
    /**
     * SOURCE DIRECTORY - MySQL data directory
     * 
     * Default: C:\wamp64\bin\mysql\mysql5.7.19\data\esurv
     */
    protected string $sourceDirectory = 'C:\wamp64\bin\mysql\mysql5.7.19\data\esurv';
    
    /**
     * BACKUP DIRECTORY - Where to store backups
     * 
     * Default: D:\MysqlFileSystemBackup
     * Structure: D:\MysqlFileSystemBackup\YEAR\MONTH\DATE\files
     */
    protected string $backupDirectory = 'D:\MysqlFileSystemBackup';
    
    /**
     * FILES TO BACKUP - List of files to copy
     * 
     * Add or remove files as needed
     */
    protected array $filesToBackup = [
        'alerts.frm',
        'alerts.ibd',
        'alerts.TRG',
    ];
    
    /**
     * ============================================
     * END CONFIGURATION
     * ============================================
     */

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'backup:mysql-files-worker 
                            {--check-interval=86400 : Seconds between backups (default: 24 hours)}
                            {--source= : Override source directory}
                            {--destination= : Override backup directory}
                            {--backup-time=02:00 : Time to run daily backup (HH:MM format)}
                            {--run-once : Run backup once and exit}';

    /**
     * The console command description.
     */
    protected $description = 'Daily backup of MySQL physical data files to organized folder structure';

    /**
     * Flag to control the worker loop.
     */
    protected bool $shouldContinue = true;

    /**
     * Configuration values.
     */
    protected int $checkInterval;
    protected string $backupTime;
    protected bool $runOnce;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Parse configuration from options
        $this->checkInterval = (int) $this->option('check-interval');
        $this->backupTime = $this->option('backup-time');
        $this->runOnce = $this->option('run-once');
        
        // Override directories if provided
        if ($this->option('source')) {
            $this->sourceDirectory = $this->option('source');
        }
        
        if ($this->option('destination')) {
            $this->backupDirectory = $this->option('destination');
        }

        // Register signal handlers for graceful shutdown
        $this->registerSignalHandlers();

        // Log worker startup
        $this->logStartup();

        // If run-once mode, do backup and exit
        if ($this->runOnce) {
            $this->info('Running backup once...');
            $this->performBackup();
            return Command::SUCCESS;
        }

        // Main processing loop
        $this->info('Backup worker started. Press Ctrl+C to stop gracefully.');
        $this->newLine();

        while ($this->shouldContinue()) {
            try {
                $this->checkAndRunBackup();
            } catch (\Exception $e) {
                $this->error("Error in backup cycle: {$e->getMessage()}");
                Log::error('Backup worker cycle error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                // Sleep before retrying
                sleep(3600); // 1 hour
            }
        }

        // Log worker shutdown
        $this->logShutdown();

        return Command::SUCCESS;
    }

    /**
     * Check if it's time to run backup and execute if needed.
     */
    protected function checkAndRunBackup(): void
    {
        $now = Carbon::now();
        $targetTime = Carbon::createFromFormat('H:i', $this->backupTime);
        
        // Get today's backup path
        $todayBackupPath = $this->getTodayBackupPath();
        
        // Check if backup already done today
        if (is_dir($todayBackupPath)) {
            // Backup already completed today
            // Calculate time until next backup (tomorrow at target time)
            $nextRun = $now->copy()->addDay()->setTimeFromTimeString($this->backupTime);
            $sleepSeconds = $nextRun->diffInSeconds($now);
            
            // Ensure sleep seconds is positive
            if ($sleepSeconds <= 0) {
                $sleepSeconds = 3600; // Sleep for 1 hour and check again
            }
            
            $this->line("Backup already completed today at: {$todayBackupPath}");
            $this->line("Next backup scheduled for {$nextRun->format('Y-m-d H:i')}");
            $this->line("Sleeping for " . round($sleepSeconds / 3600, 1) . " hours...");
            
            sleep($sleepSeconds);
            return;
        }
        
        // Check if we've passed the target time today
        if ($now->format('H:i') >= $this->backupTime) {
            // We've passed the backup time today and backup hasn't been done
            $this->info("Running scheduled backup (current time: {$now->format('H:i')})...");
            $this->performBackup();
            
            // After backup, sleep until tomorrow's backup time
            $nextRun = $now->copy()->addDay()->setTimeFromTimeString($this->backupTime);
            $sleepSeconds = $nextRun->diffInSeconds($now);
            
            // Ensure sleep seconds is positive
            if ($sleepSeconds <= 0) {
                $sleepSeconds = 3600; // Sleep for 1 hour and check again
            }
            
            $this->line("Next backup scheduled for {$nextRun->format('Y-m-d H:i')}");
            $this->line("Sleeping for " . round($sleepSeconds / 3600, 1) . " hours...");
            sleep($sleepSeconds);
        } else {
            // Haven't reached backup time yet today
            $todayTargetTime = $now->copy()->setTimeFromTimeString($this->backupTime);
            $sleepSeconds = $todayTargetTime->diffInSeconds($now);
            
            // Ensure sleep seconds is positive
            if ($sleepSeconds <= 0) {
                $sleepSeconds = 3600; // Sleep for 1 hour and check again
            }
            
            $this->line("Current time: {$now->format('H:i')}. Backup scheduled for {$this->backupTime}.");
            $this->line("Sleeping for " . round($sleepSeconds / 3600, 1) . " hours until backup time...");
            sleep($sleepSeconds);
        }
    }

    /**
     * Perform the actual backup operation.
     */
    protected function performBackup(): void
    {
        $startTime = microtime(true);
        
        // Validate source directory
        if (!is_dir($this->sourceDirectory)) {
            $this->error("Source directory not found: {$this->sourceDirectory}");
            Log::error('Backup failed: Source directory not found', [
                'source' => $this->sourceDirectory,
            ]);
            return;
        }

        // Create backup directory structure: D:\MysqlFileSystemBackup\YEAR\MONTH\DATE
        $backupPath = $this->getTodayBackupPath();
        
        if (!is_dir($backupPath)) {
            if (!mkdir($backupPath, 0777, true)) {
                $this->error("Failed to create backup directory: {$backupPath}");
                Log::error('Backup failed: Could not create directory', [
                    'path' => $backupPath,
                ]);
                return;
            }
        }

        $this->info("Backup destination: {$backupPath}");
        $this->newLine();

        // Copy each file
        $successCount = 0;
        $failCount = 0;
        $totalSize = 0;

        foreach ($this->filesToBackup as $filename) {
            $sourcePath = $this->sourceDirectory . DIRECTORY_SEPARATOR . $filename;
            $destPath = $backupPath . DIRECTORY_SEPARATOR . $filename;

            if (!file_exists($sourcePath)) {
                $this->warn("  ⚠ File not found: {$filename}");
                $failCount++;
                continue;
            }

            $fileSize = filesize($sourcePath);
            $this->line("  Copying {$filename} (" . $this->formatBytes($fileSize) . ")...");

            if (copy($sourcePath, $destPath)) {
                $this->info("  ✓ Copied {$filename}");
                $successCount++;
                $totalSize += $fileSize;
                
                Log::info('File backed up successfully', [
                    'file' => $filename,
                    'size' => $fileSize,
                    'destination' => $destPath,
                ]);
            } else {
                $this->error("  ✗ Failed to copy {$filename}");
                $failCount++;
                
                Log::error('File backup failed', [
                    'file' => $filename,
                    'source' => $sourcePath,
                    'destination' => $destPath,
                ]);
            }
        }

        $duration = microtime(true) - $startTime;

        $this->newLine();
        $this->info("=== Backup Complete ===");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Files Backed Up', $successCount],
                ['Files Failed', $failCount],
                ['Total Size', $this->formatBytes($totalSize)],
                ['Duration', round($duration, 2) . 's'],
                ['Backup Location', $backupPath],
            ]
        );

        Log::info('Backup cycle completed', [
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'total_size' => $totalSize,
            'duration' => $duration,
            'backup_path' => $backupPath,
        ]);
    }

    /**
     * Get today's backup path.
     */
    protected function getTodayBackupPath(): string
    {
        $now = Carbon::now();
        return $this->backupDirectory . DIRECTORY_SEPARATOR . 
               $now->format('Y') . DIRECTORY_SEPARATOR . 
               $now->format('m') . DIRECTORY_SEPARATOR . 
               $now->format('d');
    }

    /**
     * Format bytes to human-readable size.
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Register signal handlers for graceful shutdown.
     */
    protected function registerSignalHandlers(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);

            pcntl_signal(SIGTERM, function () {
                $this->info('Received SIGTERM. Shutting down gracefully...');
                $this->shouldContinue = false;
            });

            pcntl_signal(SIGINT, function () {
                $this->info('Received SIGINT. Shutting down gracefully...');
                $this->shouldContinue = false;
            });
        }
    }

    /**
     * Check if the worker should continue running.
     */
    protected function shouldContinue(): bool
    {
        return $this->shouldContinue;
    }

    /**
     * Log worker startup with configuration.
     */
    protected function logStartup(): void
    {
        $config = [
            'source_directory' => $this->sourceDirectory,
            'backup_directory' => $this->backupDirectory,
            'files_to_backup' => $this->filesToBackup,
            'backup_time' => $this->backupTime,
            'check_interval' => $this->checkInterval,
            'run_once' => $this->runOnce,
        ];

        Log::info('MySQL file backup worker started', $config);

        $this->info('=== MySQL File Backup Worker Configuration ===');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Source Directory', $this->sourceDirectory],
                ['Backup Directory', $this->backupDirectory],
                ['Files to Backup', implode(', ', $this->filesToBackup)],
                ['Backup Time', $this->backupTime],
                ['Run Mode', $this->runOnce ? 'Once' : 'Continuous'],
            ]
        );
        $this->newLine();
        
        $this->info("Backup structure: {$this->backupDirectory}\\YEAR\\MONTH\\DATE\\files");
        $this->newLine();
    }

    /**
     * Log worker shutdown.
     */
    protected function logShutdown(): void
    {
        Log::info('MySQL file backup worker stopped');
        $this->info('Backup worker stopped gracefully.');
    }
}

