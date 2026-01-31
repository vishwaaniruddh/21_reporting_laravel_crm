<?php

namespace App\Services;

use App\Models\AlertUpdateLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * UpdateLogMonitor Service
 * 
 * Monitors the MySQL alert_pg_update_log table and retrieves pending entries
 * for synchronization processing. This service only performs SELECT queries
 * on the log table - no modifications to the MySQL alerts table.
 * 
 * Responsibilities:
 * - Query MySQL alert_pg_update_log for entries with status=1 (pending)
 * - Order entries by created_at (oldest first)
 * - Limit results to configured batch size
 * - Provide metrics on pending entries
 */
class UpdateLogMonitor
{
    /**
     * Maximum number of entries to fetch per batch
     */
    private int $batchSize;

    /**
     * Create a new UpdateLogMonitor instance
     * 
     * @param int $batchSize Maximum entries per batch (default: 100)
     */
    public function __construct(int $batchSize = 100)
    {
        $this->batchSize = $batchSize;
    }

    /**
     * Fetch pending log entries from MySQL alert_pg_update_log table
     * 
     * Retrieves entries with status=1 (pending), ordered by created_at
     * ascending (oldest first), limited to the configured batch size.
     * 
     * This method only performs SELECT queries - no modifications to
     * the MySQL alerts table or alert_pg_update_log table.
     * 
     * @return Collection<AlertUpdateLog> Collection of pending log entries (may be empty)
     */
    public function fetchPendingEntries(): Collection
    {
        return AlertUpdateLog::pending()
            ->limit($this->batchSize)
            ->get();
    }

    /**
     * Get the timestamp of the oldest pending entry
     * 
     * Useful for monitoring lag between when entries are created
     * and when they are processed.
     * 
     * @return Carbon|null Timestamp of oldest pending entry, or null if none exist
     */
    public function getOldestPendingTimestamp(): ?Carbon
    {
        $oldest = AlertUpdateLog::pending()
            ->first();

        return $oldest ? $oldest->created_at : null;
    }

    /**
     * Get the total count of pending entries
     * 
     * Returns the number of entries with status=1 (pending) in the
     * alert_pg_update_log table. Useful for monitoring and metrics.
     * 
     * @return int Total number of pending entries
     */
    public function getPendingCount(): int
    {
        return AlertUpdateLog::pending()->count();
    }

    /**
     * Get the configured batch size
     * 
     * @return int Maximum entries per batch
     */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * Set the batch size
     * 
     * @param int $batchSize Maximum entries per batch
     * @return void
     */
    public function setBatchSize(int $batchSize): void
    {
        $this->batchSize = $batchSize;
    }
}
