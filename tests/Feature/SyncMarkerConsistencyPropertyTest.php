<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\SyncBatch;
use App\Models\SyncedAlert;
use App\Services\SyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Property-Based Test: Sync Marker Consistency
 * 
 * Feature: alerts-data-pipeline, Property 2: Sync Marker Consistency
 * Validates: Requirements 1.3
 * 
 * Property: For any record that exists in PostgreSQL with a given sync_batch_id,
 * the corresponding MySQL record SHALL have a non-null synced_at timestamp and
 * matching sync_batch_id.
 * 
 * IMPORTANT: This test uses EXISTING MySQL alerts data.
 * It does NOT create or delete any MySQL alerts.
 */
class SyncMarkerConsistencyPropertyTest extends TestCase
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
     * Property Test: Sync Marker Consistency
     * 
     * Feature: alerts-data-pipeline, Property 2: Sync Marker Consistency
     * Validates: Requirements 1.3
     * 
     * For any record that exists in PostgreSQL with a given sync_batch_id,
     * the corresponding MySQL record SHALL have:
     * 1. A non-null synced_at timestamp
     * 2. A matching sync_batch_id
     * 
     * @dataProvider batchSizeProvider
     */
    public function test_sync_markers_consistent_between_mysql_and_postgresql(int $batchSize, int $iteration): void
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

        // Store original IDs before sync
        $originalIds = $mysqlAlerts->pluck('id')->toArray();

        // Process the batch (sync to PostgreSQL)
        $result = $syncService->processBatch($mysqlAlerts);

        // Assert sync was successful
        $this->assertTrue(
            $result->isSuccess(),
            "Sync failed on iteration {$iteration}: " . ($result->errorMessage ?? 'Unknown error')
        );

        $batchId = $result->batchId;
        $this->assertNotNull($batchId, "Batch ID should not be null after successful sync");

        // Property assertion: For ALL records in PostgreSQL with this batch_id,
        // the corresponding MySQL record must have consistent sync markers
        $pgsqlRecords = SyncedAlert::where('sync_batch_id', $batchId)->get();

        $this->assertGreaterThan(
            0,
            $pgsqlRecords->count(),
            "No records found in PostgreSQL for batch {$batchId} on iteration {$iteration}"
        );

        foreach ($pgsqlRecords as $pgsqlRecord) {
            // Get corresponding MySQL record
            $mysqlRecord = Alert::find($pgsqlRecord->id);

            // Assert MySQL record exists
            $this->assertNotNull(
                $mysqlRecord,
                "MySQL record ID {$pgsqlRecord->id} not found but exists in PostgreSQL (iteration {$iteration})"
            );

            // Property 1: MySQL record must have non-null synced_at
            $this->assertNotNull(
                $mysqlRecord->synced_at,
                "MySQL record ID {$pgsqlRecord->id} has null synced_at but exists in PostgreSQL " .
                "with batch_id {$batchId} (iteration {$iteration})"
            );

            // Property 2: MySQL sync_batch_id must match PostgreSQL sync_batch_id
            $this->assertEquals(
                $pgsqlRecord->sync_batch_id,
                $mysqlRecord->sync_batch_id,
                "Sync batch ID mismatch for record ID {$pgsqlRecord->id}. " .
                "PostgreSQL: {$pgsqlRecord->sync_batch_id}, MySQL: {$mysqlRecord->sync_batch_id} " .
                "(iteration {$iteration})"
            );

            // Additional: PostgreSQL synced_at should also be non-null
            $this->assertNotNull(
                $pgsqlRecord->synced_at,
                "PostgreSQL record ID {$pgsqlRecord->id} has null synced_at (iteration {$iteration})"
            );
        }

        // Verify count consistency
        $mysqlSyncedCount = Alert::where('sync_batch_id', $batchId)->count();
        $pgsqlCount = $pgsqlRecords->count();

        $this->assertEquals(
            $mysqlSyncedCount,
            $pgsqlCount,
            "Record count mismatch for batch {$batchId}. " .
            "MySQL synced: {$mysqlSyncedCount}, PostgreSQL: {$pgsqlCount} (iteration {$iteration})"
        );
    }

    /**
     * Property Test: All PostgreSQL records have corresponding MySQL markers
     * 
     * Feature: alerts-data-pipeline, Property 2: Sync Marker Consistency
     * Validates: Requirements 1.3
     * 
     * After multiple sync operations, ALL records in PostgreSQL must have
     * corresponding sync markers in MySQL.
     */
    public function test_all_postgresql_records_have_mysql_markers(): void
    {
        $syncService = new SyncService();
        $syncService->setBatchSize(10);

        // Skip if no data to sync
        if ($syncService->getUnsyncedCount() === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }

        // Process multiple batches
        $batchesProcessed = 0;
        $maxBatches = 5;

        while ($syncService->hasRecordsToSync() && $batchesProcessed < $maxBatches) {
            $batch = $syncService->fetchUnsyncedBatch();
            if ($batch->isEmpty()) break;

            $result = $syncService->processBatch($batch);
            $this->assertTrue($result->isSuccess(), 'Batch failed: ' . $result->errorMessage);
            $batchesProcessed++;
        }

        // Skip if no batches were processed
        if ($batchesProcessed === 0) {
            $this->markTestSkipped('No batches were processed.');
        }

        // Property assertion: Every record in PostgreSQL must have consistent MySQL markers
        $allPgsqlRecords = SyncedAlert::all();

        foreach ($allPgsqlRecords as $pgsqlRecord) {
            $mysqlRecord = Alert::find($pgsqlRecord->id);

            $this->assertNotNull(
                $mysqlRecord,
                "MySQL record ID {$pgsqlRecord->id} not found but exists in PostgreSQL"
            );

            $this->assertNotNull(
                $mysqlRecord->synced_at,
                "MySQL record ID {$pgsqlRecord->id} has null synced_at"
            );

            $this->assertEquals(
                $pgsqlRecord->sync_batch_id,
                $mysqlRecord->sync_batch_id,
                "Batch ID mismatch for record ID {$pgsqlRecord->id}"
            );
        }
    }

    /**
     * Property Test: Sync batch record consistency
     * 
     * Feature: alerts-data-pipeline, Property 2: Sync Marker Consistency
     * Validates: Requirements 1.3
     * 
     * For any completed sync batch, the batch record must accurately reflect
     * the synced records.
     */
    public function test_sync_batch_record_consistency(): void
    {
        $syncService = new SyncService();
        $syncService->setBatchSize(15);

        // Skip if no data to sync
        if ($syncService->getUnsyncedCount() === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }

        // Process a batch
        $batch = $syncService->fetchUnsyncedBatch();
        $result = $syncService->processBatch($batch);

        $this->assertTrue($result->isSuccess());

        // Get the sync batch record
        $syncBatch = SyncBatch::find($result->batchId);
        $this->assertNotNull($syncBatch);

        // Property: Batch record must accurately reflect synced records
        $mysqlSyncedInBatch = Alert::where('sync_batch_id', $syncBatch->id)->count();
        $pgsqlInBatch = SyncedAlert::where('sync_batch_id', $syncBatch->id)->count();

        // Batch records_count should match actual synced count
        $this->assertEquals(
            $syncBatch->records_count,
            $mysqlSyncedInBatch,
            "Batch records_count ({$syncBatch->records_count}) doesn't match MySQL synced count ({$mysqlSyncedInBatch})"
        );

        $this->assertEquals(
            $syncBatch->records_count,
            $pgsqlInBatch,
            "Batch records_count ({$syncBatch->records_count}) doesn't match PostgreSQL count ({$pgsqlInBatch})"
        );

        // Batch ID range should contain all synced records
        $minId = Alert::where('sync_batch_id', $syncBatch->id)->min('id');
        $maxId = Alert::where('sync_batch_id', $syncBatch->id)->max('id');

        $this->assertEquals($syncBatch->start_id, $minId, "Batch start_id mismatch");
        $this->assertEquals($syncBatch->end_id, $maxId, "Batch end_id mismatch");

        // Batch status should be completed
        $this->assertEquals(
            SyncBatch::STATUS_COMPLETED,
            $syncBatch->status,
            "Batch status should be 'completed'"
        );
    }
}
