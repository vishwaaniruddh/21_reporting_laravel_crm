<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\CleanupLog;
use App\Models\CleanupBatch;
use App\Models\EmergencyStop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Unit tests for Cleanup models (CleanupLog, CleanupBatch, EmergencyStop)
 * 
 * Tests verify:
 * - CleanupLog creation and field validation
 * - CleanupBatch relationship to CleanupLog
 * - EmergencyStop flag persistence
 * 
 * Requirements: 5.1, 11.5
 */
class CleanupModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure we're using the PostgreSQL connection for these models
        config(['database.default' => 'pgsql']);
        
        // SAFE: Only run cleanup-specific migrations if tables don't exist
        // This will NOT drop or affect any existing tables
        if (!Schema::connection('pgsql')->hasTable('cleanup_logs')) {
            $this->artisan('migrate', [
                '--database' => 'pgsql',
                '--path' => 'database/migrations/postgresql',
            ])->run();
        }
        
        // Clean up test data from previous runs (safe - only affects our test tables)
        DB::connection('pgsql')->table('cleanup_batches')->truncate();
        DB::connection('pgsql')->table('cleanup_logs')->truncate();
        DB::connection('pgsql')->table('emergency_stops')->truncate();
    }

    /**
     * Test: CleanupLog can be created with all required fields
     */
    public function test_cleanup_log_creation_with_required_fields(): void
    {
        $log = CleanupLog::create([
            'operation_type' => CleanupLog::OPERATION_AGE_BASED,
            'status' => CleanupLog::STATUS_STARTED,
            'age_threshold_hours' => 48,
            'batch_size' => 100,
            'started_at' => now(),
            'triggered_by' => 'scheduler',
        ]);

        $this->assertDatabaseHas('cleanup_logs', [
            'id' => $log->id,
            'operation_type' => 'age_based_cleanup',
            'status' => 'started',
            'age_threshold_hours' => 48,
            'batch_size' => 100,
            'triggered_by' => 'scheduler',
        ]);
    }

    /**
     * Test: CleanupLog fields are properly cast
     */
    public function test_cleanup_log_field_casting(): void
    {
        $log = CleanupLog::create([
            'operation_type' => CleanupLog::OPERATION_DRY_RUN,
            'status' => CleanupLog::STATUS_STARTED,
            'age_threshold_hours' => 72,
            'batch_size' => 200,
            'batches_processed' => 5,
            'records_deleted' => 500,
            'records_skipped' => 10,
            'configuration' => ['target_table' => 'alerts_2', 'delay_ms' => 100],
            'started_at' => now(),
            'triggered_by' => 'admin',
        ]);

        $this->assertIsInt($log->age_threshold_hours);
        $this->assertIsInt($log->batch_size);
        $this->assertIsInt($log->batches_processed);
        $this->assertIsInt($log->records_deleted);
        $this->assertIsInt($log->records_skipped);
        $this->assertIsArray($log->configuration);
        $this->assertInstanceOf(\Carbon\Carbon::class, $log->started_at);
    }

    /**
     * Test: CleanupLog markCompleted helper method
     */
    public function test_cleanup_log_mark_completed(): void
    {
        $log = CleanupLog::create([
            'operation_type' => CleanupLog::OPERATION_AGE_BASED,
            'status' => CleanupLog::STATUS_STARTED,
            'age_threshold_hours' => 48,
            'batch_size' => 100,
            'started_at' => now()->subMinutes(10),
            'triggered_by' => 'scheduler',
        ]);

        $result = $log->markCompleted(500, 10);

        $this->assertTrue($result);
        $this->assertEquals(CleanupLog::STATUS_COMPLETED, $log->fresh()->status);
        $this->assertEquals(500, $log->fresh()->records_deleted);
        $this->assertEquals(10, $log->fresh()->records_skipped);
        $this->assertNotNull($log->fresh()->completed_at);
        $this->assertNotNull($log->fresh()->duration_ms);
    }

    /**
     * Test: CleanupLog markFailed helper method
     */
    public function test_cleanup_log_mark_failed(): void
    {
        $log = CleanupLog::create([
            'operation_type' => CleanupLog::OPERATION_MANUAL,
            'status' => CleanupLog::STATUS_STARTED,
            'age_threshold_hours' => 48,
            'batch_size' => 100,
            'started_at' => now()->subMinutes(5),
            'triggered_by' => 'admin',
        ]);

        $result = $log->markFailed('Database connection lost');

        $this->assertTrue($result);
        $this->assertEquals(CleanupLog::STATUS_FAILED, $log->fresh()->status);
        $this->assertEquals('Database connection lost', $log->fresh()->error_message);
        $this->assertNotNull($log->fresh()->completed_at);
        $this->assertNotNull($log->fresh()->duration_ms);
    }

    /**
     * Test: CleanupLog markStopped helper method
     */
    public function test_cleanup_log_mark_stopped(): void
    {
        $log = CleanupLog::create([
            'operation_type' => CleanupLog::OPERATION_AGE_BASED,
            'status' => CleanupLog::STATUS_STARTED,
            'age_threshold_hours' => 48,
            'batch_size' => 100,
            'started_at' => now()->subMinutes(3),
            'triggered_by' => 'scheduler',
        ]);

        $result = $log->markStopped('Emergency stop activated');

        $this->assertTrue($result);
        $this->assertEquals(CleanupLog::STATUS_STOPPED, $log->fresh()->status);
        $this->assertEquals('Emergency stop activated', $log->fresh()->error_message);
        $this->assertNotNull($log->fresh()->completed_at);
    }

    /**
     * Test: CleanupLog recent scope filters by days
     */
    public function test_cleanup_log_recent_scope(): void
    {
        // Create old log (100 days ago)
        CleanupLog::create([
            'operation_type' => CleanupLog::OPERATION_AGE_BASED,
            'status' => CleanupLog::STATUS_COMPLETED,
            'age_threshold_hours' => 48,
            'batch_size' => 100,
            'started_at' => now()->subDays(100),
            'triggered_by' => 'scheduler',
        ]);

        // Create recent log (30 days ago)
        CleanupLog::create([
            'operation_type' => CleanupLog::OPERATION_AGE_BASED,
            'status' => CleanupLog::STATUS_COMPLETED,
            'age_threshold_hours' => 48,
            'batch_size' => 100,
            'started_at' => now()->subDays(30),
            'triggered_by' => 'scheduler',
        ]);

        $recentLogs = CleanupLog::recent(90)->get();

        $this->assertCount(1, $recentLogs);
    }

    /**
     * Test: CleanupLog successful scope filters completed logs
     */
    public function test_cleanup_log_successful_scope(): void
    {
        CleanupLog::create([
            'operation_type' => CleanupLog::OPERATION_AGE_BASED,
            'status' => CleanupLog::STATUS_COMPLETED,
            'age_threshold_hours' => 48,
            'batch_size' => 100,
            'started_at' => now(),
            'triggered_by' => 'scheduler',
        ]);

        CleanupLog::create([
            'operation_type' => CleanupLog::OPERATION_AGE_BASED,
            'status' => CleanupLog::STATUS_FAILED,
            'age_threshold_hours' => 48,
            'batch_size' => 100,
            'started_at' => now(),
            'triggered_by' => 'scheduler',
        ]);

        $successfulLogs = CleanupLog::successful()->get();

        $this->assertCount(1, $successfulLogs);
        $this->assertEquals(CleanupLog::STATUS_COMPLETED, $successfulLogs->first()->status);
    }

    /**
     * Test: CleanupLog failed scope filters failed logs
     */
    public function test_cleanup_log_failed_scope(): void
    {
        CleanupLog::create([
            'operation_type' => CleanupLog::OPERATION_AGE_BASED,
            'status' => CleanupLog::STATUS_COMPLETED,
            'age_threshold_hours' => 48,
            'batch_size' => 100,
            'started_at' => now(),
            'triggered_by' => 'scheduler',
        ]);

        CleanupLog::create([
            'operation_type' => CleanupLog::OPERATION_AGE_BASED,
            'status' => CleanupLog::STATUS_FAILED,
            'age_threshold_hours' => 48,
            'batch_size' => 100,
            'started_at' => now(),
            'triggered_by' => 'scheduler',
        ]);

        $failedLogs = CleanupLog::failed()->get();

        $this->assertCount(1, $failedLogs);
        $this->assertEquals(CleanupLog::STATUS_FAILED, $failedLogs->first()->status);
    }

    /**
     * Test: CleanupBatch can be created with relationship to CleanupLog
     */
    public function test_cleanup_batch_creation_with_relationship(): void
    {
        $log = CleanupLog::create([
            'operation_type' => CleanupLog::OPERATION_AGE_BASED,
            'status' => CleanupLog::STATUS_STARTED,
            'age_threshold_hours' => 48,
            'batch_size' => 100,
            'started_at' => now(),
            'triggered_by' => 'scheduler',
        ]);

        $batch = CleanupBatch::create([
            'cleanup_log_id' => $log->id,
            'batch_number' => 1,
            'records_identified' => 100,
            'records_verified' => 95,
            'records_deleted' => 95,
            'records_skipped' => 5,
            'skipped_record_ids' => [101, 102, 103, 104, 105],
            'skip_reason' => 'Not found in PostgreSQL',
            'processed_at' => now(),
            'duration_ms' => 500,
        ]);

        $this->assertDatabaseHas('cleanup_batches', [
            'id' => $batch->id,
            'cleanup_log_id' => $log->id,
            'batch_number' => 1,
            'records_deleted' => 95,
        ]);

        // Test relationship
        $this->assertEquals($log->id, $batch->cleanupLog->id);
        $this->assertTrue($log->batches->contains($batch));
    }

    /**
     * Test: CleanupBatch fields are properly cast
     */
    public function test_cleanup_batch_field_casting(): void
    {
        $log = CleanupLog::create([
            'operation_type' => CleanupLog::OPERATION_AGE_BASED,
            'status' => CleanupLog::STATUS_STARTED,
            'age_threshold_hours' => 48,
            'batch_size' => 100,
            'started_at' => now(),
            'triggered_by' => 'scheduler',
        ]);

        $batch = CleanupBatch::create([
            'cleanup_log_id' => $log->id,
            'batch_number' => 1,
            'records_identified' => 100,
            'records_verified' => 95,
            'records_deleted' => 95,
            'records_skipped' => 5,
            'skipped_record_ids' => [101, 102, 103],
            'skip_reason' => 'Not found in PostgreSQL',
            'processed_at' => now(),
            'duration_ms' => 500,
        ]);

        $this->assertIsInt($batch->cleanup_log_id);
        $this->assertIsInt($batch->batch_number);
        $this->assertIsInt($batch->records_identified);
        $this->assertIsInt($batch->records_verified);
        $this->assertIsInt($batch->records_deleted);
        $this->assertIsInt($batch->records_skipped);
        $this->assertIsArray($batch->skipped_record_ids);
        $this->assertIsInt($batch->duration_ms);
        $this->assertInstanceOf(\Carbon\Carbon::class, $batch->processed_at);
    }

    /**
     * Test: CleanupBatch hasSkippedRecords method
     */
    public function test_cleanup_batch_has_skipped_records(): void
    {
        $log = CleanupLog::create([
            'operation_type' => CleanupLog::OPERATION_AGE_BASED,
            'status' => CleanupLog::STATUS_STARTED,
            'age_threshold_hours' => 48,
            'batch_size' => 100,
            'started_at' => now(),
            'triggered_by' => 'scheduler',
        ]);

        $batchWithSkipped = CleanupBatch::create([
            'cleanup_log_id' => $log->id,
            'batch_number' => 1,
            'records_identified' => 100,
            'records_verified' => 95,
            'records_deleted' => 95,
            'records_skipped' => 5,
            'processed_at' => now(),
            'duration_ms' => 500,
        ]);

        $batchWithoutSkipped = CleanupBatch::create([
            'cleanup_log_id' => $log->id,
            'batch_number' => 2,
            'records_identified' => 100,
            'records_verified' => 100,
            'records_deleted' => 100,
            'records_skipped' => 0,
            'processed_at' => now(),
            'duration_ms' => 450,
        ]);

        $this->assertTrue($batchWithSkipped->hasSkippedRecords());
        $this->assertFalse($batchWithoutSkipped->hasSkippedRecords());
    }

    /**
     * Test: EmergencyStop can be created and persisted
     */
    public function test_emergency_stop_creation(): void
    {
        $stop = EmergencyStop::create([
            'service_name' => EmergencyStop::SERVICE_AGE_BASED_CLEANUP,
            'is_stopped' => false,
        ]);

        $this->assertDatabaseHas('emergency_stops', [
            'id' => $stop->id,
            'service_name' => 'age_based_cleanup',
            'is_stopped' => false,
        ]);
    }

    /**
     * Test: EmergencyStop flag persistence
     */
    public function test_emergency_stop_flag_persistence(): void
    {
        // Create and activate emergency stop
        $stop = EmergencyStop::create([
            'service_name' => EmergencyStop::SERVICE_AGE_BASED_CLEANUP,
            'is_stopped' => false,
        ]);

        $stop->activate('Testing emergency stop', 'admin');

        // Verify persistence by fetching fresh from database
        $freshStop = EmergencyStop::find($stop->id);
        
        $this->assertTrue($freshStop->is_stopped);
        $this->assertEquals('Testing emergency stop', $freshStop->reason);
        $this->assertEquals('admin', $freshStop->stopped_by);
        $this->assertNotNull($freshStop->stopped_at);
        $this->assertNull($freshStop->cleared_at);
    }

    /**
     * Test: EmergencyStop can be deactivated
     */
    public function test_emergency_stop_deactivation(): void
    {
        $stop = EmergencyStop::create([
            'service_name' => EmergencyStop::SERVICE_AGE_BASED_CLEANUP,
            'is_stopped' => true,
            'reason' => 'Test stop',
            'stopped_by' => 'admin',
            'stopped_at' => now(),
        ]);

        $stop->deactivate();

        $freshStop = EmergencyStop::find($stop->id);
        
        $this->assertFalse($freshStop->is_stopped);
        $this->assertNull($freshStop->reason);
        $this->assertNull($freshStop->stopped_by);
        $this->assertNotNull($freshStop->cleared_at);
    }

    /**
     * Test: EmergencyStop static isStopped method
     */
    public function test_emergency_stop_is_stopped_static_method(): void
    {
        // No record exists - should return false
        $this->assertFalse(EmergencyStop::isStopped('age_based_cleanup'));

        // Create stopped record
        EmergencyStop::create([
            'service_name' => 'age_based_cleanup',
            'is_stopped' => true,
            'reason' => 'Test',
            'stopped_by' => 'admin',
            'stopped_at' => now(),
        ]);

        $this->assertTrue(EmergencyStop::isStopped('age_based_cleanup'));
    }

    /**
     * Test: EmergencyStop static setStop method
     */
    public function test_emergency_stop_set_stop_static_method(): void
    {
        $result = EmergencyStop::setStop('age_based_cleanup', 'Critical error detected', 'system');

        $this->assertTrue($result);
        $this->assertTrue(EmergencyStop::isStopped('age_based_cleanup'));

        $stop = EmergencyStop::where('service_name', 'age_based_cleanup')->first();
        $this->assertEquals('Critical error detected', $stop->reason);
        $this->assertEquals('system', $stop->stopped_by);
    }

    /**
     * Test: EmergencyStop static clearStop method
     */
    public function test_emergency_stop_clear_stop_static_method(): void
    {
        EmergencyStop::create([
            'service_name' => 'age_based_cleanup',
            'is_stopped' => true,
            'reason' => 'Test',
            'stopped_by' => 'admin',
            'stopped_at' => now(),
        ]);

        $result = EmergencyStop::clearStop('age_based_cleanup');

        $this->assertTrue($result);
        $this->assertFalse(EmergencyStop::isStopped('age_based_cleanup'));
    }

    /**
     * Test: EmergencyStop forService method creates if not exists
     */
    public function test_emergency_stop_for_service_creates_if_not_exists(): void
    {
        $stop = EmergencyStop::forService('age_based_cleanup');

        $this->assertNotNull($stop);
        $this->assertEquals('age_based_cleanup', $stop->service_name);
        $this->assertFalse($stop->is_stopped);

        // Calling again should return the same record
        $stop2 = EmergencyStop::forService('age_based_cleanup');
        $this->assertEquals($stop->id, $stop2->id);
    }

    /**
     * Test: EmergencyStop unique constraint on service_name
     */
    public function test_emergency_stop_unique_service_name(): void
    {
        EmergencyStop::create([
            'service_name' => 'age_based_cleanup',
            'is_stopped' => false,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        EmergencyStop::create([
            'service_name' => 'age_based_cleanup',
            'is_stopped' => true,
        ]);
    }
}
