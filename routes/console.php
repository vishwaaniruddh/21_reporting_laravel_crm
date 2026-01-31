<?php

use App\Jobs\SyncJob;
use App\Jobs\CleanupJob;
use App\Jobs\VerifyBatchJob;
use App\Jobs\TableSyncJob;
use App\Models\TableSyncConfiguration;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Pipeline Job Scheduling
|--------------------------------------------------------------------------
|
| Configure scheduled jobs for the alerts data pipeline:
| - SyncJob: Runs every 15 minutes to sync alerts from MySQL to PostgreSQL
| - VerifyBatchJob: Runs after sync to verify data integrity
| - CleanupJob: Runs daily at 2 AM (DISABLED by default for safety)
|
| Requirements: 1.6, 3.1, 4.5
|
*/

/**
 * Schedule SyncJob to run every 15 minutes
 * 
 * The sync job transfers alerts from MySQL to PostgreSQL in batches.
 * It respects off-peak hours preference when configured.
 * 
 * Requirements: 1.6
 */
Schedule::call(function () {
    // Check if sync is enabled
    if (!config('pipeline.sync_enabled', true)) {
        Log::info('Scheduled SyncJob skipped - sync is disabled');
        return;
    }

    // Check off-peak hours preference
    if (config('pipeline.off_peak.prefer_off_peak', true)) {
        $currentHour = (int) now()->format('H');
        $startHour = config('pipeline.off_peak.start_hour', 22);
        $endHour = config('pipeline.off_peak.end_hour', 6);
        
        // Determine if we're in off-peak hours
        $isOffPeak = ($startHour > $endHour)
            ? ($currentHour >= $startHour || $currentHour < $endHour)  // Spans midnight
            : ($currentHour >= $startHour && $currentHour < $endHour); // Same day
        
        // During peak hours, run less frequently (every 30 minutes instead of 15)
        if (!$isOffPeak) {
            // Only run on the hour and half-hour during peak hours
            $currentMinute = (int) now()->format('i');
            if ($currentMinute !== 0 && $currentMinute !== 30) {
                Log::debug('Scheduled SyncJob skipped - peak hours, running less frequently');
                return;
            }
        }
    }

    Log::info('Dispatching scheduled SyncJob');
    SyncJob::dispatch();
})->everyFifteenMinutes()
  ->name('pipeline:sync')
  ->withoutOverlapping(60) // Prevent overlapping for 60 minutes
  ->onOneServer(); // Only run on one server in multi-server setup

/**
 * Schedule VerifyBatchJob to run every 30 minutes (after sync completion)
 * 
 * The verification job checks that synced batches have matching record counts
 * between MySQL and PostgreSQL. It runs after sync to ensure data integrity.
 * 
 * Requirements: 3.1
 */
Schedule::call(function () {
    // Check if verification is enabled
    if (!config('pipeline.verify_enabled', true)) {
        Log::info('Scheduled VerifyBatchJob skipped - verification is disabled');
        return;
    }

    Log::info('Dispatching scheduled VerifyBatchJob');
    VerifyBatchJob::dispatch();
})->everyThirtyMinutes()
  ->name('pipeline:verify')
  ->withoutOverlapping(30) // Prevent overlapping for 30 minutes
  ->onOneServer();

/**
 * Schedule CleanupJob to run daily at 2 AM
 * 
 * ⚠️ EXTREME CAUTION: This job DELETES records from MySQL alerts table!
 * 
 * Safety features:
 * - DISABLED by default (PIPELINE_CLEANUP_ENABLED must be true)
 * - Requires admin confirmation (set via scheduled dispatch)
 * - Only runs during off-peak hours (2 AM)
 * - Verification check before cleanup
 * - Respects retention period configuration
 * 
 * Requirements: 4.5
 */
