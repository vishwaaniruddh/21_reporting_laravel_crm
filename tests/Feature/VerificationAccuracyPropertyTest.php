<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\SyncBatch;
use App\Models\SyncedAlert;
use App\Models\SyncLog;
use App\Services\SyncService;
use App\Services\VerificationService;
use App\Services\VerificationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Property-Based Test: Verification Accuracy
 * 
 * Feature: alerts-data-pipeline, Property 5: Verification Accuracy
 * Validates: Requirements 3.1
 * 
 * Property: For any synced batch, the verification service SHALL correctly report
 * whether the MySQL source count equals the PostgreSQL target count for that batch.
 * 
 * IMPORTANT: This test uses EXISTING MySQL alerts data.
 * It does NOT create or delete any MySQL alerts.
 */
class VerificationAccuracyPropertyTest extends TestCase
{
    protected SyncService $syncService;
    protected VerificationService $verificationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTablesExist();
        $this->clearSyncData();
        $this->syncService = new SyncService();
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
            $batchSizes = [1, 3, 5, 10, 15, 20, 25, 50];
            $batchSize = $batchSizes[$i % count($batchSizes)];
            $iterations["iteration_{$i}_batch_{$batchSize}"] = [$batchSize, $i];
        }
        return $iterations;
    }

    /**
     * Property Test: Verification Accuracy - Counts Match
     * 
     * Feature: alerts-data-pipeline, Property 5: Verification Accuracy
     * Validates: Requirements 3.1
     * 
     * For any synced batch, the verification service SHALL correctly report
     * whether the MySQL source count equals the PostgreSQL target count.
     * 
     * @dataProvider batchSizeProvider
     */
    public function test_verification_correctly_reports_count_match(int $batchSize, int $iteration): void
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

        $result = $this->syncService->processBatch($mysqlAlerts);

        $this->assertTrue(
            $result->isSuccess(),
            "Sync failed on iteration {$iteration}: " . ($result->errorMessage ?? 'Unknown error')
        );

        $batchId = $result->batchId;
        $this->assertNotNull($batchId, "Batch ID should not be null after successful sync");

        // Get actual counts directly from databases
        $actualMysqlCount = Alert::where('sync_batch_id', $batchId)->count();
        $actualPgsqlCount = SyncedAlert::where('sync_batch_id', $batchId)->count();

        // Run verification
        $verificationResult = $this->verificationService->verifyBatch($batchId);

        // Property assertion: Verification service must report correct source count
        $this->assertEquals(
            $actualMysqlCount,
            $verificationResult->sourceCount,
            "Verification sourceCount ({$verificationResult->sourceCount}) doesn't match actual MySQL count ({$actualMysqlCount}) " .
            "for batch {$batchId} on iteration {$iteration}"
        );

        // Property assertion: Verification service must report correct target count
        $this->assertEquals(
            $actualPgsqlCount,
            $verificationResult->targetCount,
            "Verification targetCount ({$verificationResult->targetCount}) doesn't match actual PostgreSQL count ({$actualPgsqlCount}) " .
            "for batch {$batchId} on iteration {$iteration}"
        );

        // Property assertion: Verification must correctly determine if counts match
        $countsActuallyMatch = ($actualMysqlCount === $actualPgsqlCount);
        $this->assertEquals(
            $countsActuallyMatch,
            $verificationResult->countsMatch(),
            "Verification countsMatch() returned " . ($verificationResult->countsMatch() ? 'true' : 'false') .
            " but actual counts " . ($countsActuallyMatch ? 'do match' : 'do not match') .
            " (MySQL: {$actualMysqlCount}, PostgreSQL: {$actualPgsqlCount}) on iteration {$iteration}"
        );

        // For a successful sync, counts should match and verification should pass
        $this->assertTrue(
            $verificationResult->isVerified(),
            "Verification should pass for successfully synced batch {$batchId} on iteration {$iteration}. " .
            "Source: {$verificationResult->sourceCount}, Target: {$verificationResult->targetCount}, " .
            "Missing: " . count($verificationResult->missingIds)
        );
    }

    /**
     * Property Test: Verification detects missing records
     * 
     * Feature: alerts-data-pipeline, Property 5: Verification Accuracy
     * Validates: Requirements 3.1
     * 
     * When records are missing from PostgreSQL, verification SHALL correctly
     * identify them as missing.
     */
    public function test_verification_detects_missing_records(): void
    {
        $this->syncService->setBatchSize(10);

        // Skip if no data to sync
        if ($this->syncService->getUnsyncedCount() === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }

        // Sync a batch
        $mysqlAlerts = $this->syncService->fetchUnsyncedBatch();
        $result = $this->syncService->processBatch($mysqlAlerts);

        $this->assertTrue($result->isSuccess());
        $batchId = $result->batchId;

        // Get IDs in PostgreSQL for this batch
        $pgsqlIds = SyncedAlert::where('sync_batch_id', $batchId)->pluck('id')->toArray();
        
        if (count($pgsqlIds) < 2) {
            $this->markTestSkipped('Need at least 2 records to test missing record detection.');
        }

        // Delete some records from PostgreSQL to simulate missing records
        $idsToDelete = array_slice($pgsqlIds, 0, min(3, count($pgsqlIds)));
        SyncedAlert::whereIn('id', $idsToDelete)->delete();

        // Run verification
        $verificationResult = $this->verificationService->verifyBatch($batchId);

        // Property assertion: Verification must detect the missing records
        $this->assertFalse(
            $verificationResult->isVerified(),
            "Verification should fail when records are missing from PostgreSQL"
        );

        // Property assertion: Missing IDs should be correctly identified
        $this->assertTrue(
            $verificationResult->hasMissingRecords(),
            "Verification should report missing records"
        );

        // Property assertion: The count of missing records should match
        $this->assertEquals(
            count($idsToDelete),
            $verificationResult->getMissingCount(),
            "Missing count should match the number of deleted records"
        );

        // Property assertion: The specific missing IDs should be identified
        foreach ($idsToDelete as $deletedId) {
            $this->assertContains(
                $deletedId,
                $verificationResult->missingIds,
                "Deleted ID {$deletedId} should be in the missing IDs list"
            );
        }

        // Property assertion: Counts should reflect the discrepancy
        $mysqlCount = Alert::where('sync_batch_id', $batchId)->count();
        $pgsqlCount = SyncedAlert::where('sync_batch_id', $batchId)->count();

        $this->assertEquals($mysqlCount, $verificationResult->sourceCount);
        $this->assertEquals($pgsqlCount, $verificationResult->targetCount);
        $this->assertFalse($verificationResult->countsMatch());
    }

    /**
     * Property Test: Verification handles non-existent batch
     * 
     * Feature: alerts-data-pipeline, Property 5: Verification Accuracy
     * Validates: Requirements 3.1
     * 
     * When verifying a non-existent batch, verification SHALL return
     * a failed result with appropriate error message.
     */
    public function test_verification_handles_nonexistent_batch(): void
    {
        // Use a batch ID that doesn't exist
        $nonExistentBatchId = 999999999;

        $verificationResult = $this->verificationService->verifyBatch($nonExistentBatchId);

        // Property assertion: Verification should fail for non-existent batch
        $this->assertFalse(
            $verificationResult->isVerified(),
            "Verification should fail for non-existent batch"
        );

        // Property assertion: Should have an error message
        $this->assertNotNull(
            $verificationResult->errorMessage,
            "Should have an error message for non-existent batch"
        );

        // Property assertion: Counts should be zero
        $this->assertEquals(0, $verificationResult->sourceCount);
        $this->assertEquals(0, $verificationResult->targetCount);
    }

    /**
     * Property Test: Verification record existence check
     * 
     * Feature: alerts-data-pipeline, Property 5: Verification Accuracy
     * Validates: Requirements 3.1
     * 
     * The verifyRecordExists method SHALL correctly report whether
     * a specific record exists in PostgreSQL.
     */
    public function test_verify_record_exists_accuracy(): void
    {
        $this->syncService->setBatchSize(5);

        // Skip if no data to sync
        if ($this->syncService->getUnsyncedCount() === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }

        // Sync a batch
        $mysqlAlerts = $this->syncService->fetchUnsyncedBatch();
        $result = $this->syncService->processBatch($mysqlAlerts);

        $this->assertTrue($result->isSuccess());

        // Get synced IDs
        $syncedIds = SyncedAlert::where('sync_batch_id', $result->batchId)->pluck('id')->toArray();

        // Property assertion: All synced records should be found
        foreach ($syncedIds as $id) {
            $this->assertTrue(
                $this->verificationService->verifyRecordExists($id),
                "Record ID {$id} should exist in PostgreSQL"
            );
        }

        // Property assertion: Non-existent record should not be found
        $nonExistentId = 999999999;
        $this->assertFalse(
            $this->verificationService->verifyRecordExists($nonExistentId),
            "Non-existent record should not be found"
        );
    }

    /**
     * Property Test: Batch verification of multiple records
     * 
     * Feature: alerts-data-pipeline, Property 5: Verification Accuracy
     * Validates: Requirements 3.1
     * 
     * The verifyRecordsExist method SHALL correctly identify which
     * records from a list are missing in PostgreSQL.
     */
    public function test_verify_records_exist_batch_accuracy(): void
    {
        $this->syncService->setBatchSize(10);

        // Skip if no data to sync
        if ($this->syncService->getUnsyncedCount() === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }

        // Sync a batch
        $mysqlAlerts = $this->syncService->fetchUnsyncedBatch();
        $result = $this->syncService->processBatch($mysqlAlerts);

        $this->assertTrue($result->isSuccess());

        // Get synced IDs
        $syncedIds = SyncedAlert::where('sync_batch_id', $result->batchId)->pluck('id')->toArray();

        // Property assertion: No missing IDs when all exist
        $missingIds = $this->verificationService->verifyRecordsExist($syncedIds);
        $this->assertEmpty(
            $missingIds,
            "Should have no missing IDs when all records exist"
        );

        // Add some non-existent IDs to the list
        $nonExistentIds = [999999997, 999999998, 999999999];
        $mixedIds = array_merge($syncedIds, $nonExistentIds);

        // Property assertion: Only non-existent IDs should be reported as missing
        $missingIds = $this->verificationService->verifyRecordsExist($mixedIds);
        
        $this->assertCount(
            count($nonExistentIds),
            $missingIds,
            "Should report exactly " . count($nonExistentIds) . " missing IDs"
        );

        foreach ($nonExistentIds as $id) {
            $this->assertContains(
                $id,
                $missingIds,
                "Non-existent ID {$id} should be in missing list"
            );
        }

        // Property assertion: Empty input should return empty result
        $this->assertEmpty(
            $this->verificationService->verifyRecordsExist([]),
            "Empty input should return empty result"
        );
    }

    /**
     * Property Test: Match percentage calculation accuracy
     * 
     * Feature: alerts-data-pipeline, Property 5: Verification Accuracy
     * Validates: Requirements 3.1
     * 
     * The match percentage calculation SHALL be mathematically correct.
     */
    public function test_match_percentage_calculation_accuracy(): void
    {
        $this->syncService->setBatchSize(10);

        // Skip if no data to sync
        if ($this->syncService->getUnsyncedCount() === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }

        // Sync a batch
        $mysqlAlerts = $this->syncService->fetchUnsyncedBatch();
        $result = $this->syncService->processBatch($mysqlAlerts);

        $this->assertTrue($result->isSuccess());
        $batchId = $result->batchId;

        // Full sync - should be 100%
        $verificationResult = $this->verificationService->verifyBatch($batchId);
        $this->assertEquals(
            100.0,
            $verificationResult->getMatchPercentage(),
            "Full sync should have 100% match"
        );

        // Delete half the records from PostgreSQL
        $pgsqlIds = SyncedAlert::where('sync_batch_id', $batchId)->pluck('id')->toArray();
        $halfCount = (int) ceil(count($pgsqlIds) / 2);
        $idsToDelete = array_slice($pgsqlIds, 0, $halfCount);
        SyncedAlert::whereIn('id', $idsToDelete)->delete();

        // Re-verify
        $verificationResult = $this->verificationService->verifyBatch($batchId);

        // Calculate expected percentage
        $sourceCount = $verificationResult->sourceCount;
        $targetCount = $verificationResult->targetCount;
        $expectedPercentage = $sourceCount > 0 
            ? round(($targetCount / $sourceCount) * 100, 2) 
            : 100.0;

        $this->assertEquals(
            $expectedPercentage,
            $verificationResult->getMatchPercentage(),
            "Match percentage should be mathematically correct"
        );
    }
}
