<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sites Update Sync Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the MySQL to PostgreSQL sites synchronization worker.
    | This worker monitors the MySQL sites_pg_update_log table and propagates
    | changes to the PostgreSQL sites, dvrsite, and dvronline tables.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Poll Interval
    |--------------------------------------------------------------------------
    |
    | The number of seconds to wait between polling cycles when no pending
    | entries are found.
    |
    | Default: 5 seconds
    |
    */

    'poll_interval' => env('SITES_SYNC_POLL_INTERVAL', 5),

    /*
    |--------------------------------------------------------------------------
    | Batch Size
    |--------------------------------------------------------------------------
    |
    | The maximum number of log entries to process in a single batch.
    |
    | Default: 100 entries
    |
    */

    'batch_size' => env('SITES_SYNC_BATCH_SIZE', 100),

    /*
    |--------------------------------------------------------------------------
    | Max Retries
    |--------------------------------------------------------------------------
    |
    | The maximum number of retry attempts for failed operations before
    | marking a log entry as permanently failed.
    |
    | Default: 3 retries
    |
    */

    'max_retries' => env('SITES_SYNC_MAX_RETRIES', 3),

];