Schedule::call(function () {
    // SAFETY CHECK 1: Cleanup must be explicitly enabled
    if (!config('pipeline.cleanup_enabled', false)) {
        Log::info('Scheduled CleanupJob skipped - cleanup is DISABLED (set PIPELINE_CLEANUP_ENABLED=true to enable)');
        return;
    }

    // SAFETY CHECK 2: Log warning about scheduled cleanup
    Log::warning('⚠️ Scheduled CleanupJob is about to run - this will DELETE records from MySQL alerts table', [
        'retention_days' => config('pipeline.retention_days', 7),
        'cleanup_batch_size' => config('pipeline.cleanup_batch_size', 1000),
    ]);

    // Dispatch with admin confirmation flag
    // Note: Scheduled cleanup is considered admin-approved since it requires
    // explicit PIPELINE_CLEANUP_ENABLED=true in environment
    CleanupJob::dispatch(
        adminConfirmed: true,  // Scheduled cleanup is pre-approved via config
        retentionDays: null,   // Use configured retention period
        specificBatchIds: null // Clean all eligible batches
    );

    Log::info('Dispatched scheduled CleanupJob');
})->dailyAt('02:00')
  ->name('pipeline:cleanup')
  ->withoutOverlapping(120) // Prevent overlapping for 2 hours
  ->onOneServer()
  ->when(function () {
      // Additional safety: only run if cleanup is enabled
      return config('pipeline.cleanup_enabled', false);
  });

/*
|--------------------------------------------------------------------------
| Date-Partitioned Sync Scheduling
|--------------------------------------------------------------------------
|
| Configure scheduled jobs for the date-partitioned alerts sync:
| - Partitioned Sync: Runs every 20 minutes to sync alerts to date-partitioned tables
| - Respects off-peak hours configuration
| - Can be enabled/disabled independently from regular sync
|
| Requirements: 5.1
|
*/

/**
 * Schedule Date-Partitioned Sync to run every minute (continuous mode)
 * 
 * The partitioned sync job transfers alerts from MySQL to date-partitioned
 * PostgreSQL tables (e.g., alerts_2026_01_08). Runs continuously every minute
 * until all records are synced.
 * 
 * Requirements: 5.1
 */
Schedule::call(function () {
    // Check if partitioned sync is enabled
    if (!config('pipeline.partitioned_sync_enabled', false)) {
        Log::debug('Scheduled partitioned sync skipped - partitioned sync is disabled');
        return;
    }

    Log::info('Running scheduled date-partitioned sync (every minute mode)');
    
    try {
        // Run the sync command programmatically
        Artisan::call('sync:partitioned', [
            '--max-batches' => config('pipeline.partitioned_sync_max_batches', 10),
        ]);
        
        $output = Artisan::output();
        Log::info('Scheduled partitioned sync completed', [
            'output' => trim($output),
        ]);
    } catch (\Exception $e) {
        Log::error('Scheduled partitioned sync failed', [
            'error' => $e->getMessage(),
        ]);
    }
})->everyMinute() // Run every minute for continuous sync
  ->name('pipeline:partitioned-sync')
  ->withoutOverlapping(5) // Prevent overlapping for 5 minutes
  ->onOneServer() // Only run on one server in multi-server setup
  ->when(function () {
      // Only run if partitioned sync is enabled
      return config('pipeline.partitioned_sync_enabled', false);
  });

/*
|--------------------------------------------------------------------------
| Pipeline Status Commands
|--------------------------------------------------------------------------
|
| Artisan commands for checking pipeline status and manual operations.
|
*/

Artisan::command('pipeline:status', function () {
    $syncStatus = SyncJob::getStatus();
    $verifyStatus = VerifyBatchJob::getStatus();
    $cleanupStatus = CleanupJob::getStatus();

    $this->info('=== Pipeline Status ===');
    $this->newLine();

    $this->info('Sync Job:');
    $this->table(
        ['Key', 'Value'],
        collect($syncStatus)->map(fn($v, $k) => [$k, is_array($v) ? json_encode($v) : $v])->toArray()
    );

    $this->newLine();
    $this->info('Verify Job:');
    $this->table(
        ['Key', 'Value'],
        collect($verifyStatus)->map(fn($v, $k) => [$k, is_array($v) ? json_encode($v) : $v])->toArray()
    );

    $this->newLine();
    $this->info('Cleanup Job:');
    $this->table(
        ['Key', 'Value'],
        collect($cleanupStatus)->map(fn($v, $k) => [$k, is_array($v) ? json_encode($v) : $v])->toArray()
    );

    $this->newLine();
    $this->info('Configuration:');
    $this->table(
        ['Setting', 'Value'],
        [
            ['Sync Enabled', config('pipeline.sync_enabled') ? 'Yes' : 'No'],
            ['Sync Schedule', config('pipeline.sync_schedule')],
            ['Partitioned Sync Enabled', config('pipeline.partitioned_sync_enabled') ? 'Yes' : 'No'],
            ['Partitioned Sync Schedule', 'Every minute (continuous)'],
            ['Partitioned Sync Max Batches', config('pipeline.partitioned_sync_max_batches', 5)],
            ['Verify Enabled', config('pipeline.verify_enabled') ? 'Yes' : 'No'],
            ['Verify Schedule', config('pipeline.verify_schedule')],
            ['Cleanup Enabled', config('pipeline.cleanup_enabled') ? '⚠️ YES' : 'No (safe)'],
            ['Cleanup Schedule', config('pipeline.cleanup_schedule')],
            ['Retention Days', config('pipeline.retention_days')],
            ['Batch Size', config('pipeline.batch_size')],
        ]
    );
})->purpose('Display the current status of all pipeline jobs');

