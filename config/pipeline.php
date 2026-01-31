<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Use Tracking Table (V2 Sync)
    |--------------------------------------------------------------------------
    |
    | When enabled, uses a dedicated sync_tracking table to track which records
    | have been synced instead of modifying MySQL source tables.
    |
    | Benefits:
    | - NO modification to MySQL source tables (completely read-only)
    | - NO extra columns added to PostgreSQL target tables
    | - Clean separation of sync metadata from actual data
    |
    | Set to false to use V1 sync (modifies MySQL with synced_at column).
    |
    */
    'use_tracking_table' => env('SYNC_USE_TRACKING_TABLE', true),

    /*
    |--------------------------------------------------------------------------
    | Batch Size Configuration
    |--------------------------------------------------------------------------
    |
    | The number of records to process in each sync batch. This should be
    | between 10,000 and 50,000 to balance throughput and memory usage.
    |
    | Requirements: 6.1
    |
    */
    'batch_size' => env('PIPELINE_BATCH_SIZE', 10000),

    /*
    |--------------------------------------------------------------------------
    | Minimum and Maximum Batch Size
    |--------------------------------------------------------------------------
    |
    | Constraints for batch size configuration to prevent misconfiguration.
    |
    */
    'batch_size_min' => 1000,
    'batch_size_max' => 50000,

    /*
    |--------------------------------------------------------------------------
    | Sync Schedule
    |--------------------------------------------------------------------------
    |
    | The cron expression for when the sync job should run.
    | Default: every 15 minutes
    |
    | Requirements: 6.2
    |
    */
    'sync_schedule' => env('PIPELINE_SYNC_SCHEDULE', '*/15 * * * *'),

    /*
    |--------------------------------------------------------------------------
    | Sync Enabled
    |--------------------------------------------------------------------------
    |
    | Whether automatic sync scheduling is enabled.
    |
    */
    'sync_enabled' => env('PIPELINE_SYNC_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Date-Partitioned Sync Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the date-partitioned sync system that syncs alerts
    | to date-specific PostgreSQL tables (e.g., alerts_2026_01_08).
    |
    | Requirements: 5.1
    |
    */
    'partitioned_sync_enabled' => env('PIPELINE_PARTITIONED_SYNC_ENABLED', false),
    'partitioned_sync_max_batches' => env('PIPELINE_PARTITIONED_SYNC_MAX_BATCHES', 5),

    /*
    |--------------------------------------------------------------------------
    | Cleanup Schedule
    |--------------------------------------------------------------------------
    |
    | The cron expression for when the cleanup job should run.
    | Default: daily at 2 AM
    |
    | Requirements: 6.4
    |
    */
    'cleanup_schedule' => env('PIPELINE_CLEANUP_SCHEDULE', '0 2 * * *'),

    /*
    |--------------------------------------------------------------------------
    | Retention Period
    |--------------------------------------------------------------------------
    |
    | Number of days to retain synced records in MySQL before cleanup.
    | Records must be verified before they can be cleaned up.
    |
    | Requirements: 6.3
    |
    */
    'retention_days' => env('PIPELINE_RETENTION_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Minimum and Maximum Retention Days
    |--------------------------------------------------------------------------
    |
    | Constraints for retention period to prevent misconfiguration.
    |
    */
    'retention_days_min' => 1,
    'retention_days_max' => 365,

    /*
    |--------------------------------------------------------------------------
    | Cleanup Batch Size
    |--------------------------------------------------------------------------
    |
    | Number of records to delete in each cleanup batch.
    | Smaller batches prevent long locks on the MySQL alerts table.
    |
    */
    'cleanup_batch_size' => env('PIPELINE_CLEANUP_BATCH_SIZE', 1000),

    /*
    |--------------------------------------------------------------------------
    | Cleanup Enabled
    |--------------------------------------------------------------------------
    |
    | Whether automatic cleanup is enabled. DISABLED by default for safety.
    | Cleanup should only be enabled after careful consideration.
    |
    | ⚠️ WARNING: Enabling cleanup will DELETE records from MySQL alerts table!
    |
    */
    'cleanup_enabled' => env('PIPELINE_CLEANUP_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for retry behavior on connection failures.
    |
    */
    'retry' => [
        'max_attempts' => env('PIPELINE_MAX_RETRY_ATTEMPTS', 5),
        'initial_delay_seconds' => env('PIPELINE_RETRY_INITIAL_DELAY', 1),
        'max_delay_seconds' => env('PIPELINE_RETRY_MAX_DELAY', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum runtime in seconds for sync jobs before checkpointing.
    |
    */
    'job_timeout' => env('PIPELINE_JOB_TIMEOUT', 3600), // 1 hour

    /*
    |--------------------------------------------------------------------------
    | Alert Thresholds
    |--------------------------------------------------------------------------
    |
    | Thresholds for triggering alerts on sync failures.
    |
    | Requirements: 6.1 (alert thresholds configuration)
    |
    */
    'alerts' => [
        'warning_failures' => env('PIPELINE_WARNING_FAILURES', 3),
        'critical_failures' => env('PIPELINE_CRITICAL_FAILURES', 5),
        'max_sync_lag_minutes' => env('PIPELINE_MAX_SYNC_LAG', 60),
        'email_notifications' => env('PIPELINE_EMAIL_NOTIFICATIONS', false),
        'notification_email' => env('PIPELINE_NOTIFICATION_EMAIL', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Partition Failure Alert Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for partition sync failure alerting.
    |
    | Requirements: 8.5
    |
    */
    'partition_failure_threshold' => env('PIPELINE_PARTITION_FAILURE_THRESHOLD', 10),
    'partition_failure_window' => env('PIPELINE_PARTITION_FAILURE_WINDOW', 60), // minutes
    'partition_alert_cooldown' => env('PIPELINE_PARTITION_ALERT_COOLDOWN', 30), // minutes

    /*
    |--------------------------------------------------------------------------
    | Verification Schedule
    |--------------------------------------------------------------------------
    |
    | The cron expression for when the verification job should run.
    | Default: every 30 minutes (after sync completion)
    |
    */
    'verify_schedule' => env('PIPELINE_VERIFY_SCHEDULE', '*/30 * * * *'),

    /*
    |--------------------------------------------------------------------------
    | Verification Enabled
    |--------------------------------------------------------------------------
    |
    | Whether automatic verification scheduling is enabled.
    |
    */
    'verify_enabled' => env('PIPELINE_VERIFY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Off-Peak Hours Configuration
    |--------------------------------------------------------------------------
    |
    | Define off-peak hours for running intensive operations.
    | Format: 24-hour time (0-23)
    |
    | Requirements: 1.6, 4.5
    |
    */
    'off_peak' => [
        'start_hour' => env('PIPELINE_OFF_PEAK_START', 22), // 10 PM
        'end_hour' => env('PIPELINE_OFF_PEAK_END', 6),      // 6 AM
        'prefer_off_peak' => env('PIPELINE_PREFER_OFF_PEAK', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime Configuration Storage
    |--------------------------------------------------------------------------
    |
    | Whether to allow runtime configuration changes via API.
    | When enabled, configuration can be changed without restart.
    |
    | Requirements: 6.6
    |
    */
    'allow_runtime_config' => env('PIPELINE_ALLOW_RUNTIME_CONFIG', true),

    /*
    |--------------------------------------------------------------------------
    | Configuration Cache Key
    |--------------------------------------------------------------------------
    |
    | Cache key for storing runtime configuration overrides.
    |
    */
    'config_cache_key' => 'pipeline_config_overrides',

    /*
    |--------------------------------------------------------------------------
    | Configuration Cache TTL
    |--------------------------------------------------------------------------
    |
    | How long to cache runtime configuration (in seconds).
    | Set to null for no expiration.
    |
    */
    'config_cache_ttl' => null,
];
