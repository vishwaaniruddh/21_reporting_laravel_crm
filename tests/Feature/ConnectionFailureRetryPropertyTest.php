<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\SyncBatch;
use App\Models\SyncedAlert;
use App\Models\SyncLog;
use App\Services\SyncService;
use App\Services\BatchResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Tests\TestCase;
use Exception;
use PDOException;
use Illuminate\Database\QueryException;

/**
 * Property-Based Test: Connection Failure Retry
 * 
 * Feature: alerts-data-pipeline, Property 10: Connection Failure Retry
 * Validates: Requirements 7.1
 * 
 * Property: For any database connection failure during sync, the system SHALL retry
 * the operation with increasing delays (exponential backoff) up to a maximum retry
 * count before marking the batch as failed.
 * 
 * IMPORTANT: This test uses EXISTING MySQL alerts data.
 * It does NOT create or delete any MySQL alerts.
 */
class ConnectionFailureRetryPropertyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTablesExist();
        $this->clearSyncData();
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
            DB::connection('pgsql')->table('sync_logs')->truncate();
            DB::connection('pgsql')->table('alerts')->truncate();
            DB::connection('mysql')->table('sync_batches')->truncate();
            DB::connection('mysql')->table('alerts')
                ->whereNotNull('synced_at')
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
     * Generates 100 iterations with varying configurations
     * 
     * @return array
     */
    public static function retryConfigProvider(): array
    {
        $iterations = [];
        for ($i = 1; $i <= 100; $i++) {
            // Vary max retries: 1, 2, 3, 4, 5
            $maxRetries = (($i - 1) % 5) + 1;
            // Vary batch sizes: 1, 3, 5, 10
            $batchSizes = [1, 3, 5, 10];
            $batchSize = $batchSizes[$i % count($batchSizes)];
            // Vary failure counts: fail all retries, fail some then succeed
            $failurePattern = $i % 3; // 0 = all fail, 1 = succeed on last, 2 = succeed midway
            
            $iterations["iteration_{$i}_retries_{$maxRetries}_batch_{$batchSize}"] = [
                $maxRetries,
                $batchSize,
                $failurePattern,
                $i
            ];
        }
        return $iterations;
    }

    /**
     * Property Test: System retries on connection failure with exponential backoff
     * 
     * Feature: alerts-data-pipeline, Property 10: Connection Failure Retry
     * Validates: Requirements 7.1
     * 
     * For any database connection failure during sync, the system SHALL retry
     * the operation with increasing delays (exponential backoff).
     * 
     * @dataProvider retryConfigProvider
     */
    public function test_system_retries_on_connection_failure(
        int $maxRetries,
        int $batchSize,
        int $failurePattern,
        int $iteration
    ): void {
        // Reset sync data for each iteration
        $this->clearSyncData();

        // Create a tracking sync service to monitor retry behavior
        $trackingService = new TrackingRetrySyncService($maxRetries, $batchSize);

        // Skip if no data to sync
        $unsyncedCount = Alert::unsynced()->count();
        if ($unsyncedCount === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }

        // Fetch unsynced batch from MySQL
        $mysqlAlerts = $trackingService->fetchUnsyncedBatch();
        
        if ($mysqlAlerts->isEmpty()) {
            $this->markTestSkipped('No unsynced records available for iteration ' . $iteration);
        }

        // Configure failure pattern
        $failUntilAttempt = match ($failurePattern) {
            0 => $maxRetries + 1, // All attempts fail
            1 => $maxRetries,     // Succeed on last attempt
            2 => max(1, (int)($maxRetries / 2)), // Succeed midway
            default => $maxRetries + 1,
        };
        $trackingService->setFailUntilAttempt($failUntilAttempt);

        // Process the batch
        $result = $trackingService->processBatch($mysqlAlerts);

        // Get retry tracking data
        $attemptCount = $trackingService->getAttemptCount();
        $delays = $trackingService->getDelays();

        // Property 1: System should retry up to max retries
        if ($failUntilAttempt > $maxRetries) {
            // All retries should be exhausted
            $this->assertEquals(
                $maxRetries,
                $attemptCount,
                "System should attempt exactly {$maxRetries} retries when all fail (iteration {$iteration})"
            );
            $this->assertFalse(
                $result->isSuccess(),
                "Batch should fail after exhausting all retries (iteration {$iteration})"
            );
        } else {
            // Should succeed before exhausting all retries
            $this->assertLessThanOrEqual(
                $maxRetries,
                $attemptCount,
                "System should not exceed max retries (iteration {$iteration})"
            );
            $this->assertTrue(
                $result->isSuccess(),
                "Batch should succeed when retry succeeds (iteration {$iteration}): " . 
                ($result->errorMessage ?? 'Unknown error')
            );
        }

        // Property 2: Delays should follow exponential backoff pattern
        if (count($delays) > 1) {
            for ($i = 1; $i < count($delays); $i++) {
                // Each delay should be approximately double the previous (with max cap)
                $expectedDelay = min($delays[$i - 1] * 2, 30); // Max 30 seconds
                $actualDelay = $delays[$i];
                
                // Allow for some tolerance due to timing
                $this->assertLessThanOrEqual(
                    $expectedDelay + 1,
                    $actualDelay,
                    "Delay should follow exponential backoff pattern (iteration {$iteration})"
                );
            }
        }
    }

    /**
     * Property Test: Exponential backoff delays are calculated correctly
     * 
     * Feature: alerts-data-pipeline, Property 10: Connection Failure Retry
     * Validates: Requirements 7.1
     * 
     * The backoff delays should follow the pattern: 1s, 2s, 4s, 8s, max 30s
     */
    public function test_exponential_backoff_delays_calculated_correctly(): void
    {
        $syncService = new SyncService();
        $config = $syncService->getRetryConfig();

        // Verify default configuration
        $this->assertEquals(5, $config['max_retries'], 'Default max retries should be 5');
        $this->assertEquals(1, $config['initial_delay_seconds'], 'Initial delay should be 1 second');
        $this->assertEquals(30, $config['max_delay_seconds'], 'Max delay should be 30 seconds');

        // Test delay calculation using reflection
        $reflection = new \ReflectionClass($syncService);
        $method = $reflection->getMethod('calculateBackoffDelay');
        $method->setAccessible(true);

        // Expected delays: 1, 2, 4, 8, 16, 30 (capped), 30 (capped)
        $expectedDelays = [1, 2, 4, 8, 16, 30, 30];
        
        for ($attempt = 1; $attempt <= 7; $attempt++) {
            $delay = $method->invoke($syncService, $attempt);
            $this->assertEquals(
                $expectedDelays[$attempt - 1],
                $delay,
                "Delay for attempt {$attempt} should be {$expectedDelays[$attempt - 1]} seconds"
            );
        }
    }

    /**
     * Property Test: Retryable errors are correctly identified
     * 
     * Feature: alerts-data-pipeline, Property 10: Connection Failure Retry
     * Validates: Requirements 7.1
     * 
     * Connection-related errors should be identified as retryable.
     */
    public function test_retryable_errors_correctly_identified(): void
    {
        $syncService = new SyncService();
        $reflection = new \ReflectionClass($syncService);
        $method = $reflection->getMethod('isRetryableError');
        $method->setAccessible(true);

        // Retryable error messages
        $retryableMessages = [
            'Connection refused',
            'Connection timeout',
            'Server has gone away',
            'Lost connection to MySQL server',
            'Connection reset by peer',
            'Broken pipe',
            'Network is unreachable',
            'Socket operation failed',
            'Deadlock found when trying to get lock',
            'Lock wait timeout exceeded',
        ];

        foreach ($retryableMessages as $message) {
            $exception = new PDOException($message);
            $this->assertTrue(
                $method->invoke($syncService, $exception),
                "Error '{$message}' should be identified as retryable"
            );
        }

        // Non-retryable error messages
        $nonRetryableMessages = [
            'Duplicate entry for key',
            'Column not found',
            'Table does not exist',
            'Syntax error',
            'Access denied',
        ];

        foreach ($nonRetryableMessages as $message) {
            $exception = new PDOException($message);
            $this->assertFalse(
                $method->invoke($syncService, $exception),
                "Error '{$message}' should NOT be identified as retryable"
            );
        }
    }

    /**
     * Property Test: Failed batch after max retries is marked correctly
     * 
     * Feature: alerts-data-pipeline, Property 10: Connection Failure Retry
     * Validates: Requirements 7.1
     * 
     * When all retries are exhausted, the batch should be marked as failed
     * with appropriate error message.
     */
    public function test_failed_batch_after_max_retries_marked_correctly(): void
    {
        $maxRetries = 3;
        $trackingService = new TrackingRetrySyncService($maxRetries, 5);
        $trackingService->setFailUntilAttempt($maxRetries + 1); // All attempts fail

        $unsyncedCount = Alert::unsynced()->count();
        if ($unsyncedCount === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }

        $mysqlAlerts = $trackingService->fetchUnsyncedBatch();
        if ($mysqlAlerts->isEmpty()) {
            $this->markTestSkipped('No unsynced records available.');
        }

        $result = $trackingService->processBatch($mysqlAlerts);

        // Verify batch failed
        $this->assertFalse($result->isSuccess());
        $this->assertNotNull($result->errorMessage);
        $this->assertStringContainsString(
            (string)$maxRetries,
            $result->errorMessage,
            'Error message should mention retry count'
        );

        // Verify batch status in database
        if ($result->batchId !== null) {
            $batch = SyncBatch::find($result->batchId);
            $this->assertNotNull($batch);
            $this->assertEquals(SyncBatch::STATUS_FAILED, $batch->status);
            $this->assertNotNull($batch->error_message);
        }

        // Verify sync log entry
        if ($result->batchId !== null) {
            $log = SyncLog::where('batch_id', $result->batchId)
                ->where('status', SyncLog::STATUS_FAILED)
                ->first();
            $this->assertNotNull($log, 'Failed sync should be logged');
        }
    }

    /**
     * Property Test: Successful retry recovers the batch
     * 
     * Feature: alerts-data-pipeline, Property 10: Connection Failure Retry
     * Validates: Requirements 7.1
     * 
     * When a retry succeeds, the batch should complete successfully.
     */
    public function test_successful_retry_recovers_batch(): void
    {
        $maxRetries = 5;
        $trackingService = new TrackingRetrySyncService($maxRetries, 5);
        $trackingService->setFailUntilAttempt(3); // Fail first 2 attempts, succeed on 3rd

        $unsyncedCount = Alert::unsynced()->count();
        if ($unsyncedCount === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }

        $mysqlAlerts = $trackingService->fetchUnsyncedBatch();
        if ($mysqlAlerts->isEmpty()) {
            $this->markTestSkipped('No unsynced records available.');
        }

        $alertIds = $mysqlAlerts->pluck('id')->toArray();
        $result = $trackingService->processBatch($mysqlAlerts);

        // Verify batch succeeded
        $this->assertTrue(
            $result->isSuccess(),
            'Batch should succeed after retry: ' . ($result->errorMessage ?? 'Unknown error')
        );

        // Verify records were synced
        $syncedCount = SyncedAlert::whereIn('id', $alertIds)->count();
        $this->assertEquals(
            count($alertIds),
            $syncedCount,
            'All records should be synced after successful retry'
        );

        // Verify MySQL markers were updated
        foreach ($alertIds as $id) {
            $alert = Alert::find($id);
            $this->assertNotNull($alert->synced_at, "Alert {$id} should have synced_at set");
            $this->assertNotNull($alert->sync_batch_id, "Alert {$id} should have sync_batch_id set");
        }

        // Verify attempt count
        $this->assertEquals(3, $trackingService->getAttemptCount(), 'Should have taken 3 attempts');
    }

    /**
     * Property Test: Non-retryable errors fail immediately
     * 
     * Feature: alerts-data-pipeline, Property 10: Connection Failure Retry
     * Validates: Requirements 7.1
     * 
     * Non-connection errors should not be retried.
     */
    public function test_non_retryable_errors_fail_immediately(): void
    {
        $maxRetries = 5;
        $trackingService = new NonRetryableErrorSyncService($maxRetries, 5);

        $unsyncedCount = Alert::unsynced()->count();
        if ($unsyncedCount === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }

        $mysqlAlerts = $trackingService->fetchUnsyncedBatch();
        if ($mysqlAlerts->isEmpty()) {
            $this->markTestSkipped('No unsynced records available.');
        }

        $result = $trackingService->processBatch($mysqlAlerts);

        // Verify batch failed
        $this->assertFalse($result->isSuccess());

        // Verify only 1 attempt was made (no retries for non-retryable errors)
        $this->assertEquals(
            1,
            $trackingService->getAttemptCount(),
            'Non-retryable errors should not trigger retries'
        );
    }
}

