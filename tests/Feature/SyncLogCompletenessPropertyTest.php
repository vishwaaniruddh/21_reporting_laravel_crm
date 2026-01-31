<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\SyncBatch;
use App\Models\SyncedAlert;
use App\Models\SyncLog;
use App\Services\SyncService;
use App\Services\SyncLogService;
use App\Services\VerificationService;
use App\Services\CleanupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Property-Based Test: Sync Log Completeness
 * 
 * Feature: alerts-data-pipeline, Property 4: Sync Log Completeness
 * Validates: Requirements 2.1, 2.4, 4.4
 * 
 * Property: For any sync or cleanup operation (successful or failed), the sync log
 * SHALL contain: start_time (created_at), end_time (implicit via duration), 
 * records_affected, status, and error_message (if failed).
 * 
 * IMPORTANT: This test uses EXISTING MySQL alerts data.
 * It does NOT create or delete any MySQL alerts.
 */
class SyncLogCompletenessPropertyTest extends TestCase
{
    protected SyncService $syncService;
    protected SyncLogService $syncLogService;
    protected VerificationService $verificationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTablesExist();
        $this->clearSyncData();
        $this->syncService = new SyncService();
        $this->syncLogService = new SyncLogService();
        $this->verificationService = new VerificationService();
    }

    protected function tearDown(): void
    {
        $this->resetSyncMarkers();
        parent::tearDown();
    }

    protected function ensureTablesExist(): void
    {
        if (!Schema::connection('pgsql')->hasTable('alerts')) {
            $this->markTestSkipped('PostgreSQL alerts table does not exist.');
        }
        if (!Schema::connection('pgsql')->hasTable('sync_logs')) {
            $this->markTestSkipped('PostgreSQL sync_logs table does not exist.');
        }
        if (!Schema::connection('mysql')->hasTable('sync_batches')) {
            $this->markTestSkipped('MySQL sync_batches table does not exist.');
        }
    }

    protected function clearSyncData(): void
    {
        try {
            // Use delete instead of truncate to work within transactions
            DB::connection('pgsql')->table('sync_logs')->delete();
            DB::connection('pgsql')->table('alerts')->delete();
            DB::connection('mysql')->table('sync_batches')->delete();
            // Reset sync markers only for a limited number of records (faster for testing)
            // This resets the first 1000 synced records to allow re-testing
            DB::connection('mysql')->table('alerts')
                ->whereNotNull('synced_at')
                ->orderBy('id', 'asc')
                ->limit(1000)
                ->update(['synced_at' => null, 'sync_batch_id' => null]);
        } catch (\Exception $e) {
            // Tables may not exist yet
        }
    }

    protected function resetSyncMarkers(): void
    {
        try {
            DB::connection('mysql')->table('alerts')
                ->whereNotNull('synced_at')
                ->update(['synced_at' => null, 'sync_batch_id' => null]);
        } catch (\Exception $e) {
            // Ignore errors
        }
    }

    /**
     * Data provider for property-based testing
     * Generates 5 iterations with varying batch sizes
     * 
     * @return array
     */
    public static function batchSizeProvider(): array
    {
        return [
            'iteration_1_batch_5' => [5, 1],
            'iteration_2_batch_10' => [10, 2],
            'iteration_3_batch_25' => [25, 3],
            'iteration_4_batch_50' => [50, 4],
            'iteration_5_batch_100' => [100, 5],
        ];
    }

    /**
     * Property Test: Sync Log Completeness for Successful Sync Operations
     * 
     * Feature: alerts-data-pipeline, Property 4: Sync Log Completeness
     * Validates: Requirements 2.1, 2.4
     * 
     * For any successful sync operation, the sync log SHALL contain:
     * - created_at (start time)
     * - duration_ms (allows calculating end time)
     * - records_affected
     * - status = 'success'
     * - error_message = null
     * 
     * @dataProvider batchSizeProvider
     */
    public function test_sync_log_complete_for_successful_sync(int $batchSize, int $iteration): void
    {
        // Reset sync data for each iteration
        $this->clearSyncData();

        $this->syncService->setBatchSize($batchSize);

        // Skip if no data to sync
        $unsyncedCount = $this->syncService->getUnsyncedCount();
        if ($unsyncedCount === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }

        // Fetch and sync a batch
        $mysqlAlerts = $this->syncService->fetchUnsyncedBatch();
        
        if ($mysqlAlerts->isEmpty()) {
            $this->markTestSkipped('No unsynced records available for iteration ' . $iteration);
        }

        $expectedRecordCount = $mysqlAlerts->count();
        $result = $this->syncService->processBatch($mysqlAlerts);

        $this->assertTrue(
            $result->isSuccess(),
            "Sync failed on iteration {$iteration}: " . ($result->errorMessage ?? 'Unknown error')
        );

        $batchId = $result->batchId;
        $this->assertNotNull($batchId, "Batch ID should not be null after successful sync");

        // Get the sync log entry for this batch
        $syncLog = SyncLog::where('batch_id', $batchId)
            ->where('operation', SyncLog::OPERATION_SYNC)
            ->first();

        // Property assertion: Sync log entry must exist
        $this->assertNotNull(
            $syncLog,
            "Sync log entry must exist for batch {$batchId} on iteration {$iteration}"
        );

        // Property assertion: created_at (start time) must be present
        $this->assertNotNull(
            $syncLog->created_at,
            "Sync log must have created_at (start time) for batch {$batchId} on iteration {$iteration}"
        );

        // Property assertion: duration_ms must be present and non-negative
        $this->assertNotNull(
            $syncLog->duration_ms,
            "Sync log must have duration_ms for batch {$batchId} on iteration {$iteration}"
        );
        $this->assertGreaterThanOrEqual(
            0,
            $syncLog->duration_ms,
            "Duration must be non-negative for batch {$batchId} on iteration {$iteration}"
        );

        // Property assertion: records_affected must match actual synced count
        $this->assertEquals(
            $expectedRecordCount,
            $syncLog->records_affected,
            "Sync log records_affected ({$syncLog->records_affected}) must match actual count ({$expectedRecordCount}) " .
            "for batch {$batchId} on iteration {$iteration}"
        );

        // Property assertion: status must be 'success'
        $this->assertEquals(
            SyncLog::STATUS_SUCCESS,
            $syncLog->status,
            "Sync log status must be 'success' for successful sync on iteration {$iteration}"
        );

        // Property assertion: error_message must be null for successful operation
        $this->assertNull(
            $syncLog->error_message,
            "Sync log error_message must be null for successful sync on iteration {$iteration}"
        );

        // Property assertion: operation must be 'sync'
        $this->assertEquals(
            SyncLog::OPERATION_SYNC,
            $syncLog->operation,
            "Sync log operation must be 'sync' on iteration {$iteration}"
        );

        // Property assertion: batch_id must match
        $this->assertEquals(
            $batchId,
            $syncLog->batch_id,
            "Sync log batch_id must match the actual batch ID on iteration {$iteration}"
        );
    }

    /**
     * Property Test: Sync Log Completeness for Verify Operations
     * 
     * Feature: alerts-data-pipeline, Property 4: Sync Log Completeness
     * Validates: Requirements 2.1, 2.4
     * 
     * For any verify operation, the sync log SHALL contain all required fields.
     */
    public function test_sync_log_complete_for_verify_operations(): void
    {
        $this->syncService->setBatchSize(10);

        // Skip if no data to sync
        if ($this->syncService->getUnsyncedCount() === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }

        // Sync a batch first
        $mysqlAlerts = $this->syncService->fetchUnsyncedBatch();
        $result = $this->syncService->processBatch($mysqlAlerts);

        $this->assertTrue($result->isSuccess());
        $batchId = $result->batchId;

        // Clear sync logs to isolate verify operation log
        SyncLog::where('operation', SyncLog::OPERATION_VERIFY)->delete();

        // Run verification
        $verificationResult = $this->verificationService->verifyBatch($batchId);

        // Get the verify log entry
        $verifyLog = SyncLog::where('batch_id', $batchId)
            ->where('operation', SyncLog::OPERATION_VERIFY)
            ->first();

        // Property assertion: Verify log entry must exist
        $this->assertNotNull(
            $verifyLog,
            "Verify log entry must exist for batch {$batchId}"
        );

        // Property assertion: created_at must be present
        $this->assertNotNull(
            $verifyLog->created_at,
            "Verify log must have created_at"
        );

        // Property assertion: duration_ms must be present
        $this->assertNotNull(
            $verifyLog->duration_ms,
            "Verify log must have duration_ms"
        );

        // Property assertion: records_affected must be present
        $this->assertNotNull(
            $verifyLog->records_affected,
            "Verify log must have records_affected"
        );

        // Property assertion: status must be present and valid
        $this->assertContains(
            $verifyLog->status,
            [SyncLog::STATUS_SUCCESS, SyncLog::STATUS_FAILED],
            "Verify log status must be valid"
        );

        // Property assertion: operation must be 'verify'
        $this->assertEquals(
            SyncLog::OPERATION_VERIFY,
            $verifyLog->operation,
            "Log operation must be 'verify'"
        );

        // Property assertion: For successful verification, error_message should be null
        if ($verificationResult->isVerified()) {
            $this->assertNull(
                $verifyLog->error_message,
                "Verify log error_message should be null for successful verification"
            );
            $this->assertEquals(
                SyncLog::STATUS_SUCCESS,
                $verifyLog->status,
                "Verify log status should be 'success' for successful verification"
            );
        }
    }

    /**
     * Property Test: Sync Log Completeness for Failed Verify Operations
     * 
     * Feature: alerts-data-pipeline, Property 4: Sync Log Completeness
     * Validates: Requirements 2.1, 2.4
     * 
     * For any failed verify operation, the sync log SHALL contain error_message.
     */
    public function test_sync_log_complete_for_failed_verify(): void
    {
        $this->syncService->setBatchSize(10);

        // Skip if no data to sync
        if ($this->syncService->getUnsyncedCount() === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }

        // Sync a batch first
        $mysqlAlerts = $this->syncService->fetchUnsyncedBatch();
        $result = $this->syncService->processBatch($mysqlAlerts);

        $this->assertTrue($result->isSuccess());
        $batchId = $result->batchId;

        // Delete some records from PostgreSQL to cause verification failure
        $pgsqlIds = SyncedAlert::where('sync_batch_id', $batchId)->pluck('id')->toArray();
        if (count($pgsqlIds) < 2) {
            $this->markTestSkipped('Need at least 2 records to test failed verification.');
        }
        $idsToDelete = array_slice($pgsqlIds, 0, 2);
        SyncedAlert::whereIn('id', $idsToDelete)->delete();

        // Clear previous verify logs
        SyncLog::where('operation', SyncLog::OPERATION_VERIFY)->delete();

        // Run verification (should fail)
        $verificationResult = $this->verificationService->verifyBatch($batchId);

        $this->assertFalse($verificationResult->isVerified());

        // Get the verify log entry
        $verifyLog = SyncLog::where('batch_id', $batchId)
            ->where('operation', SyncLog::OPERATION_VERIFY)
            ->first();

        // Property assertion: Verify log entry must exist
        $this->assertNotNull($verifyLog, "Verify log entry must exist for failed verification");

        // Property assertion: status must be 'failed'
        $this->assertEquals(
            SyncLog::STATUS_FAILED,
            $verifyLog->status,
            "Verify log status must be 'failed' for failed verification"
        );

        // Property assertion: error_message must be present for failed operation
        $this->assertNotNull(
            $verifyLog->error_message,
            "Verify log must have error_message for failed verification"
        );
        $this->assertNotEmpty(
            $verifyLog->error_message,
            "Verify log error_message must not be empty for failed verification"
        );

        // Property assertion: All other required fields must still be present
        $this->assertNotNull($verifyLog->created_at);
        $this->assertNotNull($verifyLog->duration_ms);
        $this->assertNotNull($verifyLog->records_affected);
    }

    /**
     * Property Test: Sync Log Service logWithTiming captures all fields
     * 
     * Feature: alerts-data-pipeline, Property 4: Sync Log Completeness
     * Validates: Requirements 2.1, 2.4
     * 
     * The logWithTiming method SHALL capture timing and all required fields.
     */
    public function test_log_with_timing_captures_all_fields(): void
    {
        $testBatchId = 99999;
        $expectedRecords = 42;

        // Test successful operation
        $result = $this->syncLogService->logWithTiming(
            SyncLog::OPERATION_SYNC,
            $testBatchId,
            function () use ($expectedRecords) {
                usleep(10000); // 10ms delay to ensure measurable duration
                return ['records_affected' => $expectedRecords];
            }
        );

        $log = $result['log'];

        // Property assertions for successful operation
        $this->assertNotNull($log->created_at, "Log must have created_at");
        $this->assertNotNull($log->duration_ms, "Log must have duration_ms");
        $this->assertGreaterThan(0, $log->duration_ms, "Duration should be > 0 for operation with delay");
        $this->assertEquals($expectedRecords, $log->records_affected, "Records affected must match");
        $this->assertEquals(SyncLog::STATUS_SUCCESS, $log->status, "Status must be success");
        $this->assertNull($log->error_message, "Error message must be null for success");
        $this->assertEquals($testBatchId, $log->batch_id, "Batch ID must match");
        $this->assertEquals(SyncLog::OPERATION_SYNC, $log->operation, "Operation must match");

        // Clean up
        $log->delete();
    }

    /**
     * Property Test: Sync Log Service logWithTiming captures errors
     * 
     * Feature: alerts-data-pipeline, Property 4: Sync Log Completeness
     * Validates: Requirements 2.1, 2.4
     * 
     * When an operation fails, logWithTiming SHALL capture the error message.
     */
    public function test_log_with_timing_captures_errors(): void
    {
        $testBatchId = 99998;
        $expectedErrorMessage = "Test error for property testing";

        $exceptionThrown = false;
        try {
            $this->syncLogService->logWithTiming(
                SyncLog::OPERATION_SYNC,
                $testBatchId,
                function () use ($expectedErrorMessage) {
                    throw new \Exception($expectedErrorMessage);
                }
            );
        } catch (\Exception $e) {
            $exceptionThrown = true;
            $this->assertEquals($expectedErrorMessage, $e->getMessage());
        }

        $this->assertTrue($exceptionThrown, "Exception should have been thrown");

        // Get the log entry
        $log = SyncLog::where('batch_id', $testBatchId)
            ->where('operation', SyncLog::OPERATION_SYNC)
            ->first();

        // Property assertions for failed operation
        $this->assertNotNull($log, "Log entry must exist even for failed operation");
        $this->assertNotNull($log->created_at, "Log must have created_at");
        $this->assertNotNull($log->duration_ms, "Log must have duration_ms");
        $this->assertEquals(SyncLog::STATUS_FAILED, $log->status, "Status must be failed");
        $this->assertNotNull($log->error_message, "Error message must be present");
        $this->assertStringContainsString(
            $expectedErrorMessage,
            $log->error_message,
            "Error message must contain the actual error"
        );

        // Clean up
        $log->delete();
    }

    /**
     * Property Test: Direct log methods capture all required fields
     * 
     * Feature: alerts-data-pipeline, Property 4: Sync Log Completeness
     * Validates: Requirements 2.1, 2.4
     * 
     * The direct log methods (logSync, logVerify, logCleanup) SHALL capture all fields.
     */
    public function test_direct_log_methods_capture_all_fields(): void
    {
        $testBatchId = 99997;
        $recordsAffected = 100;
        $durationMs = 500;

        // Test logSync
        $syncLog = $this->syncLogService->logSync(
            $testBatchId,
            $recordsAffected,
            SyncLog::STATUS_SUCCESS,
            $durationMs,
            null
        );

        $this->assertNotNull($syncLog->created_at);
        $this->assertEquals($recordsAffected, $syncLog->records_affected);
        $this->assertEquals(SyncLog::STATUS_SUCCESS, $syncLog->status);
        $this->assertEquals($durationMs, $syncLog->duration_ms);
        $this->assertNull($syncLog->error_message);
        $this->assertEquals(SyncLog::OPERATION_SYNC, $syncLog->operation);

        // Test logVerify
        $verifyLog = $this->syncLogService->logVerify(
            $testBatchId,
            $recordsAffected,
            SyncLog::STATUS_SUCCESS,
            $durationMs,
            null
        );

        $this->assertNotNull($verifyLog->created_at);
        $this->assertEquals(SyncLog::OPERATION_VERIFY, $verifyLog->operation);

        // Test logCleanup
        $cleanupLog = $this->syncLogService->logCleanup(
            $testBatchId,
            $recordsAffected,
            SyncLog::STATUS_SUCCESS,
            $durationMs,
            null
        );

        $this->assertNotNull($cleanupLog->created_at);
        $this->assertEquals(SyncLog::OPERATION_CLEANUP, $cleanupLog->operation);

        // Test with error message
        $errorMessage = "Test error message";
        $failedLog = $this->syncLogService->logSync(
            $testBatchId + 1,
            0,
            SyncLog::STATUS_FAILED,
            $durationMs,
            $errorMessage
        );

        $this->assertEquals(SyncLog::STATUS_FAILED, $failedLog->status);
        $this->assertEquals($errorMessage, $failedLog->error_message);

        // Clean up
        SyncLog::whereIn('batch_id', [$testBatchId, $testBatchId + 1])->delete();
    }

    /**
     * Property Test: Error message truncation preserves completeness
     * 
     * Feature: alerts-data-pipeline, Property 4: Sync Log Completeness
     * Validates: Requirements 2.1, 2.4
     * 
     * Very long error messages SHALL be truncated but still present.
     */
    public function test_error_message_truncation_preserves_completeness(): void
    {
        $testBatchId = 99996;
        
        // Create a very long error message (> 2000 chars)
        $longErrorMessage = str_repeat("Error detail. ", 200);
        $this->assertGreaterThan(2000, strlen($longErrorMessage));

        $log = $this->syncLogService->logSync(
            $testBatchId,
            0,
            SyncLog::STATUS_FAILED,
            100,
            $longErrorMessage
        );

        // Property assertion: Error message must still be present (not null)
        $this->assertNotNull($log->error_message, "Error message must be present even when truncated");
        
        // Property assertion: Error message should be truncated
        $this->assertLessThanOrEqual(
            2100, // 2000 + some buffer for truncation indicator
            strlen($log->error_message),
            "Error message should be truncated to reasonable length"
        );

        // Property assertion: Truncation indicator should be present
        if (strlen($longErrorMessage) > 2000) {
            $this->assertStringContainsString(
                '[truncated]',
                $log->error_message,
                "Truncated message should indicate truncation"
            );
        }

        // Clean up
        $log->delete();
    }

    /**
     * Property Test: Sync logs are queryable for history
     * 
     * Feature: alerts-data-pipeline, Property 4: Sync Log Completeness
     * Validates: Requirements 2.1, 2.4
     * 
     * Sync logs SHALL be queryable to show sync history.
     */
    public function test_sync_logs_queryable_for_history(): void
    {
        // Create some test logs
        $testBatchIds = [99990, 99991, 99992];
        
        foreach ($testBatchIds as $batchId) {
            $this->syncLogService->logSync($batchId, 100, SyncLog::STATUS_SUCCESS, 500);
        }

        // Test getLogs pagination
        $logs = $this->syncLogService->getLogs(10);
        $this->assertGreaterThanOrEqual(count($testBatchIds), $logs->count());

        // Test getRecentLogs
        $recentLogs = $this->syncLogService->getRecentLogs(30, SyncLog::OPERATION_SYNC);
        $this->assertGreaterThanOrEqual(count($testBatchIds), $recentLogs->count());

        // Test getLogsForBatch
        $batchLogs = $this->syncLogService->getLogsForBatch($testBatchIds[0]);
        $this->assertGreaterThanOrEqual(1, $batchLogs->count());

        // Property assertion: Each log in history has all required fields
        foreach ($logs as $log) {
            $this->assertNotNull($log->created_at, "Historical log must have created_at");
            $this->assertNotNull($log->status, "Historical log must have status");
            $this->assertNotNull($log->operation, "Historical log must have operation");
            // records_affected can be 0 but must be set
            $this->assertTrue(
                isset($log->records_affected),
                "Historical log must have records_affected"
            );
        }

        // Clean up
        SyncLog::whereIn('batch_id', $testBatchIds)->delete();
    }
}
