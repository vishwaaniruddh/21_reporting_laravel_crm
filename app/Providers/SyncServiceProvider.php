<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\GenericSyncService;
use App\Services\GenericSyncServiceV2;
use App\Services\SchemaDetectorService;
use App\Services\ColumnMapperService;
use App\Services\SyncLockService;
use App\Services\RetryService;
use App\Services\ErrorThresholdService;
use App\Services\UpdateLogMonitor;
use App\Services\AlertSyncService;
use App\Services\SyncLogger;

/**
 * SyncServiceProvider binds the sync services.
 * 
 * This provider allows switching between V1 (modifies MySQL) and V2 (tracking table) implementations.
 * 
 * Set SYNC_USE_TRACKING_TABLE=true in .env to use V2 (recommended).
 */
class SyncServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Bind GenericSyncService to V2 implementation when tracking table is enabled
        $this->app->bind(GenericSyncService::class, function ($app) {
            $useTrackingTable = config('pipeline.use_tracking_table', true);

            if ($useTrackingTable) {
                // Use V2 with tracking table (no MySQL modification)
                return new GenericSyncServiceV2(
                    $app->make(SchemaDetectorService::class),
                    $app->make(ColumnMapperService::class),
                    $app->make(SyncLockService::class),
                    $app->make(RetryService::class),
                    $app->make(ErrorThresholdService::class)
                );
            }

            // Use V1 (modifies MySQL with synced_at column)
            return new GenericSyncService(
                $app->make(SchemaDetectorService::class),
                $app->make(ColumnMapperService::class),
                $app->make(SyncLockService::class),
                $app->make(RetryService::class),
                $app->make(ErrorThresholdService::class)
            );
        });

        // Register UpdateLogMonitor as singleton
        $this->app->singleton(UpdateLogMonitor::class, function ($app) {
            return new UpdateLogMonitor(
                batchSize: config('update-sync.batch_size', 100)
            );
        });

        // Register SyncLogger as singleton
        $this->app->singleton(SyncLogger::class, function ($app) {
            return new SyncLogger();
        });

        // Register AlertSyncService with dependencies
        $this->app->bind(AlertSyncService::class, function ($app) {
            return new AlertSyncService(
                logger: $app->make(SyncLogger::class),
                maxRetries: config('update-sync.max_retries', 3)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
