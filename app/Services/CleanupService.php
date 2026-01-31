<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\SyncBatch;
use App\Models\SyncedAlert;
use App\Models\SyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Exception;
use PDOException;

/**
 * CleanupService handles the deletion of verified synced records from MySQL.
 * 
 * ⚠️ EXTREME CAUTION: This is the ONLY service that deletes from MySQL alerts!
 * 
 * Cleanup requires ALL of the following conditions:
 * 1. Records must be successfully synced to PostgreSQL
 * 2. Records must be verified by the VerificationService
 * 3. Records must be older than the configured retention period
 * 4. Admin must explicitly confirm/trigger the cleanup
 * 
 * Requirements: 4.1, 4.2, 4.3, 3.5
 */
class CleanupService
{
    /**
     * Batch size for delete operations to prevent long locks
     */
    protected int $deleteBatchSize;

    /**
     * Retention period in days
     */
    protected int $retentionDays;

    /**
     * Verification service for triple-checking before delete
     */
    protected VerificationService $verificationService;

    /**
     * Flag to track if admin has confirmed cleanup
     */
    protected bool $adminConfirmed = false;

    public function __construct(?VerificationService $verificationService = null)
    {
        $this->deleteBatchSize = (int) config('pipeline.cleanup_batch_size', 1000);
        $this->retentionDays = (int) config('pipeline.retention_days', 7);
        $this->verificationService = $verificationService ?? new VerificationService();
    }

    /**
     * Set admin confirmation for cleanup operations
     * 
     * ⚠️ Cleanup will NOT proceed without explicit admin confirmation
     * 
     * @param bool $confirmed Whether admin has confirmed
     * @return self
     */
    public function setAdminConfirmation(bool $confirmed): self
    {
        $this->adminConfirmed = $confirmed;
        
        if ($confirmed) {
            Log::info('Admin confirmation received for cleanup operation');
        }
        
        return $this;
    }

    /**
     * Check if admin has confirmed cleanup
     */
    public function isAdminConfirmed(): bool
    {
        return $this->adminConfirmed;
    }

    /**
     * Get the configured retention period in days
     */
    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    /**
     * Set the retention period in days
     */
    public function setRetentionDays(int $days): self
    {
        $this->retentionDays = max(1, $days);
        return $this;
    }

    /**
     * Get the delete batch size
     */
    public function getDeleteBatchSize(): int
    {
        return $this->deleteBatchSize;
    }

    /**
     * Set the delete batch size
     */
    public function setDeleteBatchSize(int $size): self
    {
        $this->deleteBatchSize = max(100, min($size, 10000));
        return $this;
    }


    /**
     * Get batches eligible for cleanup
     * 
     * Returns batches that are:
     * 1. Verified (status = 'verified')
     * 2. Older than retention period
     * 
     * @return Collection
     */
    public function getEligibleBatches(): Collection
    {
        return SyncBatch::verifiedForCleanup($this->retentionDays)
            ->orderBy('id')
            ->get();
    }

    /**
     * Get count of records eligible for cleanup
     */
    public function getEligibleRecordCount(): int
    {
        $eligibleBatches = $this->getEligibleBatches();
        
        if ($eligibleBatches->isEmpty()) {
            return 0;
        }

        $batchIds = $eligibleBatches->pluck('id')->toArray();
        
        return Alert::whereIn('sync_batch_id', $batchIds)->count();
    }

    /**
     * TRIPLE-CHECK verification before any delete operation
     * 
     * This method performs three levels of verification:
     * 1. Check batch status is 'verified'
     * 2. Re-verify record counts match between MySQL and PostgreSQL
     * 3. Verify all record IDs exist in PostgreSQL
     * 
     * @param int $batchId The batch to verify
     * @return array ['verified' => bool, 'reason' => string|null]
     */
    public function tripleCheckVerification(int $batchId): array
    {
        // CHECK 1: Batch status must be 'verified'
        $batch = SyncBatch::find($batchId);
        
        if (!$batch) {
            return [
                'verified' => false,
                'reason' => "Batch {$batchId} not found",
            ];
        }

        if ($batch->status !== SyncBatch::STATUS_VERIFIED) {
            return [
                'verified' => false,
                'reason' => "Batch {$batchId} status is '{$batch->status}', expected 'verified'",
            ];
        }

        // CHECK 2: Re-verify record counts match
        $sourceCount = Alert::where('sync_batch_id', $batchId)->count();
        $targetCount = SyncedAlert::where('sync_batch_id', $batchId)->count();

        if ($sourceCount !== $targetCount) {
            return [
                'verified' => false,
                'reason' => "Count mismatch for batch {$batchId}: MySQL has {$sourceCount}, PostgreSQL has {$targetCount}",
            ];
        }

        // CHECK 3: Verify all record IDs exist in PostgreSQL
        $sourceIds = Alert::where('sync_batch_id', $batchId)
            ->pluck('id')
            ->toArray();

        if (!empty($sourceIds)) {
            $missingIds = $this->verificationService->verifyRecordsExist($sourceIds);
            
            if (!empty($missingIds)) {
                $sampleMissing = array_slice($missingIds, 0, 5);
                return [
                    'verified' => false,
                    'reason' => "Missing " . count($missingIds) . " records in PostgreSQL. Sample IDs: " . implode(', ', $sampleMissing),
                ];
            }
        }

        return [
            'verified' => true,
            'reason' => null,
        ];
    }

