<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\SyncBatch;
use App\Models\SyncedAlert;
use App\Models\SyncLog;
use App\Services\CleanupService;
use App\Services\SyncService;
use App\Services\VerificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Property-Based Test: Cleanup Safety Gate
 * 
 * Feature: alerts-data-pipeline, Property 6: Cleanup Safety Gate
 * Validates: Requirements 3.2, 3.3, 3.5, 4.1, 4.2
 * 
 * Property: For any record deleted from MySQL by the cleanup job, that record SHALL have been:
 * (a) successfully synced to PostgreSQL
 * (b) verified by the verification service
 * (c) older than the configured retention period
 * 
 * ⚠️ IMPORTANT: This test uses MOCK/FAKE data ONLY.
 * It creates test data in a controlled environment and cleans up after itself.
 * NEVER test cleanup on production data!
 */
class CleanupSafetyGatePropertyTest extends TestCase
{
    protected CleanupService $cleanupService;
    protected SyncService $syncService;
    protected VerificationService $verificationService;

    /**
     * Test alert IDs created during tests (for cleanup)
     */
    protected array $testAlertIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTablesExist();
        $this->clearTestData();
        
        $this->verificationService = new VerificationService();
        $this->cleanupService = new CleanupService($this->verificationService);
        $this->syncService = new SyncService();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
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
        if (!Schema::connection('mysql')->hasTable('alerts')) {
            $this->markTestSkipped('MySQL alerts table does not exist.');
        }
    }

    protected function clearTestData(): void
    {
        try {
            // Clear PostgreSQL test data
            DB::connection('pgsql')->table('sync_logs')->truncate();
            DB::connection('pgsql')->table('alerts')->truncate();
            
            // Clear MySQL sync batches
            DB::connection('mysql')->table('sync_batches')->truncate();
            
            // Reset sync markers on MySQL alerts (don't delete!)
            DB::connection('mysql')->table('alerts')
                ->whereNotNull('synced_at')
                ->update(['synced_at' => null, 'sync_batch_id' => null]);
        } catch (\Exception $e) {
            // Tables may not exist yet
        }
    }

    protected function cleanupTestData(): void
    {
        try {
            // Clean up any test alerts we created
            if (!empty($this->testAlertIds)) {
                DB::connection('mysql')->table('alerts')
                    ->whereIn('id', $this->testAlertIds)
                    ->delete();
            }
            
            // Clear PostgreSQL data
            DB::connection('pgsql')->table('alerts')->truncate();
            DB::connection('pgsql')->table('sync_logs')->truncate();
            
            // Clear sync batches
            DB::connection('mysql')->table('sync_batches')->truncate();
            
            // Reset sync markers
            DB::connection('mysql')->table('alerts')
                ->whereNotNull('synced_at')
                ->update(['synced_at' => null, 'sync_batch_id' => null]);
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }

    /**
     * Create a test alert in MySQL for testing purposes
     * These are temporary test records that will be cleaned up after tests
     * 
     * MySQL column constraints:
     * - panelid: varchar(10)
     * - seqno: varchar(100)
     * - zone: varchar(3)
     * - alarm: varchar(3)
     * - status: char(1)
     * - location: char(1)
     * - priority: char(1)
     */
    protected function createTestAlert(array $overrides = []): Alert
    {
        $data = array_merge([
            'panelid' => (string) rand(100, 999),      // varchar(10) - use 3 digits
            'seqno' => (string) rand(1, 9999),         // varchar(100)
            'zone' => (string) rand(1, 99),            // varchar(3) - max 2 digits
            'alarm' => (string) rand(1, 99),           // varchar(3) - max 2 digits
            'createtime' => now()->subDays(rand(1, 30)),
            'receivedtime' => now(),
            'comment' => 'Test alert for cleanup safety gate',
            'status' => 'O',                           // char(1) - 'O' for Open
            'alerttype' => 'TEST',                     // varchar(50)
            'location' => 'T',                         // char(1)
            'priority' => 'M',                         // char(1) - M for Medium
            'level' => rand(1, 5),                     // int(5)
        ], $overrides);

        $alert = Alert::create($data);
        $this->testAlertIds[] = $alert->id;
        
        return $alert;
    }

    /**
     * Create multiple test alerts
     */
    protected function createTestAlerts(int $count, array $overrides = []): array
    {
        $alerts = [];
        for ($i = 0; $i < $count; $i++) {
            $alerts[] = $this->createTestAlert($overrides);
        }
        return $alerts;
    }

    /**
     * Create a verified sync batch with test data
     */
    protected function createVerifiedBatch(int $alertCount = 5, int $daysAgo = 10): SyncBatch
    {
        // Create test alerts
        $alerts = $this->createTestAlerts($alertCount);
        $alertIds = array_map(fn($a) => $a->id, $alerts);
        
        // Create sync batch
        $batch = SyncBatch::create([
            'start_id' => min($alertIds),
            'end_id' => max($alertIds),
            'records_count' => count($alerts),
            'status' => SyncBatch::STATUS_VERIFIED,
            'started_at' => now()->subDays($daysAgo),
            'completed_at' => now()->subDays($daysAgo),
            'verified_at' => now()->subDays($daysAgo),
        ]);

        // Mark alerts as synced
        Alert::whereIn('id', $alertIds)->update([
            'synced_at' => now()->subDays($daysAgo),
            'sync_batch_id' => $batch->id,
        ]);

        // Copy alerts to PostgreSQL
        foreach ($alerts as $alert) {
            $alert->refresh();
            SyncedAlert::create([
                'id' => $alert->id,
                'panelid' => $alert->panelid,
                'seqno' => $alert->seqno,
                'zone' => $alert->zone,
                'alarm' => $alert->alarm,
                'createtime' => $alert->createtime,
                'receivedtime' => $alert->receivedtime,
                'comment' => $alert->comment,
                'status' => $alert->status,
                'alerttype' => $alert->alerttype,
                'location' => $alert->location,
                'priority' => $alert->priority,
                'level' => $alert->level,
                'synced_at' => $alert->synced_at,
                'sync_batch_id' => $batch->id,
            ]);
        }

        return $batch;
    }

    /**
     * Data provider for property-based testing
     * Generates 10 iterations with varying configurations
     */
    public static function cleanupConfigProvider(): array
    {
        $iterations = [];
        for ($i = 1; $i <= 10; $i++) {
            $alertCounts = [1, 2, 3, 5, 10];
            $retentionDays = [1, 3, 5, 7, 14];
            $daysAgo = [8, 10, 15, 20, 30];
            
            $iterations["iteration_{$i}"] = [
                $alertCounts[$i % count($alertCounts)],
                $retentionDays[$i % count($retentionDays)],
                $daysAgo[$i % count($daysAgo)],
                $i
            ];
        }
        return $iterations;
    }

    /**
     * Property Test: Cleanup requires admin confirmation
     * 
     * Feature: alerts-data-pipeline, Property 6: Cleanup Safety Gate
     * Validates: Requirements 4.1
     * 
     * Cleanup SHALL NOT proceed without explicit admin confirmation.
     * 
     * @dataProvider cleanupConfigProvider
     */
    public function test_cleanup_blocked_without_admin_confirmation(
        int $alertCount,
        int $retentionDays,
        int $daysAgo,
        int $iteration
    ): void {
        // Create a verified batch eligible for cleanup
        $batch = $this->createVerifiedBatch($alertCount, max($daysAgo, $retentionDays + 1));
        
        // Configure cleanup service WITHOUT admin confirmation
        $this->cleanupService->setRetentionDays($retentionDays);
        // Note: NOT calling setAdminConfirmation(true)
        
        $initialCount = Alert::where('sync_batch_id', $batch->id)->count();
        
        // Attempt cleanup
        $result = $this->cleanupService->cleanupBatch($batch->id);
        
        // Property assertion: Cleanup should be blocked
        $this->assertFalse(
            $result->isSuccess(),
            "Cleanup should fail without admin confirmation on iteration {$iteration}"
        );
        
        $this->assertFalse(
            $result->adminConfirmed,
            "Result should indicate admin was not confirmed on iteration {$iteration}"
        );
        
        // Property assertion: No records should be deleted
        $finalCount = Alert::where('sync_batch_id', $batch->id)->count();
        $this->assertEquals(
            $initialCount,
            $finalCount,
            "No records should be deleted without admin confirmation on iteration {$iteration}"
        );
    }

    /**
     * Property Test: Cleanup requires verified batch status
     * 
     * Feature: alerts-data-pipeline, Property 6: Cleanup Safety Gate
     * Validates: Requirements 3.2, 3.5
     * 
     * Cleanup SHALL NOT proceed for batches that are not verified.
     */
    public function test_cleanup_blocked_for_unverified_batch(): void
    {
        // Create test alerts
        $alerts = $this->createTestAlerts(5);
        $alertIds = array_map(fn($a) => $a->id, $alerts);
        
        // Create batch with COMPLETED status (not verified)
        $batch = SyncBatch::create([
            'start_id' => min($alertIds),
            'end_id' => max($alertIds),
            'records_count' => count($alerts),
            'status' => SyncBatch::STATUS_COMPLETED, // Not verified!
            'started_at' => now()->subDays(10),
            'completed_at' => now()->subDays(10),
        ]);

        // Mark alerts as synced
        Alert::whereIn('id', $alertIds)->update([
            'synced_at' => now()->subDays(10),
            'sync_batch_id' => $batch->id,
        ]);

        // Copy to PostgreSQL
        foreach ($alerts as $alert) {
            $alert->refresh();
            SyncedAlert::create([
                'id' => $alert->id,
                'panelid' => $alert->panelid,
                'seqno' => $alert->seqno,
                'zone' => $alert->zone,
                'alarm' => $alert->alarm,
                'createtime' => $alert->createtime,
                'receivedtime' => $alert->receivedtime,
                'comment' => $alert->comment,
                'status' => $alert->status,
                'alerttype' => $alert->alerttype,
                'location' => $alert->location,
                'priority' => $alert->priority,
                'level' => $alert->level,
                'synced_at' => $alert->synced_at,
                'sync_batch_id' => $batch->id,
            ]);
        }

        // Configure cleanup with admin confirmation
        $this->cleanupService->setAdminConfirmation(true);
        $this->cleanupService->setRetentionDays(1);
        
        $initialCount = Alert::where('sync_batch_id', $batch->id)->count();
        
        // Attempt cleanup
        $result = $this->cleanupService->cleanupBatch($batch->id);
        
        // Property assertion: Cleanup should be blocked
        $this->assertFalse(
            $result->isSuccess(),
            "Cleanup should fail for unverified batch"
        );
        
        // Property assertion: Error should mention verification
        $this->assertNotEmpty($result->errors);
        $errorMessage = implode(' ', $result->errors);
        $this->assertStringContainsString(
            'verified',
            strtolower($errorMessage),
            "Error should mention verification status"
        );
        
        // Property assertion: No records should be deleted
        $finalCount = Alert::where('sync_batch_id', $batch->id)->count();
        $this->assertEquals(
            $initialCount,
            $finalCount,
            "No records should be deleted for unverified batch"
        );
    }

    /**
     * Property Test: Cleanup requires records to exist in PostgreSQL
     * 
     * Feature: alerts-data-pipeline, Property 6: Cleanup Safety Gate
     * Validates: Requirements 3.2, 3.3
     * 
     * Cleanup SHALL NOT proceed if records are missing from PostgreSQL.
     */
    public function test_cleanup_blocked_when_records_missing_from_postgresql(): void
    {
        // Create a verified batch
        $batch = $this->createVerifiedBatch(5, 10);
        
        // Delete some records from PostgreSQL to simulate missing data
        $pgsqlIds = SyncedAlert::where('sync_batch_id', $batch->id)->pluck('id')->toArray();
        $idsToDelete = array_slice($pgsqlIds, 0, 2);
        SyncedAlert::whereIn('id', $idsToDelete)->delete();
        
        // Configure cleanup with admin confirmation
        $this->cleanupService->setAdminConfirmation(true);
        $this->cleanupService->setRetentionDays(1);
        
        $initialCount = Alert::where('sync_batch_id', $batch->id)->count();
        
        // Attempt cleanup
        $result = $this->cleanupService->cleanupBatch($batch->id);
        
        // Property assertion: Cleanup should be blocked
        $this->assertFalse(
            $result->isSuccess(),
            "Cleanup should fail when records are missing from PostgreSQL"
        );
        
        // Property assertion: No records should be deleted
        $finalCount = Alert::where('sync_batch_id', $batch->id)->count();
        $this->assertEquals(
            $initialCount,
            $finalCount,
            "No records should be deleted when PostgreSQL records are missing"
        );
    }

    /**
     * Property Test: Cleanup respects retention period
     * 
     * Feature: alerts-data-pipeline, Property 6: Cleanup Safety Gate
     * Validates: Requirements 4.2
     * 
     * Cleanup SHALL NOT proceed for batches within the retention period.
     */
    public function test_cleanup_blocked_within_retention_period(): void
    {
        // Create a verified batch that was verified recently (within retention)
        $alerts = $this->createTestAlerts(5);
        $alertIds = array_map(fn($a) => $a->id, $alerts);
        
        // Create batch verified only 2 days ago
        $batch = SyncBatch::create([
            'start_id' => min($alertIds),
            'end_id' => max($alertIds),
            'records_count' => count($alerts),
            'status' => SyncBatch::STATUS_VERIFIED,
            'started_at' => now()->subDays(2),
            'completed_at' => now()->subDays(2),
            'verified_at' => now()->subDays(2), // Only 2 days ago
        ]);

        // Mark alerts as synced
        Alert::whereIn('id', $alertIds)->update([
            'synced_at' => now()->subDays(2),
            'sync_batch_id' => $batch->id,
        ]);

        // Copy to PostgreSQL
        foreach ($alerts as $alert) {
            $alert->refresh();
            SyncedAlert::create([
                'id' => $alert->id,
                'panelid' => $alert->panelid,
                'seqno' => $alert->seqno,
                'zone' => $alert->zone,
                'alarm' => $alert->alarm,
                'createtime' => $alert->createtime,
                'receivedtime' => $alert->receivedtime,
                'comment' => $alert->comment,
                'status' => $alert->status,
                'alerttype' => $alert->alerttype,
                'location' => $alert->location,
                'priority' => $alert->priority,
                'level' => $alert->level,
                'synced_at' => $alert->synced_at,
                'sync_batch_id' => $batch->id,
            ]);
        }

        // Configure cleanup with 7-day retention
        $this->cleanupService->setAdminConfirmation(true);
        $this->cleanupService->setRetentionDays(7);
        
        $initialCount = Alert::where('sync_batch_id', $batch->id)->count();
        
        // Attempt cleanup
        $result = $this->cleanupService->cleanupBatch($batch->id);
        
        // Property assertion: Cleanup should be blocked
        $this->assertFalse(
            $result->isSuccess(),
            "Cleanup should fail for batch within retention period"
        );
        
        // Property assertion: Error should mention retention
        $this->assertNotEmpty($result->errors);
        $errorMessage = implode(' ', $result->errors);
        $this->assertStringContainsString(
            'retention',
            strtolower($errorMessage),
            "Error should mention retention period"
        );
        
        // Property assertion: No records should be deleted
        $finalCount = Alert::where('sync_batch_id', $batch->id)->count();
        $this->assertEquals(
            $initialCount,
            $finalCount,
            "No records should be deleted within retention period"
        );
    }

    /**
     * Property Test: Successful cleanup only when all conditions met
     * 
     * Feature: alerts-data-pipeline, Property 6: Cleanup Safety Gate
     * Validates: Requirements 3.2, 3.3, 3.5, 4.1, 4.2
     * 
     * Cleanup SHALL succeed only when ALL conditions are met:
     * (a) Admin confirmation provided
     * (b) Batch is verified
     * (c) All records exist in PostgreSQL
     * (d) Retention period has passed
     * 
     * @dataProvider cleanupConfigProvider
     */
    public function test_cleanup_succeeds_when_all_conditions_met(
        int $alertCount,
        int $retentionDays,
        int $daysAgo,
        int $iteration
    ): void {
        // Ensure daysAgo is greater than retention period
        $actualDaysAgo = max($daysAgo, $retentionDays + 1);
        
        // Create a verified batch that meets all conditions
        $batch = $this->createVerifiedBatch($alertCount, $actualDaysAgo);
        
        // Configure cleanup with all conditions met
        $this->cleanupService->setAdminConfirmation(true);
        $this->cleanupService->setRetentionDays($retentionDays);
        
        $initialMysqlCount = Alert::where('sync_batch_id', $batch->id)->count();
        $initialPgsqlCount = SyncedAlert::where('sync_batch_id', $batch->id)->count();
        
        // Verify preconditions
        $this->assertEquals(
            $initialMysqlCount,
            $initialPgsqlCount,
            "MySQL and PostgreSQL should have same count before cleanup on iteration {$iteration}"
        );
        
        // Perform cleanup
        $result = $this->cleanupService->cleanupBatch($batch->id);
        
        // Property assertion: Cleanup should succeed
        $this->assertTrue(
            $result->isSuccess(),
            "Cleanup should succeed when all conditions are met on iteration {$iteration}. " .
            "Error: " . ($result->errorMessage ?? 'none')
        );
        
        // Property assertion: Records should be deleted from MySQL
        $finalMysqlCount = Alert::where('sync_batch_id', $batch->id)->count();
        $this->assertEquals(
            0,
            $finalMysqlCount,
            "All MySQL records should be deleted on iteration {$iteration}"
        );
        
        // Property assertion: Records should still exist in PostgreSQL
        $finalPgsqlCount = SyncedAlert::where('sync_batch_id', $batch->id)->count();
        $this->assertEquals(
            $initialPgsqlCount,
            $finalPgsqlCount,
            "PostgreSQL records should remain intact on iteration {$iteration}"
        );
        
        // Property assertion: Deleted count should match initial count
        $this->assertEquals(
            $initialMysqlCount,
            $result->recordsDeleted,
            "Deleted count should match initial MySQL count on iteration {$iteration}"
        );
        
        // Property assertion: Batch should be marked as cleaned
        $batch->refresh();
        $this->assertEquals(
            SyncBatch::STATUS_CLEANED,
            $batch->status,
            "Batch should be marked as cleaned on iteration {$iteration}"
        );
    }

    /**
     * Property Test: Triple verification before cleanup
     * 
     * Feature: alerts-data-pipeline, Property 6: Cleanup Safety Gate
     * Validates: Requirements 3.2, 3.3
     * 
     * The tripleCheckVerification method SHALL verify:
     * 1. Batch status is 'verified'
     * 2. Record counts match between MySQL and PostgreSQL
     * 3. All record IDs exist in PostgreSQL
     */
    public function test_triple_check_verification_accuracy(): void
    {
        // Create a verified batch
        $batch = $this->createVerifiedBatch(5, 10);
        
        // Test 1: Valid batch should pass triple check
        $result = $this->cleanupService->tripleCheckVerification($batch->id);
        $this->assertTrue(
            $result['verified'],
            "Valid verified batch should pass triple check"
        );
        
        // Test 2: Non-existent batch should fail
        $result = $this->cleanupService->tripleCheckVerification(999999999);
        $this->assertFalse(
            $result['verified'],
            "Non-existent batch should fail triple check"
        );
        $this->assertStringContainsString('not found', strtolower($result['reason']));
        
        // Test 3: Unverified batch should fail
        $batch->update(['status' => SyncBatch::STATUS_COMPLETED]);
        $result = $this->cleanupService->tripleCheckVerification($batch->id);
        $this->assertFalse(
            $result['verified'],
            "Unverified batch should fail triple check"
        );
        $this->assertStringContainsString('verified', strtolower($result['reason']));
        
        // Restore verified status
        $batch->update(['status' => SyncBatch::STATUS_VERIFIED]);
        
        // Test 4: Missing PostgreSQL records should fail
        $pgsqlIds = SyncedAlert::where('sync_batch_id', $batch->id)->pluck('id')->toArray();
        SyncedAlert::whereIn('id', array_slice($pgsqlIds, 0, 2))->delete();
        
        $result = $this->cleanupService->tripleCheckVerification($batch->id);
        $this->assertFalse(
            $result['verified'],
            "Batch with missing PostgreSQL records should fail triple check"
        );
    }

    /**
     * Property Test: Cleanup preview accuracy
     * 
     * Feature: alerts-data-pipeline, Property 6: Cleanup Safety Gate
     * Validates: Requirements 4.1
     * 
     * The preview method SHALL accurately report what would be cleaned.
     */
    public function test_cleanup_preview_accuracy(): void
    {
        // Create multiple verified batches
        $batch1 = $this->createVerifiedBatch(3, 15);
        $batch2 = $this->createVerifiedBatch(5, 20);
        
        // Create a batch within retention (should not be in preview)
        $alerts = $this->createTestAlerts(2);
        $alertIds = array_map(fn($a) => $a->id, $alerts);
        $recentBatch = SyncBatch::create([
            'start_id' => min($alertIds),
            'end_id' => max($alertIds),
            'records_count' => count($alerts),
            'status' => SyncBatch::STATUS_VERIFIED,
            'started_at' => now()->subDays(2),
            'completed_at' => now()->subDays(2),
            'verified_at' => now()->subDays(2),
        ]);
        Alert::whereIn('id', $alertIds)->update([
            'synced_at' => now()->subDays(2),
            'sync_batch_id' => $recentBatch->id,
        ]);
        
        // Configure retention
        $this->cleanupService->setRetentionDays(7);
        
        // Get preview
        $preview = $this->cleanupService->previewCleanup();
        
        // Property assertion: Preview should show correct batch count
        $this->assertEquals(
            2,
            $preview['eligible_batches'],
            "Preview should show 2 eligible batches (excluding recent one)"
        );
        
        // Property assertion: Preview should show correct record count
        $expectedRecords = Alert::where('sync_batch_id', $batch1->id)->count() +
                          Alert::where('sync_batch_id', $batch2->id)->count();
        $this->assertEquals(
            $expectedRecords,
            $preview['eligible_records'],
            "Preview should show correct total eligible records"
        );
        
        // Property assertion: Preview should show retention days
        $this->assertEquals(
            7,
            $preview['retention_days'],
            "Preview should show configured retention days"
        );
    }

    /**
     * Property Test: Cleanup logs all operations
     * 
     * Feature: alerts-data-pipeline, Property 6: Cleanup Safety Gate
     * Validates: Requirements 4.1
     * 
     * All cleanup operations SHALL be logged.
     */
    public function test_cleanup_operations_are_logged(): void
    {
        // Create a verified batch
        $batch = $this->createVerifiedBatch(5, 10);
        
        // Configure cleanup
        $this->cleanupService->setAdminConfirmation(true);
        $this->cleanupService->setRetentionDays(1);
        
        $initialLogCount = SyncLog::where('operation', 'cleanup')->count();
        
        // Perform cleanup
        $result = $this->cleanupService->cleanupBatch($batch->id);
        
        $this->assertTrue($result->isSuccess());
        
        // Property assertion: Cleanup should be logged
        $finalLogCount = SyncLog::where('operation', 'cleanup')->count();
        $this->assertGreaterThan(
            $initialLogCount,
            $finalLogCount,
            "Cleanup operation should be logged"
        );
        
        // Property assertion: Log should contain correct batch ID
        $log = SyncLog::where('operation', 'cleanup')
            ->where('batch_id', $batch->id)
            ->first();
        
        $this->assertNotNull($log, "Log entry should exist for batch");
        $this->assertEquals('success', $log->status, "Log should show success status");
        $this->assertGreaterThan(0, $log->records_affected, "Log should show records affected");
    }

    /**
     * Property Test: canProceedWithCleanup accuracy
     * 
     * Feature: alerts-data-pipeline, Property 6: Cleanup Safety Gate
     * Validates: Requirements 4.1
     * 
     * The canProceedWithCleanup method SHALL accurately report readiness.
     */
    public function test_can_proceed_with_cleanup_accuracy(): void
    {
        // Test without admin confirmation
        $this->cleanupService->setAdminConfirmation(false);
        $result = $this->cleanupService->canProceedWithCleanup();
        
        $this->assertFalse(
            $result['can_proceed'],
            "Should not proceed without admin confirmation"
        );
        $this->assertContains(
            'Admin confirmation not received',
            $result['reasons'],
            "Should list admin confirmation as reason"
        );
        
        // Test with admin confirmation but no eligible batches
        $this->cleanupService->setAdminConfirmation(true);
        $result = $this->cleanupService->canProceedWithCleanup();
        
        // May or may not proceed depending on eligible batches
        if (!$result['can_proceed']) {
            $this->assertContains(
                'No eligible batches for cleanup',
                $result['reasons'],
                "Should list no eligible batches as reason"
            );
        }
        
        // Create eligible batch and test again
        $batch = $this->createVerifiedBatch(3, 10);
        $this->cleanupService->setRetentionDays(1);
        
        $result = $this->cleanupService->canProceedWithCleanup();
        $this->assertTrue(
            $result['can_proceed'],
            "Should proceed when all conditions are met"
        );
        $this->assertEmpty(
            $result['reasons'],
            "Should have no blocking reasons"
        );
    }
}
