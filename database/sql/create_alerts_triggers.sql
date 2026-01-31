-- ============================================
-- Alerts Triggers for PostgreSQL Sync
-- ============================================
-- These triggers track changes to the alerts table
-- and log them to alert_pg_update_log for sync processing

-- Drop existing triggers if they exist
DROP TRIGGER IF EXISTS alerts_after_insert;
DROP TRIGGER IF EXISTS alerts_after_update;
DROP TRIGGER IF EXISTS alerts_after_delete;
DROP TRIGGER IF EXISTS trg_alert_status_change;

DELIMITER $$

-- Insert Trigger: Log new alerts
CREATE TRIGGER alerts_after_insert
AFTER INSERT ON alerts
FOR EACH ROW
BEGIN
    INSERT INTO alert_pg_update_log (alert_id, status, created_at)
    VALUES (NEW.id, 1, NOW());
END$$

-- Update Trigger: Log alert changes (including status changes from O to C)
CREATE TRIGGER alerts_after_update
AFTER UPDATE ON alerts
FOR EACH ROW
BEGIN
    INSERT INTO alert_pg_update_log (alert_id, status, created_at)
    VALUES (NEW.id, 1, NOW());
END$$

-- Delete Trigger: Log alert deletions
CREATE TRIGGER alerts_after_delete
AFTER DELETE ON alerts
FOR EACH ROW
BEGIN
    INSERT INTO alert_pg_update_log (alert_id, status, created_at)
    VALUES (OLD.id, 1, NOW());
END$$

DELIMITER ;

-- Show created triggers
SHOW TRIGGERS LIKE 'alerts';

-- Test query to verify table structure
DESCRIBE alert_pg_update_log;

-- Verify triggers are working
-- After running this script, test with:
-- UPDATE alerts SET status='C' WHERE id=<some_id> AND status='O';
-- Then check: SELECT * FROM alert_pg_update_log WHERE alert_id=<some_id> ORDER BY created_at DESC LIMIT 1;
