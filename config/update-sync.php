<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Update Sync Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the MySQL to PostgreSQL update synchronization worker.
    | This worker monitors the MySQL alert_pg_update_log table and propagates
    | changes to the PostgreSQL alerts table.
    |
    | Requirements: 5.1, 6.1, 6.5
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Poll Interval
    |--------------------------------------------------------------------------
    |
    | The number of seconds to wait between polling cycles when no pending
    | entries are found. Lower values provide faster synchronization but
    | consume more resources.
    |
    | Default: 5 seconds
    | Requirement: 6.5
    |
    */

    'poll_interval' => env('UPDATE_SYNC_POLL_INTERVAL', 5),

    /*
    |--------------------------------------------------------------------------
    | Batch Size
    |--------------------------------------------------------------------------
    |
    | The maximum number of log entries to process in a single batch.
    | Larger batches improve throughput but increase memory usage and
    | transaction duration.
    |
    | Default: 100 entries
    | Requirement: 6.1
    |
    */

    'batch_size' => env('UPDATE_SYNC_BATCH_SIZE', 100),

    /*
    |--------------------------------------------------------------------------
    | Max Retries
    |--------------------------------------------------------------------------
    |
    | The maximum number of retry attempts for failed operations before
    | marking a log entry as permanently failed.
    |
    | Default: 3 retries
    | Requirement: 5.1
    |
    */

    'max_retries' => env('UPDATE_SYNC_MAX_RETRIES', 3),

    /*
    |--------------------------------------------------------------------------
    | Retry Backoff Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for exponential backoff retry strategy.
    |
    | - base_delay: Initial delay in milliseconds before first retry
    | - multiplier: Factor by which delay increases for each retry
    | - max_delay: Maximum delay in milliseconds (cap for exponential growth)
    |
    | Delay calculation: min(base_delay * (multiplier ^ attempt), max_delay)
    |
    | Example with defaults:
    | - Attempt 1: 1000ms (1s)
    | - Attempt 2: 2000ms (2s)
    | - Attempt 3: 4000ms (4s)
    | - Attempt 4+: 60000ms (60s, capped)
    |
    | Requirement: 5.1
    |
    */

    'retry_backoff' => [
        'base_delay' => env('UPDATE_SYNC_RETRY_BASE_DELAY', 1000), // milliseconds
        'multiplier' => env('UPDATE_SYNC_RETRY_MULTIPLIER', 2),
        'max_delay' => env('UPDATE_SYNC_RETRY_MAX_DELAY', 60000), // milliseconds
    ],

];
