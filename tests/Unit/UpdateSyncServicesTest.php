<?php

namespace Tests\Unit;

use App\Services\UpdateLogMonitor;
use App\Services\AlertSyncService;
use App\Services\SyncLogger;
use App\Services\SyncResult;
use App\Models\AlertUpdateLog;
use App\Console\Commands\UpdateSyncWorker;
use Tests\TestCase;

/**
 * Basic smoke tests for Update Sync services
 * 
 * These tests verify that the services can be instantiated and basic
 * functionality works without errors.
 */
class UpdateSyncServicesTest extends TestCase
{
    public function test_update_log_monitor_can_be_instantiated(): void
    {
        $monitor = new UpdateLogMonitor(100);
        $this->assertInstanceOf(UpdateLogMonitor::class, $monitor);
    }

    public function test_sync_logger_can_be_instantiated(): void
    {
        $logger = new SyncLogger();
        $this->assertInstanceOf(SyncLogger::class, $logger);
    }

    public function test_alert_sync_service_can_be_instantiated(): void
    {
        $logger = new SyncLogger();
        $service = new AlertSyncService($logger, 3);
        $this->assertInstanceOf(AlertSyncService::class, $service);
    }

    public function test_sync_result_success(): void
    {
        $result = new SyncResult(
            success: true,
            alertId: 123,
            errorMessage: null,
            duration: 0.5
        );

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailed());
        $this->assertEquals(123, $result->alertId);
        $this->assertNull($result->errorMessage);
        $this->assertEquals(0.5, $result->duration);
    }

    public function test_sync_result_failure(): void
    {
        $result = new SyncResult(
            success: false,
            alertId: 456,
            errorMessage: 'Test error',
            duration: 0.3
        );

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isFailed());
        $this->assertEquals(456, $result->alertId);
        $this->assertEquals('Test error', $result->errorMessage);
        $this->assertEquals(0.3, $result->duration);
    }

    public function test_alert_update_log_model_exists(): void
    {
        $this->assertTrue(class_exists(AlertUpdateLog::class));
    }

    public function test_update_sync_worker_command_exists(): void
    {
        $this->assertTrue(class_exists(UpdateSyncWorker::class));
    }

    public function test_update_sync_worker_has_correct_signature(): void
    {
        $command = new UpdateSyncWorker();
        
        // Verify command signature contains expected options
        $signature = $command->getName();
        $this->assertEquals('sync:update-worker', $signature);
    }

    public function test_update_log_monitor_batch_size_can_be_set(): void
    {
        $monitor = new UpdateLogMonitor(50);
        $this->assertEquals(50, $monitor->getBatchSize());
        
        $monitor->setBatchSize(200);
        $this->assertEquals(200, $monitor->getBatchSize());
    }

    public function test_alert_sync_service_max_retries_can_be_set(): void
    {
        $logger = new SyncLogger();
        $service = new AlertSyncService($logger, 5);
        $this->assertEquals(5, $service->getMaxRetries());
        
        $service->setMaxRetries(10);
        $this->assertEquals(10, $service->getMaxRetries());
    }
}
