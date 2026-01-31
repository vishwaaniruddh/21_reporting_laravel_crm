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
use Mockery;

/**
 * Property-Based Test: Transaction Rollback on Failure
 * 
 * Feature: alerts-data-pipeline, Property 3: Transaction Rollback on Failure
 * Validates: Requirements 1.4, 7.2
 * 
 * Property: For any sync batch that encounters a failure during PostgreSQL insertion,
 * the batch SHALL be rolled back completely with zero partial records in PostgreSQL
 * and zero sync markers updated in MySQL.
 * 
 * IMPORTANT: This test uses EXISTING MySQL alerts data.
 * It does NOT create or delete any MySQL alerts.
 */
class TransactionRollbackPropertyTest extends TestCase
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
        Mockery::close();
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
     * Generates 100 iterations with varying batch sizes
     * 
     * @return array
     */
    public static function batchSizeProvider(): array
    {
        $iterations = [];
        for ($i = 1; $i <= 100; $i++) {
            $batchSizes = [1, 3, 5, 10, 15, 20, 25];
            $batchSize = $batchSizes[$i % count($batchSizes)];
            $iterations["iteration_{$i}_batch_{$batchSize}"] = [$batchSize, $i];
        }
        return $iterations;
    }

    /**
     * Property Test: Transaction Rollback on Failure
     * 
     * Feature: alerts-data-pipeline, Property 3: Transaction Rollback on Failure
     * Validates: Requirements 1.4, 7.2
     * 
     * For any sync batch that encounters a failure during PostgreSQL insertion,
     * the batch SHALL be rolled back completely with:
     * 1. Zero partial records in PostgreSQL for that batch
     * 2. Zero sync markers updated in MySQL for that batch
     * 
     * @dataProvider batchSizeProvider
     */
    public function test_failed_batch_has_zero_partial_records(int $batchSize, int $iteration): void
    {
        // Reset sync data for each iteration
        $this->clearSyncData();

        $syncService = new SyncService();
        $syncService->setBatchSize($batchSize);

        // Skip if no data to sync
        $unsyncedCount = $syncService->getUnsyncedCount();
        if ($unsyncedCount === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }

        // Fetch unsynced batch from MySQL
        $mysqlAlerts = $syncService->fetchUnsyncedBatch();
        
        if ($mysqlAlerts->isEmpty()) {
            $this->markTestSkipped('No unsynced records available for iteration ' . $iteration);
        }

        // Store original IDs and their sync state before attempting sync
        $originalIds = $mysqlAlerts->pluck('id')->toArray();
        $originalSyncStates = [];
        foreach ($mysqlAlerts as $alert) {
            $originalSyncStates[$alert->id] = [
                'synced_at' => $alert->synced_at,
                'sync_batch_id' => $alert->sync_batch_id,
            ];
        }

        // Count PostgreSQL records before the failed sync attempt
        $pgsqlCountBefore = SyncedAlert::count();

        // Create a mock SyncService that will fail during PostgreSQL insertion
        $failingSyncService = $this->createFailingSyncService($batchSize);

        // Attempt to process the batch (this should fail)
        $result = $failingSyncService->processBatch($mysqlAlerts);

        // Assert the batch failed
        $this->assertFalse(
            $result->isSuccess(),
            "Batch should have failed on iteration {$iteration}"
        );

        // Property 1: Zero partial records in PostgreSQL for this batch
        $pgsqlCountAfter = SyncedAlert::count();
        $this->assertEquals(
            $pgsqlCountBefore,
            $pgsqlCountAfter,
            "PostgreSQL should have zero new records after failed batch (iteration {$iteration}). " .
            "Before: {$pgsqlCountBefore}, After: {$pgsqlCountAfter}"
        );

        // Verify no records from this batch exist in PostgreSQL
        $batchId = $result->batchId;
        if ($batchId !== null) {
            $pgsqlBatchRecords = SyncedAlert::where('sync_batch_id', $batchId)->count();
            $this->assertEquals(
                0,
                $pgsqlBatchRecords,
                "PostgreSQL should have zero records for failed batch {$batchId} (iteration {$iteration})"
            );
        }

        // Property 2: Zero sync markers updated in MySQL for this batch
        foreach ($originalIds as $id) {
            $mysqlAlert = Alert::find($id);
            $this->assertNotNull($mysqlAlert, "MySQL alert {$id} should still exist");

            // Sync markers should be unchanged (still null or original value)
            $this->assertEquals(
                $originalSyncStates[$id]['synced_at'],
                $mysqlAlert->synced_at,
                "MySQL alert {$id} synced_at should be unchanged after failed batch (iteration {$iteration})"
            );
            $this->assertEquals(
                $originalSyncStates[$id]['sync_batch_id'],
                $mysqlAlert->sync_batch_id,
                "MySQL alert {$id} sync_batch_id should be unchanged after failed batch (iteration {$iteration})"
            );
        }

        // Verify batch status is 'failed'
        if ($batchId !== null) {
            $syncBatch = SyncBatch::find($batchId);
            $this->assertNotNull($syncBatch, "SyncBatch record should exist");
            $this->assertEquals(
                SyncBatch::STATUS_FAILED,
                $syncBatch->status,
                "SyncBatch status should be 'failed' (iteration {$iteration})"
            );
            $this->assertNotNull(
                $syncBatch->error_message,
                "SyncBatch should have an error message (iteration {$iteration})"
            );
        }
    }

    /**
     * Property Test: Rollback preserves MySQL data integrity
     * 
     * Feature: alerts-data-pipeline, Property 3: Transaction Rollback on Failure
     * Validates: Requirements 1.4, 7.2
     * 
     * After a failed sync, MySQL alerts data should be completely unchanged
     * (except for the sync_batches table which tracks the failed attempt).
     */
    public function test_rollback_preserves_mysql_data_integrity(): void
    {
        $syncService = new SyncService();
        $syncService->setBatchSize(10);

        if ($syncService->getUnsyncedCount() === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }

        $mysqlAlerts = $syncService->fetchUnsyncedBatch();
        $alertIds = $mysqlAlerts->pluck('id')->toArray();

        // Store complete snapshot of MySQL data before sync attempt using raw query
        // This avoids any model serialization issues
        $originalData = DB::connection('mysql')
            ->table('alerts')
            ->whereIn('id', $alertIds)
            ->get()
            ->keyBy('id')
            ->toArray();

        // Create failing sync service
        $failingSyncService = $this->createFailingSyncService(10);
        $result = $failingSyncService->processBatch($mysqlAlerts);

        $this->assertFalse($result->isSuccess());

        // Get current data using raw query
        $currentData = DB::connection('mysql')
            ->table('alerts')
            ->whereIn('id', $alertIds)
            ->get()
            ->keyBy('id')
            ->toArray();

        // Verify ALL MySQL data is unchanged (not just sync markers)
        $fieldsToCompare = [
            'panelid', 'seqno', 'zone', 'alarm', 'createtime', 'receivedtime',
            'comment', 'status', 'sendtoclient', 'closedBy', 'closedtime',
            'sendip', 'alerttype', 'location', 'priority', 'AlertUserStatus',
            'level', 'sip2', 'c_status', 'auto_alert', 'critical_alerts',
            'Readstatus', 'synced_at', 'sync_batch_id'
        ];

        foreach ($originalData as $id => $originalRecord) {
            $this->assertArrayHasKey($id, $currentData, "Alert {$id} should still exist");
            $currentRecord = $currentData[$id];

            foreach ($fieldsToCompare as $field) {
                $originalValue = $originalRecord->{$field} ?? null;
                $currentValue = $currentRecord->{$field} ?? null;

                $this->assertEquals(
                    $originalValue,
                    $currentValue,
                    "MySQL alert {$id} field '{$field}' should be unchanged after failed sync. " .
                    "Original: " . var_export($originalValue, true) . ", Current: " . var_export($currentValue, true)
                );
            }
        }
    }

    /**
     * Check if a string looks like a datetime
     */
    protected function isDateTimeString(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1;
    }

    /**
     * Normalize datetime string to Y-m-d H:i:s format
     */
    protected function normalizeDateTimeString(string $value): string
    {
        try {
            return (new \DateTime($value))->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return $value;
        }
    }

    /**
     * Property Test: Failed sync logs the failure
     * 
     * Feature: alerts-data-pipeline, Property 3: Transaction Rollback on Failure
     * Validates: Requirements 1.4, 7.2
     * 
     * When a sync batch fails, the failure should be logged in sync_logs.
     */
    public function test_failed_sync_is_logged(): void
    {
        $syncService = new SyncService();
        $syncService->setBatchSize(5);

        if ($syncService->getUnsyncedCount() === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }

        $mysqlAlerts = $syncService->fetchUnsyncedBatch();

        // Count logs before
        $logCountBefore = SyncLog::count();

        // Create failing sync service
        $failingSyncService = $this->createFailingSyncService(5);
        $result = $failingSyncService->processBatch($mysqlAlerts);

        $this->assertFalse($result->isSuccess());

        // Verify a log entry was created for the failure
        $logCountAfter = SyncLog::count();
        $this->assertGreaterThan(
            $logCountBefore,
            $logCountAfter,
            "A sync log entry should be created for the failed batch"
        );

        // Verify the log entry has correct status
        if ($result->batchId !== null) {
            $failedLog = SyncLog::where('batch_id', $result->batchId)
                ->where('status', SyncLog::STATUS_FAILED)
                ->first();

            $this->assertNotNull(
                $failedLog,
                "A failed sync log entry should exist for batch {$result->batchId}"
            );

            $this->assertNotNull(
                $failedLog->error_message,
                "Failed sync log should have an error message"
            );
        }
    }

    /**
     * Property Test: Successful sync after failed sync works correctly
     * 
     * Feature: alerts-data-pipeline, Property 3: Transaction Rollback on Failure
     * Validates: Requirements 1.4, 7.2
     * 
     * After a failed sync, a subsequent successful sync should work correctly
     * without any leftover state from the failed attempt.
     */
    public function test_successful_sync_after_failed_sync(): void
    {
        $syncService = new SyncService();
        $syncService->setBatchSize(5);

        if ($syncService->getUnsyncedCount() === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }

        $mysqlAlerts = $syncService->fetchUnsyncedBatch();
        $originalIds = $mysqlAlerts->pluck('id')->toArray();

        // First, attempt a failing sync
        $failingSyncService = $this->createFailingSyncService(5);
        $failedResult = $failingSyncService->processBatch($mysqlAlerts);
        $this->assertFalse($failedResult->isSuccess());

        // Verify no records synced
        $this->assertEquals(0, SyncedAlert::whereIn('id', $originalIds)->count());

        // Now, perform a successful sync with the real service
        // Re-fetch the alerts (they should still be unsynced)
        $mysqlAlertsRetry = $syncService->fetchUnsyncedBatch();
        $this->assertGreaterThan(0, $mysqlAlertsRetry->count(), "Alerts should still be available for sync");

        $successResult = $syncService->processBatch($mysqlAlertsRetry);
        $this->assertTrue(
            $successResult->isSuccess(),
            "Retry sync should succeed: " . ($successResult->errorMessage ?? 'Unknown error')
        );

        // Verify records are now synced
        $syncedIds = $mysqlAlertsRetry->pluck('id')->toArray();
        $pgsqlCount = SyncedAlert::whereIn('id', $syncedIds)->count();
        $this->assertEquals(
            count($syncedIds),
            $pgsqlCount,
            "All records should be synced after successful retry"
        );

        // Verify MySQL markers are set
        foreach ($syncedIds as $id) {
            $mysqlAlert = Alert::find($id);
            $this->assertNotNull($mysqlAlert->synced_at, "MySQL alert {$id} should have synced_at set");
            $this->assertNotNull($mysqlAlert->sync_batch_id, "MySQL alert {$id} should have sync_batch_id set");
        }
    }

    /**
     * Create a SyncService that will fail during PostgreSQL insertion
     * 
     * This creates a partial mock that overrides the bulkInsertToPostgres method
     * to simulate a failure during PostgreSQL insertion.
     */
    protected function createFailingSyncService(int $batchSize): SyncService
    {
        $service = new class extends SyncService {
            protected bool $shouldFail = true;

            public function setShouldFail(bool $fail): void
            {
                $this->shouldFail = $fail;
            }

            protected function bulkInsertToPostgresWithRetry(Collection $alerts, int $batchId): void
            {
                if ($this->shouldFail) {
                    throw new Exception("Simulated PostgreSQL connection failure for testing");
                }
                parent::bulkInsertToPostgresWithRetry($alerts, $batchId);
            }
        };

        $service->setBatchSize($batchSize);
        return $service;
    }
}
