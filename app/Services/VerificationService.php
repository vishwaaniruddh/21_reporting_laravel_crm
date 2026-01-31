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

/**
 * VerificationService handles verification of synced data between MySQL and PostgreSQL.
 * 
 * This service compares record counts per batch, validates that all synced IDs exist
 * in PostgreSQL, and generates verification reports.
 * 
 * IMPORTANT: This service performs READ-ONLY operations on MySQL alerts table.
 * It only updates sync_batches status - NEVER touches MySQL alerts data.
 * 
 * Requirements: 3.1, 3.2
 */
class VerificationService
{
    /**
     * Verification result for a single batch
     */
    public function verifyBatch(int $batchId): VerificationResult
    {
        $startTime = microtime(true);
        
        $batch = SyncBatch::find($batchId);
        
        if (!$batch) {
            return new VerificationResult(
                sourceCount: 0,
                targetCount: 0,
                missingIds: [],
                verified: false,
                batchId: $batchId,
                errorMessage: "Batch {$batchId} not found"
            );
        }

        try {
            // Get count from MySQL (source) - READ ONLY
            $sourceCount = Alert::where('sync_batch_id', $batchId)->count();
            
            // Get count from PostgreSQL (target)
            $targetCount = SyncedAlert::where('sync_batch_id', $batchId)->count();
            
            // Get IDs from both databases to find missing records
            $sourceIds = Alert::where('sync_batch_id', $batchId)
                ->pluck('id')
                ->toArray();
            
            $targetIds = SyncedAlert::where('sync_batch_id', $batchId)
                ->pluck('id')
                ->toArray();
            
            // Find missing IDs (in MySQL but not in PostgreSQL)
            $missingIds = array_diff($sourceIds, $targetIds);
            
            // Verification passes if counts match and no missing IDs
            $verified = ($sourceCount === $targetCount) && empty($missingIds);
            
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            
            // Log the verification result
            $status = $verified ? SyncLog::STATUS_SUCCESS : SyncLog::STATUS_FAILED;
            $errorMessage = null;
            
            if (!$verified) {
                $errorMessage = $this->buildErrorMessage($sourceCount, $targetCount, $missingIds);
            }
            
            SyncLog::logVerify(
                $batchId,
                $sourceCount,
                $status,
                $durationMs,
                $errorMessage
            );
            
            Log::info('Batch verification completed', [
                'batch_id' => $batchId,
                'source_count' => $sourceCount,
                'target_count' => $targetCount,
                'missing_ids_count' => count($missingIds),
                'verified' => $verified,
                'duration_ms' => $durationMs,
            ]);
            
            return new VerificationResult(
                sourceCount: $sourceCount,
                targetCount: $targetCount,
                missingIds: $missingIds,
                verified: $verified,
                batchId: $batchId,
                errorMessage: $errorMessage
            );
            
        } catch (Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            
            $errorMessage = "Verification failed: " . $e->getMessage();
            
            SyncLog::logVerify(
                $batchId,
                0,
                SyncLog::STATUS_FAILED,
                $durationMs,
                $errorMessage
            );
            
            Log::error('Batch verification failed', [
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
            ]);
            
            return new VerificationResult(
                sourceCount: 0,
                targetCount: 0,
                missingIds: [],
                verified: false,
                batchId: $batchId,
                errorMessage: $errorMessage
            );
        }
    }

    /**
     * Verify a specific record exists in PostgreSQL
     * 
     * @param int $recordId The alert ID to verify
     * @return bool True if record exists in PostgreSQL
     */
    public function verifyRecordExists(int $recordId): bool
    {
        return SyncedAlert::where('id', $recordId)->exists();
    }

    /**
     * Verify multiple records exist in PostgreSQL
     * 
     * @param array $recordIds Array of alert IDs to verify
     * @return array Array of missing IDs (IDs not found in PostgreSQL)
     */
    public function verifyRecordsExist(array $recordIds): array
    {
        if (empty($recordIds)) {
            return [];
        }
        
        $existingIds = SyncedAlert::whereIn('id', $recordIds)
            ->pluck('id')
            ->toArray();
        
        return array_diff($recordIds, $existingIds);
    }

