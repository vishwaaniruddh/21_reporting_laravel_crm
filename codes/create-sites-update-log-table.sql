-- =====================================================
-- Sites Update Log Table for PostgreSQL Sync
-- =====================================================
-- This table tracks INSERT/UPDATE operations on sites, dvrsite, dvronline
-- Similar to alert_pg_update_log for alerts sync
-- =====================================================

CREATE TABLE IF NOT EXISTS `sites_pg_update_log` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `table_name` VARCHAR(50) NOT NULL COMMENT 'Source table: sites, dvrsite, or dvronline',
    `record_id` INT(11) NOT NULL COMMENT 'Primary key value (SN or id)',
    `operation` VARCHAR(10) NOT NULL COMMENT 'INSERT or UPDATE',
    `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=pending, 2=completed, 3=failed',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    `error_message` TEXT NULL,
    `retry_count` INT(11) NOT NULL DEFAULT 0,
    INDEX `idx_status_created` (`status`, `created_at`),
    INDEX `idx_table_record` (`table_name`, `record_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Tracks sites/dvrsite/dvronline changes for PostgreSQL sync';
