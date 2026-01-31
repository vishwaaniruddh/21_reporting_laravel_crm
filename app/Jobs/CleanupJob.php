<?php

namespace App\Jobs;

use App\Services\CleanupService;
use App\Services\CleanupResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use PDOException;

/**
 * CleanupJob handles the scheduled cleanup of verified synced records from MySQL.
 * 
 * ⚠️ EXTREME CAUTION: This job DELETES records from MySQL alerts table!
 * 
 * Safety requirements:
 * 1. Check verification status before delete
 * 2. Respect configurable retention period
 * 3. Stop on connection issues
 * 4. NEVER auto-run cleanup - require manual trigger
 * 5. Log every single delete operation
 * 
 * Requirements: 3.5, 4.1, 4.6
 */
class CleanupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1; // Only try once - cleanup is sensitive

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout;

    /**
     * Cache key for storing job status
     */
    protected const STATUS_CACHE_KEY = 'cleanup_job_status';

    /**
     * Flag indicating admin has confirmed this cleanup
     * ⚠️ REQUIRED for cleanup to proceed
     */
    protected bool $adminConfirmed;

    /**
     * Optional retention days override
     */
    protected ?int $retentionDays;

    /**
     * Optional specific batch IDs to clean up
     */
    protected ?array $specificBatchIds;

    /**
     * Create a new job instance.
     * 
     * ⚠️ Admin confirmation is REQUIRED - cleanup will not proceed without it
     * 
     * @param bool $adminConfirmed Whether admin has explicitly confirmed cleanup
     * @param int|null $retentionDays Override retention period (days)
     * @param array|null $specificBatchIds Specific batch IDs to clean (null = all eligible)
     */
    public function __construct(
        bool $adminConfirmed = false,
        ?int $retentionDays = null,
        ?array $specificBatchIds = null
    ) {
        $this->adminConfirmed = $adminConfirmed;
        $this->retentionDays = $retentionDays;
        $this->specificBatchIds = $specificBatchIds;
        $this->timeout = (int) config('pipeline.job_timeout', 3600);
    }


    /**
     * Execute the job.
     * 
     * ⚠️ This job DELETES records from MySQL alerts table!
     */
    public function handle(CleanupService $cleanupService): void
    {
        $startTime = microtime(true);

        // SAFETY CHECK 1: Admin confirmation required
        if (!$this->adminConfirmed) {
            Log::warning('CleanupJob blocked - admin confirmation not provided');
            
            $this->setStatus('blocked', [
                'reason' => 'Admin confirmation required',
                'message' => 'Cleanup job was dispatched without admin confirmation. This is a safety feature.',
            ]);
            
            return;
        }

        // SAFETY CHECK 2: Verify cleanup is enabled in config
        if (!config('pipeline.cleanup_enabled', false)) {
            Log::warning('CleanupJob blocked - cleanup is disabled in configuration');
            
            $this->setStatus('blocked', [
                'reason' => 'Cleanup disabled',
                'message' => 'Set PIPELINE_CLEANUP_ENABLED=true in environment to enable cleanup.',
            ]);
            
            return;
        }

        // SAFETY CHECK 3: Verify database connections before proceeding
        if (!$this->verifyDatabaseConnections()) {
            Log::error('CleanupJob blocked - database connection issues detected');
            
            $this->setStatus('failed', [
                'reason' => 'Database connection failure',
                'message' => 'Cleanup stopped due to database connection issues.',
            ]);
            
            return;
        }

        $this->setStatus('running');

        Log::info('CleanupJob started', [
            'admin_confirmed' => $this->adminConfirmed,
            'retention_days' => $this->retentionDays ?? config('pipeline.retention_days'),
            'specific_batches' => $this->specificBatchIds,
        ]);

        try {
            // Configure the cleanup service
            $cleanupService->setAdminConfirmation(true);
            
            if ($this->retentionDays !== null) {
                $cleanupService->setRetentionDays($this->retentionDays);
            }

            // Preview what will be cleaned
            $preview = $cleanupService->previewCleanup();
            
            Log::info('Cleanup preview', [
                'eligible_batches' => $preview['eligible_batches'],
                'eligible_records' => $preview['eligible_records'],
            ]);

            if ($preview['eligible_batches'] === 0) {
                Log::info('CleanupJob completed - no eligible batches for cleanup');
                
                $this->setStatus('completed', [
                    'records_deleted' => 0,
                    'batches_processed' => 0,
                    'message' => 'No eligible batches for cleanup',
                    'duration_seconds' => round(microtime(true) - $startTime, 2),
                ]);
                
                return;
            }

            // Perform cleanup
            $result = $this->performCleanup($cleanupService);

            $durationSeconds = round(microtime(true) - $startTime, 2);

            if ($result->isSuccess()) {
                Log::info('CleanupJob completed successfully', [
                    'records_deleted' => $result->recordsDeleted,
                    'batches_processed' => $result->batchesProcessed,
                    'duration_seconds' => $durationSeconds,
                ]);

                $this->setStatus('completed', [
                    'records_deleted' => $result->recordsDeleted,
                    'records_skipped' => $result->recordsSkipped,
                    'batches_processed' => $result->batchesProcessed,
                    'duration_seconds' => $durationSeconds,
                ]);
            } else {
                Log::warning('CleanupJob completed with errors', [
                    'records_deleted' => $result->recordsDeleted,
                    'records_skipped' => $result->recordsSkipped,
                    'errors' => $result->errors,
                    'duration_seconds' => $durationSeconds,
                ]);

                $this->setStatus('completed_with_errors', [
                    'records_deleted' => $result->recordsDeleted,
                    'records_skipped' => $result->recordsSkipped,
                    'batches_processed' => $result->batchesProcessed,
                    'errors' => $result->errors,
                    'duration_seconds' => $durationSeconds,
                ]);
            }

        } catch (PDOException $e) {
            // Connection issues - stop immediately
            Log::error('CleanupJob stopped due to database connection issue', [
                'error' => $e->getMessage(),
            ]);

            $this->setStatus('failed', [
                'reason' => 'Database connection failure',
                'error' => $e->getMessage(),
                'duration_seconds' => round(microtime(true) - $startTime, 2),
            ]);

            // Don't rethrow - we want to stop gracefully on connection issues
            return;

        } catch (Exception $e) {
            Log::error('CleanupJob failed', [
                'error' => $e->getMessage(),
            ]);

            $this->setStatus('failed', [
                'error' => $e->getMessage(),
                'duration_seconds' => round(microtime(true) - $startTime, 2),
            ]);

            throw $e;
        }
    }

    /**
     * Perform the actual cleanup operation
     */
    protected function performCleanup(CleanupService $cleanupService): CleanupResult
    {
        if ($this->specificBatchIds !== null && !empty($this->specificBatchIds)) {
            // Clean specific batches
            $totalDeleted = 0;
            $totalSkipped = 0;
            $batchesProcessed = 0;
            $errors = [];

            foreach ($this->specificBatchIds as $batchId) {
                // Check connection before each batch
                if (!$this->verifyDatabaseConnections()) {
                    $errors[] = "Database connection lost during cleanup";
                    break;
                }

                $result = $cleanupService->cleanupBatch($batchId);
                
                if ($result->isSuccess()) {
                    $totalDeleted += $result->recordsDeleted;
                    $batchesProcessed++;
                } else {
                    $totalSkipped += $result->recordsSkipped;
                    $errors = array_merge($errors, $result->errors);
                }
            }

            return new CleanupResult(
                recordsDeleted: $totalDeleted,
                recordsSkipped: $totalSkipped,
                batchesProcessed: $batchesProcessed,
                success: empty($errors),
                errors: $errors,
                errorMessage: empty($errors) ? null : implode('; ', array_slice($errors, 0, 5)),
                adminConfirmed: true
            );
        }

        // Clean all eligible batches
        return $cleanupService->cleanupAllEligible();
    }

    /**
     * Verify database connections are healthy
     * 
     * ⚠️ Cleanup stops immediately if connections are unhealthy
     */
    protected function verifyDatabaseConnections(): bool
    {
        try {
            // Check MySQL connection
            DB::connection('mysql')->getPdo();
            
            // Check PostgreSQL connection (needed for verification)
            DB::connection('pgsql')->getPdo();
            
            return true;
        } catch (Exception $e) {
            Log::error('Database connection check failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }


    /**
     * Set job status in cache
     */
    protected function setStatus(string $status, array $data = []): void
    {
        Cache::put(self::STATUS_CACHE_KEY, array_merge([
            'status' => $status,
            'updated_at' => now()->toIso8601String(),
        ], $data), now()->addHours(24));
    }

    /**
     * Get current job status
     */
    public static function getStatus(): array
    {
        return Cache::get(self::STATUS_CACHE_KEY, [
            'status' => 'idle',
            'updated_at' => null,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Exception $exception): void
    {
        Log::error('CleanupJob failed permanently', [
            'error' => $exception?->getMessage(),
        ]);

        $this->setStatus('failed', [
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return ['cleanup', 'pipeline', 'alerts'];
    }

    /**
     * Dispatch a cleanup job with admin confirmation
     * 
     * This is the recommended way to trigger cleanup - it ensures
     * admin confirmation is explicitly provided.
     * 
     * @param int|null $retentionDays Override retention period
     * @param array|null $specificBatchIds Specific batches to clean
     * @return self
     */
    public static function dispatchWithAdminConfirmation(
        ?int $retentionDays = null,
        ?array $specificBatchIds = null
    ): self {
        $job = new self(
            adminConfirmed: true,
            retentionDays: $retentionDays,
            specificBatchIds: $specificBatchIds
        );
        
        dispatch($job);
        
        return $job;
    }

    /**
     * Preview what would be cleaned without actually cleaning
     * 
     * @return array Preview information
     */
    public static function preview(): array
    {
        $cleanupService = new CleanupService();
        return $cleanupService->previewCleanup();
    }
}
