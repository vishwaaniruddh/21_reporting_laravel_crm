<?php

namespace Tests\Unit;

use App\Models\PartitionSyncError;
use App\Services\PartitionErrorQueueService;
use App\Services\PartitionFailureAlertService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Test error handling and retry logic for partition sync operations
 * 
 * Requirements: 8.1, 8.2, 8.3
 */
class PartitionErrorHandlingTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Test that PartitionSyncError model can be created
     */
    public function test_partition_sync_error_creation()
    {
        $error = PartitionSyncError::createError(
            alertId: 123,
            partitionDate: Carbon::parse('2026-01-08'),
            partitionTable: 'alerts_2026_01_08',
            errorType: PartitionSyncError::ERROR_PARTITION_CREATION,
            errorMessage: 'Failed to create partition table',
            alertData: ['id' => 123, 'panelid' => 'TEST001']
        );

        $this->assertNotNull($error);
        $this->assertEquals(123, $error->alert_id);
        $this->assertEquals('alerts_2026_01_08', $error->partition_table);
        $this->assertEquals(PartitionSyncError::STATUS_PENDING, $error->status);
        $this->assertEquals(0, $error->retry_count);
    }


    /**
     * Test that errors can be marked for retry
     */
    public function test_error_can_be_marked_for_retry()
    {
        $error = PartitionSyncError::createError(
            alertId: 456,
            partitionDate: Carbon::parse('2026-01-09'),
            partitionTable: 'alerts_2026_01_09',
            errorType: PartitionSyncError::ERROR_INSERT_FAILED,
            errorMessage: 'Insert failed',
            alertData: ['id' => 456]
        );

        $this->assertTrue($error->canRetry());
        
        $error->markRetrying();
        
        $this->assertEquals(1, $error->retry_count);
        $this->assertEquals(PartitionSyncError::STATUS_RETRYING, $error->status);
        $this->assertNotNull($error->last_retry_at);
        $this->assertNotNull($error->next_retry_at);
    }

    /**
     * Test that errors can be marked as failed after max retries
     */
    public function test_error_marked_failed_after_max_retries()
    {
        $error = PartitionSyncError::createError(
            alertId: 789,
            partitionDate: Carbon::parse('2026-01-10'),
            partitionTable: 'alerts_2026_01_10',
            errorType: PartitionSyncError::ERROR_TRANSACTION_FAILED,
            errorMessage: 'Transaction failed',
            alertData: ['id' => 789]
        );

        // Simulate max retries
        $error->retry_count = 3;
        $error->save();

        $this->assertFalse($error->canRetry());
        
        $error->markFailed();
        
        $this->assertEquals(PartitionSyncError::STATUS_FAILED, $error->status);
        $this->assertNull($error->next_retry_at);
    }

    /**
     * Test error statistics
     */
    public function test_error_statistics()
    {
        // Create various errors
        PartitionSyncError::createError(
            alertId: 1,
            partitionDate: Carbon::parse('2026-01-08'),
            partitionTable: 'alerts_2026_01_08',
            errorType: PartitionSyncError::ERROR_PARTITION_CREATION,
            errorMessage: 'Error 1',
            alertData: ['id' => 1]
        );

        $error2 = PartitionSyncError::createError(
            alertId: 2,
            partitionDate: Carbon::parse('2026-01-09'),
            partitionTable: 'alerts_2026_01_09',
            errorType: PartitionSyncError::ERROR_INSERT_FAILED,
            errorMessage: 'Error 2',
            alertData: ['id' => 2]
        );
        $error2->markFailed();

        $stats = PartitionSyncError::getStatistics();

        $this->assertEquals(2, $stats['total']);
        $this->assertEquals(1, $stats['pending']);
        $this->assertEquals(1, $stats['failed']);
    }

    /**
     * Test failure alert service threshold checking
     */
    public function test_failure_alert_threshold()
    {
        $alertService = new PartitionFailureAlertService(
            failureThreshold: 2,
            timeWindowMinutes: 60,
            alertCooldownMinutes: 30
        );

        // Create one error - should not trigger alert
        PartitionSyncError::createError(
            alertId: 100,
            partitionDate: Carbon::parse('2026-01-08'),
            partitionTable: 'alerts_2026_01_08',
            errorType: PartitionSyncError::ERROR_PARTITION_CREATION,
            errorMessage: 'Error 1',
            alertData: ['id' => 100]
        );

        $stats = $alertService->getFailureStatistics();
        $this->assertFalse($stats['threshold_exceeded']);

        // Create second error - should trigger alert
        PartitionSyncError::createError(
            alertId: 101,
            partitionDate: Carbon::parse('2026-01-08'),
            partitionTable: 'alerts_2026_01_08',
            errorType: PartitionSyncError::ERROR_PARTITION_CREATION,
            errorMessage: 'Error 2',
            alertData: ['id' => 101]
        );

        $stats = $alertService->getFailureStatistics();
        $this->assertTrue($stats['threshold_exceeded']);
        $this->assertEquals(2, $stats['recent_failure_count']);
    }
}
