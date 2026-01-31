<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * SyncLockService handles cache-based locking to prevent concurrent syncs.
 * 
 * This service implements:
 * - Cache-based distributed locking
 * - Lock acquisition with timeout
 * - Lock release with safety checks
 * - Lock status checking
 * 
 * ⚠️ NO DELETION FROM MYSQL: Locking uses cache, not MySQL
 * 
 * Requirements: 5.4
 */
class SyncLockService
{
    /**
     * Default lock timeout in seconds (1 hour)
     */
    protected int $defaultLockTimeout = 3600;

    /**
     * Lock key prefix
     */
    protected string $lockPrefix = 'table_sync_lock_';

    /**
     * Lock owner key prefix (to track who owns the lock)
     */
    protected string $ownerPrefix = 'table_sync_lock_owner_';

    /**
     * Acquire a lock for a specific configuration.
     * 
     * @param int $configId Configuration ID
     * @param int|null $timeout Lock timeout in seconds (default: 3600)
     * @return bool True if lock was acquired, false if already locked
     */
    public function acquireLock(int $configId, ?int $timeout = null): bool
    {
        $lockKey = $this->getLockKey($configId);
        $ownerKey = $this->getOwnerKey($configId);
        $timeout = $timeout ?? $this->defaultLockTimeout;
        
        // Generate a unique owner ID for this lock acquisition
        $ownerId = $this->generateOwnerId();

        // Try to acquire the lock atomically
        $acquired = Cache::add($lockKey, true, $timeout);

        if ($acquired) {
            // Store the owner ID for this lock
            Cache::put($ownerKey, $ownerId, $timeout);
            
            Log::info('Sync lock acquired', [
                'config_id' => $configId,
                'owner_id' => $ownerId,
                'timeout_seconds' => $timeout,
            ]);

            return true;
        }

        Log::debug('Sync lock acquisition failed - already locked', [
            'config_id' => $configId,
        ]);

        return false;
    }

    /**
     * Release a lock for a specific configuration.
     * 
     * @param int $configId Configuration ID
     * @return bool True if lock was released, false if lock didn't exist
     */
    public function releaseLock(int $configId): bool
    {
        $lockKey = $this->getLockKey($configId);
        $ownerKey = $this->getOwnerKey($configId);

        $existed = Cache::has($lockKey);
        
        Cache::forget($lockKey);
        Cache::forget($ownerKey);

        if ($existed) {
            Log::info('Sync lock released', [
                'config_id' => $configId,
            ]);
        }

        return $existed;
    }

    /**
     * Check if a lock is currently held for a configuration.
     * 
     * @param int $configId Configuration ID
     * @return bool True if locked, false otherwise
     */
    public function isLocked(int $configId): bool
    {
        return Cache::has($this->getLockKey($configId));
    }

    /**
     * Get lock status information for a configuration.
     * 
     * @param int $configId Configuration ID
     * @return array Lock status information
     */
    public function getLockStatus(int $configId): array
    {
        $lockKey = $this->getLockKey($configId);
        $ownerKey = $this->getOwnerKey($configId);

        $isLocked = Cache::has($lockKey);
        $ownerId = Cache::get($ownerKey);

        return [
            'is_locked' => $isLocked,
            'owner_id' => $ownerId,
            'config_id' => $configId,
        ];
    }

    /**
     * Force release a lock (use with caution).
     * This should only be used for administrative purposes.
     * 
     * @param int $configId Configuration ID
     * @return bool True if lock was force released
     */
    public function forceReleaseLock(int $configId): bool
    {
        Log::warning('Force releasing sync lock', [
            'config_id' => $configId,
        ]);

        return $this->releaseLock($configId);
    }

    /**
     * Extend the lock timeout for a configuration.
     * 
     * @param int $configId Configuration ID
     * @param int $additionalSeconds Additional seconds to extend
     * @return bool True if lock was extended, false if lock doesn't exist
     */
    public function extendLock(int $configId, int $additionalSeconds): bool
    {
        $lockKey = $this->getLockKey($configId);
        $ownerKey = $this->getOwnerKey($configId);

        if (!Cache::has($lockKey)) {
            return false;
        }

        $ownerId = Cache::get($ownerKey);
        
        // Re-acquire with extended timeout
        Cache::put($lockKey, true, $additionalSeconds);
        if ($ownerId) {
            Cache::put($ownerKey, $ownerId, $additionalSeconds);
        }

        Log::debug('Sync lock extended', [
            'config_id' => $configId,
            'additional_seconds' => $additionalSeconds,
        ]);

        return true;
    }

    /**
     * Get all currently held locks.
     * Note: This is an approximation as cache may not support listing keys.
     * 
     * @param array $configIds Array of configuration IDs to check
     * @return array Array of locked configuration IDs
     */
    public function getActiveLocks(array $configIds): array
    {
        $lockedIds = [];

        foreach ($configIds as $configId) {
            if ($this->isLocked($configId)) {
                $lockedIds[] = $configId;
            }
        }

        return $lockedIds;
    }

    /**
     * Execute a callback while holding a lock.
     * Automatically acquires and releases the lock.
     * 
     * @param int $configId Configuration ID
     * @param callable $callback Callback to execute
     * @param int|null $timeout Lock timeout in seconds
     * @return mixed Result of the callback
     * @throws Exception If lock cannot be acquired
     */
    public function withLock(int $configId, callable $callback, ?int $timeout = null)
    {
        if (!$this->acquireLock($configId, $timeout)) {
            throw new Exception("Cannot acquire lock for configuration {$configId} - sync already running");
        }

        try {
            return $callback();
        } finally {
            $this->releaseLock($configId);
        }
    }

    /**
     * Get the cache key for a lock.
     * 
     * @param int $configId Configuration ID
     * @return string
     */
    protected function getLockKey(int $configId): string
    {
        return $this->lockPrefix . $configId;
    }

    /**
     * Get the cache key for lock owner.
     * 
     * @param int $configId Configuration ID
     * @return string
     */
    protected function getOwnerKey(int $configId): string
    {
        return $this->ownerPrefix . $configId;
    }

    /**
     * Generate a unique owner ID for lock tracking.
     * 
     * @return string
     */
    protected function generateOwnerId(): string
    {
        return uniqid('sync_', true) . '_' . gethostname() . '_' . getmypid();
    }

    /**
     * Set the default lock timeout.
     * 
     * @param int $seconds Timeout in seconds
     * @return self
     */
    public function setDefaultTimeout(int $seconds): self
    {
        $this->defaultLockTimeout = $seconds;
        return $this;
    }

    /**
     * Get the default lock timeout.
     * 
     * @return int
     */
    public function getDefaultTimeout(): int
    {
        return $this->defaultLockTimeout;
    }
}