    /**
     * Generate a verification report for a date range
     * 
     * @param \Carbon\Carbon|string $startDate Start date
     * @param \Carbon\Carbon|string $endDate End date
     * @return VerificationReport
     */
    public function generateVerificationReport($startDate, $endDate): VerificationReport
    {
        $startTime = microtime(true);
        
        // Get all batches in the date range
        $batches = SyncBatch::whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('id')
            ->get();
        
        $totalBatches = $batches->count();
        $verifiedBatches = 0;
        $failedBatches = 0;
        $pendingBatches = 0;
        $totalSourceRecords = 0;
        $totalTargetRecords = 0;
        $totalMissingRecords = 0;
        $batchDetails = [];
        
        foreach ($batches as $batch) {
            // Count records for this batch
            $sourceCount = Alert::where('sync_batch_id', $batch->id)->count();
            $targetCount = SyncedAlert::where('sync_batch_id', $batch->id)->count();
            $missingCount = max(0, $sourceCount - $targetCount);
            
            $totalSourceRecords += $sourceCount;
            $totalTargetRecords += $targetCount;
            $totalMissingRecords += $missingCount;
            
            // Track batch status
            switch ($batch->status) {
                case SyncBatch::STATUS_VERIFIED:
                    $verifiedBatches++;
                    break;
                case SyncBatch::STATUS_FAILED:
                    $failedBatches++;
                    break;
                case SyncBatch::STATUS_COMPLETED:
                case SyncBatch::STATUS_PROCESSING:
                case SyncBatch::STATUS_PENDING:
                    $pendingBatches++;
                    break;
            }
            
            $batchDetails[] = [
                'batch_id' => $batch->id,
                'status' => $batch->status,
                'source_count' => $sourceCount,
                'target_count' => $targetCount,
                'missing_count' => $missingCount,
                'created_at' => $batch->created_at?->toDateTimeString(),
                'verified_at' => $batch->verified_at?->toDateTimeString(),
            ];
        }
        
        $durationMs = (int) ((microtime(true) - $startTime) * 1000);
        
        $matchPercentage = $totalSourceRecords > 0 
            ? round(($totalTargetRecords / $totalSourceRecords) * 100, 2) 
            : 100.0;
        
        return new VerificationReport(
            startDate: $startDate,
            endDate: $endDate,
            totalBatches: $totalBatches,
            verifiedBatches: $verifiedBatches,
            failedBatches: $failedBatches,
            pendingBatches: $pendingBatches,
            totalSourceRecords: $totalSourceRecords,
            totalTargetRecords: $totalTargetRecords,
            totalMissingRecords: $totalMissingRecords,
            matchPercentage: $matchPercentage,
            batchDetails: $batchDetails,
            generatedAt: now(),
            durationMs: $durationMs
        );
    }

    /**
     * Get all completed batches that need verification
     * 
     * @return Collection
     */
    public function getCompletedBatchesForVerification(): Collection
    {
        return SyncBatch::where('status', SyncBatch::STATUS_COMPLETED)
            ->orderBy('id')
            ->get();
    }

    /**
     * Verify all completed batches and update their status
     * 
     * @return array Summary of verification results
     */
    public function verifyAllCompletedBatches(): array
    {
        $batches = $this->getCompletedBatchesForVerification();
        
        $results = [
            'total' => $batches->count(),
            'verified' => 0,
            'failed' => 0,
            'errors' => [],
        ];
        
        foreach ($batches as $batch) {
            $result = $this->verifyBatch($batch->id);
            
            if ($result->isVerified()) {
                // Update batch status to verified - only updates sync_batches, not alerts
                $batch->markVerified();
                $results['verified']++;
            } else {
                // Update batch status to failed
                $batch->markFailed($result->errorMessage ?? 'Verification failed');
                $results['failed']++;
                $results['errors'][] = [
                    'batch_id' => $batch->id,
                    'error' => $result->errorMessage,
                ];
            }
        }
        
        Log::info('Batch verification summary', $results);
        
        return $results;
    }

    /**
     * Build error message for verification failure
     */
    protected function buildErrorMessage(int $sourceCount, int $targetCount, array $missingIds): string
    {
        $messages = [];
        
        if ($sourceCount !== $targetCount) {
            $messages[] = "Count mismatch: MySQL has {$sourceCount} records, PostgreSQL has {$targetCount}";
        }
        
        if (!empty($missingIds)) {
            $missingCount = count($missingIds);
            $sampleIds = array_slice($missingIds, 0, 10);
            $messages[] = "{$missingCount} records missing in PostgreSQL. Sample IDs: " . implode(', ', $sampleIds);
        }
        
        return implode('. ', $messages);
    }
}
