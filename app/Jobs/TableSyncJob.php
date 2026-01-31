<?php

namespace App\Jobs;

use App\Services\GenericSyncService;
use App\Services\GenericSyncResult;
use App\Models\TableSyncConfiguration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * TableSyncJob handles the scheduled synchronization of any configured table from MySQL to PostgreSQL.
 * 
 * This job:
 * - Accepts a configuration ID parameter
 * - Calls GenericSyncService::syncTable()
 * - Handles errors and logging
 * 
 * ⚠️ NO DELETION FROM MYSQL: Job reads from MySQL, writes to PostgreSQL
 * 
 * Requirements: 5.2
 */
class TableSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * Cache key prefix for storing job status
     */
    protected const STATUS_CACHE_KEY_PREFIX = 'table_sync_job_status_';

    /**
     * The configuration ID to sync
     */
    protected int $configurationId;

    /**
     * Create a new job instance.
     * 
     * @param int $configurationId The ID of the TableSyncConfiguration to sync
     */
    public function __construct(int $configurationId)
    {
        $this->configurationId = $configurationId;
        $this->timeout = (int) config('pipeline.job_timeout', 3600);
    }

    /**
     * Execute the job.
     */
    public function handle(GenericSyncService $syncService): void
    {
        $this->setStatus('running');
        $startTime = microtime(true);

        // Get configuration for logging
        $config = TableSyncConfiguration::find($this->configurationId);
        $sourceTable = $config?->source_table ?? 'unknown';

        Log::info('TableSyncJob started', [
            'configuration_id' => $this->configurationId,
            'source_table' => $sourceTable,
        ]);

        try {
            // Call GenericSyncService to perform the sync
            $result = $syncService->syncTable($this->configurationId);

            $duration = round(microtime(true) - $startTime, 2);

            if ($result->success) {
                Log::info('TableSyncJob completed successfully', [
                    'configuration_id' => $this->configurationId,
                    'source_table' => $sourceTable,
                    'records_synced' => $result->recordsSynced,
                    'records_failed' => $result->recordsFailed,
                    'duration_seconds' => $duration,
                ]);

                $this->setStatus('completed', [
                    'records_synced' => $result->recordsSynced,
                    'records_failed' => $result->recordsFailed,
                    'duration_seconds' => $duration,
                ]);
            } else {
                Log::warning('TableSyncJob completed with errors', [
                    'configuration_id' => $this->configurationId,
                    'source_table' => $sourceTable,
                    'records_synced' => $result->recordsSynced,
                    'records_failed' => $result->recordsFailed,
                    'error' => $result->errorMessage,
                    'duration_seconds' => $duration,
                ]);

                $this->setStatus('failed', [
                    'records_synced' => $result->recordsSynced,
                    'records_failed' => $result->recordsFailed,
                    'error' => $result->errorMessage,
                    'duration_seconds' => $duration,
                ]);

                // If the sync failed completely, throw exception to trigger retry
                if ($result->recordsSynced === 0 && $result->recordsFailed > 0) {
                    throw new Exception($result->errorMessage ?? 'Sync failed with no records synced');
                }
            }

        } catch (Exception $e) {
            $duration = round(microtime(true) - $startTime, 2);

            Log::error('TableSyncJob failed', [
                'configuration_id' => $this->configurationId,
                'source_table' => $sourceTable,
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
            ]);

            $this->setStatus('failed', [
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
            ]);

            throw $e;
        }
    }

    /**
     * Set job status in cache.
     * 
     * @param string $status
     * @param array $data
     */
    protected function setStatus(string $status, array $data = []): void
    {
        Cache::put(
            self::STATUS_CACHE_KEY_PREFIX . $this->configurationId,
            array_merge([
                'status' => $status,
                'configuration_id' => $this->configurationId,
                'updated_at' => now()->toIso8601String(),
            ], $data),
            now()->addHours(24)
        );
    }

    /**
     * Get current job status for a configuration.
     * 
     * @param int $configurationId
     * @return array
     */
    public static function getStatus(int $configurationId): array
    {
        return Cache::get(self::STATUS_CACHE_KEY_PREFIX . $configurationId, [
            'status' => 'idle',
            'configuration_id' => $configurationId,
            'updated_at' => null,
        ]);
    }

    /**
     * Handle a job failure.
     * 
     * @param Exception|null $exception
     */
    public function failed(?Exception $exception): void
    {
        $config = TableSyncConfiguration::find($this->configurationId);
        $sourceTable = $config?->source_table ?? 'unknown';

        Log::error('TableSyncJob failed permanently', [
            'configuration_id' => $this->configurationId,
            'source_table' => $sourceTable,
            'error' => $exception?->getMessage(),
        ]);

        $this->setStatus('failed', [
            'error' => $exception?->getMessage(),
            'permanent_failure' => true,
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     * 
     * @return array
     */
    public function tags(): array
    {
        return ['table-sync', 'configuration:' . $this->configurationId];
    }

    /**
     * Get the configuration ID for this job.
     * 
     * @return int
     */
    public function getConfigurationId(): int
    {
        return $this->configurationId;
    }
}