    /**
     * Clean up a single batch of verified records
     * 
     * ⚠️ This method DELETES records from MySQL alerts table!
     * ⚠️ Requires admin confirmation before proceeding
     * 
     * @param int $batchId The batch to clean up
     * @return CleanupResult
     */
    public function cleanupBatch(int $batchId): CleanupResult
    {
        $startTime = microtime(true);

        // SAFETY CHECK 1: Admin confirmation required
        if (!$this->adminConfirmed) {
            Log::warning('Cleanup attempted without admin confirmation', [
                'batch_id' => $batchId,
            ]);
            
            return new CleanupResult(
                recordsDeleted: 0,
                recordsSkipped: 0,
                batchesProcessed: 0,
                success: false,
                errors: ['Admin confirmation required for cleanup'],
                errorMessage: 'Admin confirmation required. Call setAdminConfirmation(true) before cleanup.',
                adminConfirmed: false
            );
        }

        // SAFETY CHECK 2: Triple-check verification
        $verificationCheck = $this->tripleCheckVerification($batchId);
        
        if (!$verificationCheck['verified']) {
            Log::warning('Cleanup blocked by verification failure', [
                'batch_id' => $batchId,
                'reason' => $verificationCheck['reason'],
            ]);

            return new CleanupResult(
                recordsDeleted: 0,
                recordsSkipped: 0,
                batchesProcessed: 0,
                success: false,
                errors: [$verificationCheck['reason']],
                errorMessage: $verificationCheck['reason'],
                adminConfirmed: true
            );
        }

        // SAFETY CHECK 3: Verify retention period
        $batch = SyncBatch::find($batchId);
        
        if ($batch->verified_at && $batch->verified_at->diffInDays(now()) < $this->retentionDays) {
            $daysRemaining = $this->retentionDays - $batch->verified_at->diffInDays(now());
            $reason = "Batch {$batchId} has not met retention period. {$daysRemaining} days remaining.";
            
            Log::warning('Cleanup blocked by retention period', [
                'batch_id' => $batchId,
                'verified_at' => $batch->verified_at,
                'retention_days' => $this->retentionDays,
                'days_remaining' => $daysRemaining,
            ]);

            return new CleanupResult(
                recordsDeleted: 0,
                recordsSkipped: 0,
                batchesProcessed: 0,
                success: false,
                errors: [$reason],
                errorMessage: $reason,
                adminConfirmed: true
            );
        }

        // All safety checks passed - proceed with deletion
        try {
            $totalDeleted = $this->deleteRecordsInBatches($batchId);
            
            // Mark batch as cleaned
            $batch->markCleaned();

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            // Log successful cleanup
            SyncLog::logCleanup(
                $batchId,
                $totalDeleted,
                SyncLog::STATUS_SUCCESS,
                $durationMs
            );

            Log::info('Batch cleanup completed successfully', [
                'batch_id' => $batchId,
                'records_deleted' => $totalDeleted,
                'duration_ms' => $durationMs,
            ]);

            return new CleanupResult(
                recordsDeleted: $totalDeleted,
                recordsSkipped: 0,
                batchesProcessed: 1,
                success: true,
                errors: [],
                errorMessage: null,
                adminConfirmed: true
            );

        } catch (Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $errorMessage = $e->getMessage();

            // Log failed cleanup
            SyncLog::logCleanup(
                $batchId,
                0,
                SyncLog::STATUS_FAILED,
                $durationMs,
                $errorMessage
            );

            Log::error('Batch cleanup failed', [
                'batch_id' => $batchId,
                'error' => $errorMessage,
            ]);

            return new CleanupResult(
                recordsDeleted: 0,
                recordsSkipped: 0,
                batchesProcessed: 0,
                success: false,
                errors: [$errorMessage],
                errorMessage: $errorMessage,
                adminConfirmed: true
            );
        }
    }


