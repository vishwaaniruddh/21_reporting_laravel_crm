<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Exception;
use PDOException;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * RetryService handles retry logic with exponential backoff for sync operations.
 * 
 * This service implements:
 * - Retryable error detection
 * - Exponential backoff (1s, 2s, 4s, 8s, max 30s)
 * - Max 5 retry attempts before failing
 * - Configurable retry parameters
 * 
 * ⚠️ NO DELETION FROM MYSQL: Retry re-reads from MySQL, writes to PostgreSQL
 * 
 * Requirements: 7.1, 7.2
 */
class RetryService
{
    /**
     * Maximum number of retry attempts
     */
    protected int $maxAttempts;

    /**
     * Initial delay in seconds
     */
    protected int $initialDelaySeconds;

    /**
     * Maximum delay in seconds
     */
    protected int $maxDelaySeconds;

    /**
     * Patterns that indicate a retryable error
     */
    protected array $retryablePatterns = [
        'connection',
        'timeout',
        'gone away',
        'lost connection',
        'server has gone away',
        'connection refused',
        'connection reset',
        'broken pipe',
        'network',
        'socket',
        'deadlock',
        'lock wait timeout',
        'too many connections',
        'connection timed out',
        'temporarily unavailable',
        'resource temporarily unavailable',
        'no route to host',
        'host is down',
        'connection aborted',
    ];

    /**
     * Error codes that indicate a retryable error
     */
    protected array $retryableErrorCodes = [
        // MySQL error codes
        1040, // Too many connections
        1205, // Lock wait timeout exceeded
        1213, // Deadlock found
        2002, // Can't connect to local MySQL server
        2003, // Can't connect to MySQL server
        2006, // MySQL server has gone away
        2013, // Lost connection to MySQL server
        
        // PostgreSQL error codes (SQLSTATE)
        '08000', // Connection exception
        '08003', // Connection does not exist
        '08006', // Connection failure
        '08001', // SQL client unable to establish connection
        '08004', // SQL server rejected connection
        '40001', // Serialization failure (deadlock)
        '40P01', // Deadlock detected
        '57P01', // Admin shutdown
        '57P02', // Crash shutdown
        '57P03', // Cannot connect now
    ];

    public function __construct()
    {
        $this->maxAttempts = (int) config('pipeline.retry.max_attempts', 5);
        $this->initialDelaySeconds = (int) config('pipeline.retry.initial_delay_seconds', 1);
        $this->maxDelaySeconds = (int) config('pipeline.retry.max_delay_seconds', 30);
    }