Artisan::command('pipeline:schedule-list', function () {
    $this->info('=== Scheduled Pipeline Jobs ===');
    $this->newLine();

    $this->table(
        ['Job', 'Schedule', 'Enabled', 'Notes'],
        [
            [
                'SyncJob',
                'Every 15 minutes',
                config('pipeline.sync_enabled') ? 'Yes' : 'No',
                'Respects off-peak hours preference',
            ],
            [
                'PartitionedSyncJob',
                'Every minute (continuous)',
                config('pipeline.partitioned_sync_enabled') ? 'Yes' : 'No',
                'Date-partitioned sync, runs continuously',
            ],
            [
                'VerifyBatchJob',
                'Every 30 minutes',
                config('pipeline.verify_enabled') ? 'Yes' : 'No',
                'Runs after sync completion',
            ],
            [
                'CleanupJob',
                'Daily at 2:00 AM',
                config('pipeline.cleanup_enabled') ? '⚠️ YES' : 'No (DISABLED)',
                '⚠️ DELETES MySQL records!',
            ],
        ]
    );

    if (!config('pipeline.cleanup_enabled')) {
        $this->newLine();
        $this->warn('CleanupJob is DISABLED for safety. Set PIPELINE_CLEANUP_ENABLED=true to enable.');
    }
})->purpose('List all scheduled pipeline jobs and their configuration');


/*
|--------------------------------------------------------------------------
| Excel Report Generation Scheduling
|--------------------------------------------------------------------------
|
| Schedule daily Excel report generation for past dates.
| Reports are generated at midnight for the previous day.
| Cached to avoid regeneration.
|
*/

/**
 * Schedule Excel report generation to run daily at midnight
 * 
 * Generates Excel reports for yesterday and checks for any missing reports
 * from the past 7 days.
 */
Schedule::call(function () {
    Log::info('Running scheduled Excel report generation');
    
    try {
        // Run the artisan command to generate missing reports
        Artisan::call('reports:generate-excel', [
            '--days' => 7, // Check last 7 days for missing reports
        ]);
        
        $output = Artisan::output();
        Log::info('Scheduled Excel report generation completed', [
            'output' => trim($output),
        ]);
    } catch (\Exception $e) {
        Log::error('Scheduled Excel report generation failed', [
            'error' => $e->getMessage(),
        ]);
    }
})->dailyAt('00:05') // Run at 12:05 AM (5 minutes after midnight)
  ->name('reports:excel-daily')
  ->withoutOverlapping(60) // Prevent overlapping for 60 minutes
  ->onOneServer(); // Only run on one server in multi-server setup

/*
|--------------------------------------------------------------------------
| CSV Report Generation Scheduling
|--------------------------------------------------------------------------
|
| Schedule daily CSV report generation for past dates.
| Reports are generated at midnight for the previous day.
| Pre-generated reports allow instant downloads.
|
*/

/**
 * Schedule CSV report generation to run daily at midnight
 * 
 * Generates CSV reports for yesterday. CSV reports are memory-efficient
 * and can handle large datasets (360k+ records).
 */
Schedule::call(function () {
    Log::info('Running scheduled CSV report generation');
    
    try {
        // Run the artisan command to generate yesterday's report
        Artisan::call('reports:generate-csv', [
            '--days-back' => 1, // Generate for yesterday
        ]);
        
        $output = Artisan::output();
        Log::info('Scheduled CSV report generation completed', [
            'output' => trim($output),
        ]);
    } catch (\Exception $e) {
        Log::error('Scheduled CSV report generation failed', [
            'error' => $e->getMessage(),
        ]);
    }
})->dailyAt('00:10') // Run at 12:10 AM (10 minutes after midnight)
  ->name('reports:csv-daily')
  ->withoutOverlapping(120) // Prevent overlapping for 2 hours (large datasets)
  ->onOneServer(); // Only run on one server in multi-server setup

