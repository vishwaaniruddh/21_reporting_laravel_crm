<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\SyncBatch;
use App\Models\SyncedAlert;
use App\Models\SyncLog;
use App\Services\SyncService;
use App\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Checkpoint test: Verify sync engine works
 * 
 * This test verifies:
 * - Sync runs on existing MySQL data
 * - Records appear in PostgreSQL
 * - All sync operations complete successfully
 * 
 * IMPORTANT: These tests use EXISTING MySQL alerts data.
 * They do NOT create or delete any MySQL alerts.
 */
class SyncEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure PostgreSQL tables exist
        $this->ensurePostgreSqlTablesExist();
        
        // Clear PostgreSQL data and sync tracking (but NEVER MySQL alerts!)
        $this->clearSyncData();
    }

    protected function tearDown(): void
    {
        // Reset sync markers on MySQL alerts that were synced during tests
        $this->resetSyncMarkers();
        parent::tearDown();
    }

    /**
     * Ensure PostgreSQL tables exist for testing
     */
    protected function ensurePostgreSqlTablesExist(): void
    {
        // Alerts table should already exist from migrations
        if (!Schema::connection('pgsql')->hasTable('alerts')) {
            $this->markTestSkipped('PostgreSQL alerts table does not exist. Run migrations first.');
        }

        // Sync logs table should already exist from migrations
        if (!Schema::connection('pgsql')->hasTable('sync_logs')) {
            $this->markTestSkipped('PostgreSQL sync_logs table does not exist. Run migrations first.');
        }
    }

    /**
     * Clear sync data - NEVER delete from MySQL alerts!
     */
    protected function clearSyncData(): void
    {
        try {
            // Clear PostgreSQL tables (safe to truncate)
            DB::connection('pgsql')->table('sync_logs')->truncate();
            DB::connection('pgsql')->table('alerts')->truncate();
            
            // Clear sync_batches in MySQL (tracking table only)
            DB::connection('mysql')->table('sync_batches')->truncate();
            
            // Reset sync markers on MySQL alerts (but don't delete!)
            DB::connection('mysql')->table('alerts')
                ->whereNotNull('synced_at')
                ->update(['synced_at' => null, 'sync_batch_id' => null]);
        } catch (\Exception $e) {
            // Tables may not exist yet
        }
    }

    /**
     * Reset sync markers after tests
     */
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
     * Test: Sync service can count unsynced records
     */
    public function test_sync_service_counts_unsynced_records(): void
    {
        $syncService = new SyncService();
        
        // Get count of unsynced records
        $unsyncedCount = $syncService->getUnsyncedCount();
        
        // Should have some records to sync (existing MySQL data)
        $this->assertIsInt($unsyncedCount);
        $this->assertGreaterThanOrEqual(0, $unsyncedCount);
        
        // Log for visibility
        echo "\nUnsynced records in MySQL: {$unsyncedCount}\n";
    }

    /**
     * Test: Sync service can fetch unsynced records
     */
    public function test_sync_service_fetches_unsynced_records(): void
    {
        $syncService = new SyncService();
        $syncService->setBatchSize(10); // Small batch for testing
        
        // Skip if no data to sync
        if ($syncService->getUnsyncedCount() === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }
        
        // Fetch unsynced batch
        $batch = $syncService->fetchUnsyncedBatch();
        
        $this->assertNotEmpty($batch);
        $this->assertLessThanOrEqual(10, $batch->count());
        $this->assertTrue($batch->every(fn($alert) => $alert->synced_at === null));
    }

    /**
     * Test: Sync service processes batch and records appear in PostgreSQL
     */
    public function test_sync_service_processes_batch_to_postgresql(): void
    {
        $syncService = new SyncService();
        $syncService->setBatchSize(5); // Small batch for testing
        
        // Skip if no data to sync
        if ($syncService->getUnsyncedCount() === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }
        
        // Fetch and process batch
        $batch = $syncService->fetchUnsyncedBatch();
        $batchCount = $batch->count();
        $result = $syncService->processBatch($batch);

        // Verify result
        $this->assertTrue($result->isSuccess(), 'Batch processing failed: ' . $result->errorMessage);
        $this->assertEquals($batchCount, $result->recordsProcessed);
        $this->assertNotNull($result->batchId);

        // Verify records appear in PostgreSQL
        $syncedCount = SyncedAlert::count();
        $this->assertEquals($batchCount, $syncedCount);

        // Verify data integrity - check first record
        $firstAlert = $batch->first();
        $syncedAlert = SyncedAlert::find($firstAlert->id);
        $this->assertNotNull($syncedAlert);
        $this->assertEquals($firstAlert->panelid, $syncedAlert->panelid);
        $this->assertEquals($firstAlert->alerttype, $syncedAlert->alerttype);
    }

    /**
     * Test: Sync markers are updated in MySQL after successful sync
     */
    public function test_sync_markers_updated_after_sync(): void
    {
        $syncService = new SyncService();
        $syncService->setBatchSize(5);
        
        // Skip if no data to sync
        if ($syncService->getUnsyncedCount() === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }
        
        // Process batch
        $batch = $syncService->fetchUnsyncedBatch();
        $alertIds = $batch->pluck('id')->toArray();
        $result = $syncService->processBatch($batch);

        $this->assertTrue($result->isSuccess());

        // Verify sync markers in MySQL
        $syncedAlerts = Alert::whereIn('id', $alertIds)->get();
        
        foreach ($syncedAlerts as $alert) {
            $this->assertNotNull($alert->synced_at, "Alert {$alert->id} should have synced_at set");
            $this->assertEquals($result->batchId, $alert->sync_batch_id);
        }
    }

    /**
     * Test: Sync batch record is created and updated correctly
     */
    public function test_sync_batch_record_created(): void
    {
        $syncService = new SyncService();
        $syncService->setBatchSize(5);
        
        // Skip if no data to sync
        if ($syncService->getUnsyncedCount() === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }
        
        // Process batch
        $batch = $syncService->fetchUnsyncedBatch();
        $result = $syncService->processBatch($batch);

        $this->assertTrue($result->isSuccess());

        // Verify sync batch record
        $syncBatch = SyncBatch::find($result->batchId);
        $this->assertNotNull($syncBatch);
        $this->assertEquals($batch->count(), $syncBatch->records_count);
        $this->assertEquals(SyncBatch::STATUS_COMPLETED, $syncBatch->status);
        $this->assertNotNull($syncBatch->started_at);
        $this->assertNotNull($syncBatch->completed_at);
    }

    /**
     * Test: Sync log is created in PostgreSQL
     */
    public function test_sync_log_created(): void
    {
        $syncService = new SyncService();
        $syncService->setBatchSize(5);
        
        // Skip if no data to sync
        if ($syncService->getUnsyncedCount() === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }
        
        // Process batch
        $batch = $syncService->fetchUnsyncedBatch();
        $result = $syncService->processBatch($batch);

        $this->assertTrue($result->isSuccess());

        // Verify sync log
        $syncLog = SyncLog::where('batch_id', $result->batchId)->first();
        $this->assertNotNull($syncLog);
        $this->assertEquals(SyncLog::OPERATION_SYNC, $syncLog->operation);
        $this->assertEquals($batch->count(), $syncLog->records_affected);
        $this->assertEquals(SyncLog::STATUS_SUCCESS, $syncLog->status);
        $this->assertNotNull($syncLog->duration_ms);
    }

    /**
     * Test: Empty batch returns success with zero records
     */
    public function test_empty_batch_returns_success(): void
    {
        $syncService = new SyncService();
        
        // Process empty collection
        $result = $syncService->processBatch(collect([]));

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(0, $result->recordsProcessed);
    }

    /**
     * Test: Multiple batches can be processed sequentially
     */
    public function test_multiple_batches_processed(): void
    {
        $syncService = new SyncService();
        $syncService->setBatchSize(3); // Very small batch for testing
        
        $initialUnsynced = $syncService->getUnsyncedCount();
        
        // Skip if not enough data
        if ($initialUnsynced < 6) {
            $this->markTestSkipped('Need at least 6 unsynced records for this test.');
        }

        $totalProcessed = 0;
        $batchCount = 0;
        $maxBatches = 3; // Limit to 3 batches for testing

        // Process multiple batches
        while ($syncService->hasRecordsToSync() && $batchCount < $maxBatches) {
            $batch = $syncService->fetchUnsyncedBatch();
            if ($batch->isEmpty()) break;
            
            $result = $syncService->processBatch($batch);
            $this->assertTrue($result->isSuccess(), 'Batch failed: ' . $result->errorMessage);
            
            $totalProcessed += $result->recordsProcessed;
            $batchCount++;
        }

        // Verify batches were processed
        $this->assertGreaterThan(0, $batchCount);
        $this->assertEquals($totalProcessed, SyncedAlert::count());
        
        echo "\nProcessed {$totalProcessed} records in {$batchCount} batches\n";
    }

    /**
     * Test: Artisan command shows correct status
     */
    public function test_artisan_command_shows_status(): void
    {
        $this->artisan('pipeline:sync', ['--status' => true])
            ->assertSuccessful();
    }

    /**
     * Test: SyncJob can be run synchronously with small batch
     */
    public function test_sync_job_runs_synchronously(): void
    {
        $syncService = new SyncService();
        
        // Skip if no data to sync
        if ($syncService->getUnsyncedCount() === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }

        // Run sync job synchronously with small batch
        $job = new SyncJob(null, 5);
        $job->handle($syncService);

        // Verify some records were synced
        $this->assertGreaterThan(0, SyncedAlert::count());

        // Verify job status
        $status = SyncJob::getStatus();
        $this->assertEquals('completed', $status['status']);
    }
}