    /**
     * Execute a callback with retry logic.
     * 
     * @param callable $callback The operation to execute
     * @param string $operationName Name of the operation for logging
     * @param array $context Additional context for logging
     * @return mixed Result of the callback
     * @throws Exception If all retry attempts fail
     */
    public function executeWithRetry(callable $callback, string $operationName = 'operation', array $context = [])
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxAttempts) {
            $attempt++;

            try {
                return $callback();

            } catch (Throwable $e) {
                $lastException = $e;

                // Check if this is a retryable error
                if (!$this->isRetryableError($e)) {
                    Log::error("Non-retryable error in {$operationName}", array_merge($context, [
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                        'error_class' => get_class($e),
                    ]));
                    throw $e;
                }

                // Check if we have more attempts
                if ($attempt >= $this->maxAttempts) {
                    Log::error("{$operationName} failed after {$attempt} attempts", array_merge($context, [
                        'error' => $e->getMessage(),
                        'error_class' => get_class($e),
                    ]));
                    break;
                }

                // Calculate delay and wait
                $delay = $this->calculateBackoffDelay($attempt);
                
                Log::warning("{$operationName} failed, retrying", array_merge($context, [
                    'attempt' => $attempt,
                    'max_attempts' => $this->maxAttempts,
                    'delay_seconds' => $delay,
                    'error' => $e->getMessage(),
                ]));

                $this->sleep($delay);
            }
        }

        throw new Exception(
            "{$operationName} failed after {$attempt} attempts: " . 
            ($lastException?->getMessage() ?? 'Unknown error'),
            0,
            $lastException
        );
    }

    /**
     * Check if an exception is a retryable connection error.
     * 
     * @param Throwable $e
     * @return bool
     */
    public function isRetryableError(Throwable $e): bool
    {
        // Check error message patterns
        $message = strtolower($e->getMessage());
        foreach ($this->retryablePatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        // Check error codes for PDOException
        if ($e instanceof PDOException) {
            $code = $e->getCode();
            if (in_array($code, $this->retryableErrorCodes, true) || 
                in_array((string) $code, $this->retryableErrorCodes, true)) {
                return true;
            }
        }

        // Check error codes for QueryException
        if ($e instanceof QueryException) {
            $code = $e->getCode();
            if (in_array($code, $this->retryableErrorCodes, true) || 
                in_array((string) $code, $this->retryableErrorCodes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate exponential backoff delay.
     * 
     * Formula: min(initialDelay * 2^(attempt-1), maxDelay)
     * Example with defaults: 1s, 2s, 4s, 8s, 16s (capped at 30s)
     * 
     * @param int $attempt Current attempt number (1-based)
     * @return int Delay in seconds
     */
    public function calculateBackoffDelay(int $attempt): int
    {
        $delay = $this->initialDelaySeconds * pow(2, $attempt - 1);
        return min($delay, $this->maxDelaySeconds);
    }

    /**
     * Sleep for the specified number of seconds.
     * This method is separated for easier testing.
     * 
     * @param int $seconds
     */
    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * Add a custom retryable pattern.
     * 
     * @param string $pattern
     * @return self
     */
    public function addRetryablePattern(string $pattern): self
    {
        $this->retryablePatterns[] = strtolower($pattern);
        return $this;
    }

    /**
     * Add a custom retryable error code.
     * 
     * @param int|string $code
     * @return self
     */
    public function addRetryableErrorCode($code): self
    {
        $this->retryableErrorCodes[] = $code;
        return $this;
    }

    /**
     * Set the maximum number of retry attempts.
     * 
     * @param int $maxAttempts
     * @return self
     */
    public function setMaxAttempts(int $maxAttempts): self
    {
        $this->maxAttempts = max(1, $maxAttempts);
        return $this;
    }

    /**
     * Get the maximum number of retry attempts.
     * 
     * @return int
     */
    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * Set the initial delay in seconds.
     * 
     * @param int $seconds
     * @return self
     */
    public function setInitialDelay(int $seconds): self
    {
        $this->initialDelaySeconds = max(1, $seconds);
        return $this;
    }

    /**
     * Get the initial delay in seconds.
     * 
     * @return int
     */
    public function getInitialDelay(): int
    {
        return $this->initialDelaySeconds;
    }

    /**
     * Set the maximum delay in seconds.
     * 
     * @param int $seconds
     * @return self
     */
    public function setMaxDelay(int $seconds): self
    {
        $this->maxDelaySeconds = max(1, $seconds);
        return $this;
    }

    /**
     * Get the maximum delay in seconds.
     * 
     * @return int
     */
    public function getMaxDelay(): int
    {
        return $this->maxDelaySeconds;
    }

    /**
     * Get all retryable patterns.
     * 
     * @return array
     */
    public function getRetryablePatterns(): array
    {
        return $this->retryablePatterns;
    }

    /**
     * Get all retryable error codes.
     * 
     * @return array
     */
    public function getRetryableErrorCodes(): array
    {
        return $this->retryableErrorCodes;
    }

    /**
     * Create a new instance with custom configuration.
     * 
     * @param int $maxAttempts
     * @param int $initialDelaySeconds
     * @param int $maxDelaySeconds
     * @return self
     */
    public static function create(
        int $maxAttempts = 5,
        int $initialDelaySeconds = 1,
        int $maxDelaySeconds = 30
    ): self {
        $instance = new self();
        $instance->maxAttempts = $maxAttempts;
        $instance->initialDelaySeconds = $initialDelaySeconds;
        $instance->maxDelaySeconds = $maxDelaySeconds;
        return $instance;
    }
}
