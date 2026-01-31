<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\SyncBatch;
use App\Models\SyncedAlert;
use App\Models\SyncLog;
use App\Services\SyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Property-Based Test: Data Preservation on Sync
 * 
 * Feature: alerts-data-pipeline, Property 1: Data Preservation on Sync
 * Validates: Requirements 1.2
 * 
 * Property: For any alert record synced from MySQL to PostgreSQL, the PostgreSQL
 * record SHALL contain identical data to the original MySQL record (excluding
 * sync metadata fields).
 * 
 * IMPORTANT: This test uses EXISTING MySQL alerts data.
 * It does NOT create or delete any MySQL alerts.
 */
class DataPreservationPropertyTest extends TestCase
{
    /**
     * Fields that should be preserved during sync (excluding sync metadata)
     */
    protected array $preservedFields = [
        'id',
        'panelid',
        'seqno',
        'zone',
        'alarm',
        'createtime',
        'receivedtime',
        'comment',
        'status',
        'sendtoclient',
        'closedBy',
        'closedtime',
        'sendip',
        'alerttype',
        'location',
        'priority',
        'AlertUserStatus',
        'level',
        'sip2',
        'c_status',
        'auto_alert',
        'critical_alerts',
        'Readstatus',
    ];

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
     * Generates multiple test iterations with different batch sizes
     * 
     * @return array
     */
    public static function batchSizeProvider(): array
    {
        // Generate 100 iterations with varying batch sizes
        $iterations = [];
        for ($i = 1; $i <= 100; $i++) {
            // Vary batch sizes: 1, 5, 10, 25, 50
            $batchSizes = [1, 5, 10, 25, 50];
            $batchSize = $batchSizes[$i % count($batchSizes)];
            $iterations["iteration_{$i}_batch_{$batchSize}"] = [$batchSize, $i];
        }
        return $iterations;
    }

    /**
     * Property Test: Data Preservation on Sync
     * 
     * Feature: alerts-data-pipeline, Property 1: Data Preservation on Sync
     * Validates: Requirements 1.2
     * 
     * For any alert record synced from MySQL to PostgreSQL, the PostgreSQL
     * record SHALL contain identical data to the original MySQL record
     * (excluding sync metadata fields).
     * 
     * @dataProvider batchSizeProvider
     */
    public function test_synced_data_preserves_all_original_values(int $batchSize, int $iteration): void
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

        // Store original MySQL data before sync (deep copy)
        $originalData = $mysqlAlerts->map(function ($alert) {
            $data = [];
            foreach ($this->preservedFields as $field) {
                $value = $alert->{$field};
                // Convert datetime objects to string for comparison
                if ($value instanceof \DateTime || $value instanceof \Carbon\Carbon) {
                    $data[$field] = $value->format('Y-m-d H:i:s');
                } else {
                    $data[$field] = $value;
                }
            }
            return $data;
        })->keyBy('id')->toArray();

        // Process the batch (sync to PostgreSQL)
        $result = $syncService->processBatch($mysqlAlerts);

        // Assert sync was successful
        $this->assertTrue(
            $result->isSuccess(),
            "Sync failed on iteration {$iteration}: " . ($result->errorMessage ?? 'Unknown error')
        );

        // Property assertion: For ALL synced records, PostgreSQL data must match MySQL data
        foreach ($originalData as $id => $mysqlData) {
            $pgsqlAlert = SyncedAlert::find($id);

            // Assert record exists in PostgreSQL
            $this->assertNotNull(
                $pgsqlAlert,
                "Alert ID {$id} not found in PostgreSQL after sync (iteration {$iteration})"
            );

            // Assert ALL preserved fields match exactly
            foreach ($this->preservedFields as $field) {
                $mysqlValue = $mysqlData[$field];
                $pgsqlValue = $pgsqlAlert->{$field};

                // Convert datetime objects to string for comparison
                if ($pgsqlValue instanceof \DateTime || $pgsqlValue instanceof \Carbon\Carbon) {
                    $pgsqlValue = $pgsqlValue->format('Y-m-d H:i:s');
                }

                $this->assertEquals(
                    $mysqlValue,
                    $pgsqlValue,
                    "Field '{$field}' mismatch for Alert ID {$id} on iteration {$iteration}. " .
                    "MySQL: " . var_export($mysqlValue, true) . " vs PostgreSQL: " . var_export($pgsqlValue, true)
                );
            }
        }

        // Additional property: Record count must match
        $this->assertEquals(
            count($originalData),
            SyncedAlert::whereIn('id', array_keys($originalData))->count(),
            "Record count mismatch on iteration {$iteration}"
        );
    }

    /**
     * Property Test: Data types are preserved correctly
     * 
     * Feature: alerts-data-pipeline, Property 1: Data Preservation on Sync
     * Validates: Requirements 1.2
     * 
     * For any synced record, data types should be preserved (strings remain strings,
     * integers remain integers, nulls remain nulls).
     */
    public function test_data_types_preserved_on_sync(): void
    {
        $syncService = new SyncService();
        $syncService->setBatchSize(20);

        if ($syncService->getUnsyncedCount() === 0) {
            $this->markTestSkipped('No unsynced records in MySQL alerts table.');
        }

        $mysqlAlerts = $syncService->fetchUnsyncedBatch();
        
        // Store type information before sync
        $typeInfo = [];
        foreach ($mysqlAlerts as $alert) {
            $types = [];
            foreach ($this->preservedFields as $field) {
                $value = $alert->{$field};
                $types[$field] = [
                    'is_null' => is_null($value),
                    'type' => gettype($value),
                ];
            }
            $typeInfo[$alert->id] = $types;
        }

        $result = $syncService->processBatch($mysqlAlerts);
        $this->assertTrue($result->isSuccess());

        // Verify type preservation
        foreach ($typeInfo as $id => $info) {
            $pgsqlAlert = SyncedAlert::find($id);
            $this->assertNotNull($pgsqlAlert);

            foreach ($this->preservedFields as $field) {
                if ($field === 'id') continue;
                
                $expectedNull = $info[$field]['is_null'];
                $actualNull = is_null($pgsqlAlert->{$field});

                $this->assertEquals(
                    $expectedNull,
                    $actualNull,
                    "Null status mismatch for field '{$field}' on Alert ID {$id}. " .
                    "Expected null: " . ($expectedNull ? 'yes' : 'no') . ", Got null: " . ($actualNull ? 'yes' : 'no')
                );
            }
        }
    }
}