/*
|--------------------------------------------------------------------------
| Configurable Table Sync Scheduling
|--------------------------------------------------------------------------
|
| Schedule table sync jobs based on configurations stored in the database.
| Each enabled configuration with a schedule will be registered as a scheduled job.
|
| ⚠️ NO DELETION FROM MYSQL: Scheduled jobs only sync, never delete
|
| Requirements: 5.2, 5.3
|
*/

/**
 * Schedule TableSyncJobs based on database configurations.
 * 
 * This reads all enabled configurations with schedules and registers
 * each as a scheduled job using the configured cron expression.
 * 
 * Requirements: 5.2, 5.3
 */
Schedule::call(function () {
    try {
        // Get all enabled configurations with schedules
        $configs = TableSyncConfiguration::enabled()
            ->scheduled()
            ->get();

        if ($configs->isEmpty()) {
            Log::debug('No table sync configurations with schedules found');
            return;
        }

        foreach ($configs as $config) {
            // Check if this config's schedule matches current time using cron expression
            try {
                $cron = new \Cron\CronExpression($config->schedule);
                $shouldRun = $cron->isDue();
            } catch (\Exception $cronException) {
                Log::warning('Invalid cron expression for table sync', [
                    'configuration_id' => $config->id,
                    'expression' => $config->schedule,
                    'error' => $cronException->getMessage(),
                ]);
                continue;
            }

            if ($shouldRun) {
                Log::info('Dispatching scheduled TableSyncJob', [
                    'configuration_id' => $config->id,
                    'source_table' => $config->source_table,
                    'schedule' => $config->schedule,
                ]);

                TableSyncJob::dispatch($config->id);
            }
        }
    } catch (\Exception $e) {
        Log::error('Failed to process table sync schedules', [
            'error' => $e->getMessage(),
        ]);
    }
})->everyMinute()
  ->name('table-sync:scheduler')
  ->withoutOverlapping(5)
  ->onOneServer();

/*
|--------------------------------------------------------------------------
| Table Sync Status Commands
|--------------------------------------------------------------------------
|
| Artisan commands for checking table sync status.
|
*/

Artisan::command('table-sync:status', function () {
    $this->info('=== Table Sync Status ===');
    $this->newLine();

    try {
        $configs = TableSyncConfiguration::all();

        if ($configs->isEmpty()) {
            $this->warn('No table sync configurations found.');
            return;
        }

        $tableData = [];
        foreach ($configs as $config) {
            $status = TableSyncJob::getStatus($config->id);
            $tableData[] = [
                $config->id,
                $config->name,
                $config->source_table,
                $config->is_enabled ? 'Yes' : 'No',
                $config->schedule ?? 'Manual',
                $config->last_sync_status ?? 'Never',
                $config->last_sync_at?->format('Y-m-d H:i:s') ?? 'Never',
                $status['status'] ?? 'idle',
            ];
        }

        $this->table(
            ['ID', 'Name', 'Source Table', 'Enabled', 'Schedule', 'Last Status', 'Last Sync', 'Job Status'],
            $tableData
        );
    } catch (\Exception $e) {
        $this->error('Failed to get table sync status: ' . $e->getMessage());
    }
})->purpose('Display the status of all table sync configurations');

Artisan::command('table-sync:schedule-list', function () {
    $this->info('=== Scheduled Table Syncs ===');
    $this->newLine();

    try {
        $configs = TableSyncConfiguration::enabled()
            ->scheduled()
            ->get();

        if ($configs->isEmpty()) {
            $this->warn('No scheduled table syncs found.');
            return;
        }

        $tableData = [];
        foreach ($configs as $config) {
            $tableData[] = [
                $config->id,
                $config->name,
                $config->source_table,
                $config->schedule,
                $config->batch_size,
            ];
        }

        $this->table(
            ['ID', 'Name', 'Source Table', 'Schedule (Cron)', 'Batch Size'],
            $tableData
        );
    } catch (\Exception $e) {
        $this->error('Failed to list scheduled syncs: ' . $e->getMessage());
    }
})->purpose('List all scheduled table sync configurations');