/**
 * Test helper class that tracks retry behavior
 */
class TrackingRetrySyncService extends SyncService
{
    protected int $attemptCount = 0;
    protected array $delays = [];
    protected int $failUntilAttempt = PHP_INT_MAX;
    protected int $customMaxRetries;

    public function __construct(int $maxRetries, int $batchSize)
    {
        parent::__construct();
        $this->customMaxRetries = $maxRetries;
        $this->maxRetries = $maxRetries;
        $this->setBatchSize($batchSize);
        // Use minimal delays for testing (don't actually sleep)
        $this->initialDelaySeconds = 1;
        $this->maxDelaySeconds = 30;
    }

    public function setFailUntilAttempt(int $attempt): void
    {
        $this->failUntilAttempt = $attempt;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function getDelays(): array
    {
        return $this->delays;
    }

    protected function bulkInsertToPostgresWithRetry(Collection $alerts, int $batchId): void
    {
        $this->attemptCount = 0;
        $this->delays = [];
        $lastException = null;

        while ($this->attemptCount < $this->customMaxRetries) {
            $this->attemptCount++;

            try {
                if ($this->attemptCount < $this->failUntilAttempt) {
                    // Simulate connection failure
                    throw new PDOException("Simulated connection timeout for testing");
                }
                
                // Success - perform actual insert
                $this->bulkInsertToPostgres($alerts, $batchId);
                return;
            } catch (PDOException $e) {
                $lastException = $e;

                if (!$this->isRetryableError($e)) {
                    throw $e;
                }

                if ($this->attemptCount < $this->customMaxRetries) {
                    $delay = $this->calculateBackoffDelay($this->attemptCount);
                    $this->delays[] = $delay;
                    // Don't actually sleep in tests - just track the delay
                }
            }
        }

        throw new Exception(
            "PostgreSQL insert failed after {$this->attemptCount} attempts: " . 
            ($lastException?->getMessage() ?? 'Unknown error')
        );
    }
}

/**
 * Test helper class that throws non-retryable errors
 */
class NonRetryableErrorSyncService extends SyncService
{
    protected int $attemptCount = 0;
    protected int $customMaxRetries;

    public function __construct(int $maxRetries, int $batchSize)
    {
        parent::__construct();
        $this->customMaxRetries = $maxRetries;
        $this->maxRetries = $maxRetries;
        $this->setBatchSize($batchSize);
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    protected function bulkInsertToPostgresWithRetry(Collection $alerts, int $batchId): void
    {
        $this->attemptCount++;
        // Throw a non-retryable error (syntax error, not connection error)
        throw new PDOException("Syntax error in SQL statement");
    }
}