    /**
     * Delete records in small batches to prevent long locks
     * 
     * ⚠️ This method performs actual DELETE operations on MySQL alerts!
     * 
     * @param int $batchId The sync batch ID
     * @return int Total number of records deleted
     */
    protected function deleteRecordsInBatches(int $batchId): int
    {
        $totalDeleted = 0;
        
        do {
            // Get a batch of record IDs to delete
            $recordIds = Alert::where('sync_batch_id', $batchId)
                ->limit($this->deleteBatchSize)
                ->pluck('id')
                ->toArray();

            if (empty($recordIds)) {
                break;
            }

            // Log each delete operation
            Log::info('Deleting batch of records from MySQL alerts', [
                'batch_id' => $batchId,
                'record_count' => count($recordIds),
                'first_id' => $recordIds[0],
                'last_id' => end($recordIds),
            ]);

            // Delete the records
            $deleted = Alert::whereIn('id', $recordIds)->delete();
            $totalDeleted += $deleted;

            Log::info('Deleted records from MySQL alerts', [
                'batch_id' => $batchId,
                'deleted_count' => $deleted,
                'total_deleted' => $totalDeleted,
            ]);

        } while (!empty($recordIds));

        return $totalDeleted;
    }

    /**
     * Clean up all eligible batches
     * 
     * ⚠️ This method DELETES records from MySQL alerts table!
     * ⚠️ Requires admin confirmation before proceeding
     * 
     * @return CleanupResult
     */
    public function cleanupAllEligible(): CleanupResult
    {
        $startTime = microtime(true);

        // SAFETY CHECK: Admin confirmation required
        if (!$this->adminConfirmed) {
            Log::warning('Cleanup all attempted without admin confirmation');
            
            return new CleanupResult(
                recordsDeleted: 0,
                recordsSkipped: 0,
                batchesProcessed: 0,
                success: false,
                errors: ['Admin confirmation required for cleanup'],
                errorMessage: 'Admin confirmation required. Call setAdminConfirmation(true) before cleanup.',
                adminConfirmed: false
            );
        }

        $eligibleBatches = $this->getEligibleBatches();

        if ($eligibleBatches->isEmpty()) {
            Log::info('No eligible batches for cleanup');
            
            return new CleanupResult(
                recordsDeleted: 0,
                recordsSkipped: 0,
                batchesProcessed: 0,
                success: true,
                errors: [],
                errorMessage: null,
                adminConfirmed: true
            );
        }

        $totalDeleted = 0;
        $totalSkipped = 0;
        $batchesProcessed = 0;
        $errors = [];

        foreach ($eligibleBatches as $batch) {
            $result = $this->cleanupBatch($batch->id);

            if ($result->isSuccess()) {
                $totalDeleted += $result->recordsDeleted;
                $batchesProcessed++;
            } else {
                $totalSkipped += Alert::where('sync_batch_id', $batch->id)->count();
                $errors = array_merge($errors, $result->errors);
            }
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('Cleanup all eligible completed', [
            'batches_processed' => $batchesProcessed,
            'total_deleted' => $totalDeleted,
            'total_skipped' => $totalSkipped,
            'error_count' => count($errors),
            'duration_ms' => $durationMs,
        ]);

        return new CleanupResult(
            recordsDeleted: $totalDeleted,
            recordsSkipped: $totalSkipped,
            batchesProcessed: $batchesProcessed,
            success: empty($errors),
            errors: $errors,
            errorMessage: empty($errors) ? null : implode('; ', array_slice($errors, 0, 5)),
            adminConfirmed: true
        );
    }

    /**
     * Preview cleanup without actually deleting
     * 
     * Returns information about what would be cleaned up
     * 
     * @return array
     */
    public function previewCleanup(): array
    {
        $eligibleBatches = $this->getEligibleBatches();
        
        $preview = [
            'eligible_batches' => $eligibleBatches->count(),
            'eligible_records' => 0,
            'retention_days' => $this->retentionDays,
            'batches' => [],
        ];

        foreach ($eligibleBatches as $batch) {
            $recordCount = Alert::where('sync_batch_id', $batch->id)->count();
            $preview['eligible_records'] += $recordCount;
            
            $preview['batches'][] = [
                'batch_id' => $batch->id,
                'record_count' => $recordCount,
                'verified_at' => $batch->verified_at?->toDateTimeString(),
                'days_since_verified' => $batch->verified_at?->diffInDays(now()),
            ];
        }

        return $preview;
    }

    /**
     * Check if cleanup can proceed (all safety checks)
     * 
     * @return array ['can_proceed' => bool, 'reasons' => array]
     */
    public function canProceedWithCleanup(): array
    {
        $reasons = [];

        if (!$this->adminConfirmed) {
            $reasons[] = 'Admin confirmation not received';
        }

        $eligibleBatches = $this->getEligibleBatches();
        
        if ($eligibleBatches->isEmpty()) {
            $reasons[] = 'No eligible batches for cleanup';
        }

        // Check MySQL connection
        try {
            DB::connection('mysql')->getPdo();
        } catch (Exception $e) {
            $reasons[] = 'MySQL connection unavailable: ' . $e->getMessage();
        }

        // Check PostgreSQL connection (for verification)
        try {
            DB::connection('pgsql')->getPdo();
        } catch (Exception $e) {
            $reasons[] = 'PostgreSQL connection unavailable: ' . $e->getMessage();
        }

        return [
            'can_proceed' => empty($reasons),
            'reasons' => $reasons,
        ];
    }
}
