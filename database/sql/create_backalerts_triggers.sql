-- ============================================
-- BackAlerts Triggers for PostgreSQL Sync
-- ============================================
-- These triggers track changes to the backalerts table
-- and log them to backalert_pg_update_log for sync processing
-- Simplified structure matching alert_pg_update_log

-- Drop existing triggers if they exist
DROP TRIGGER IF EXISTS backalerts_after_insert;
DROP TRIGGER IF EXISTS backalerts_after_update;
DROP TRIGGER IF EXISTS backalerts_after_delete;

DELIMITER $$

-- Insert Trigger: Log new backalerts
CREATE TRIGGER backalerts_after_insert
AFTER INSERT ON backalerts
FOR EACH ROW
BEGIN
    INSERT INTO backalert_pg_update_log (backalert_id, status, created_at)
    VALUES (NEW.id, 1, NOW());
END$$

-- Update Trigger: Log backalert changes
CREATE TRIGGER backalerts_after_update
AFTER UPDATE ON backalerts
FOR EACH ROW
BEGIN
    INSERT INTO backalert_pg_update_log (backalert_id, status, created_at)
    VALUES (NEW.id, 1, NOW());
END$$

-- Delete Trigger: Log backalert deletions
CREATE TRIGGER backalerts_after_delete
AFTER DELETE ON backalerts
FOR EACH ROW
BEGIN
    INSERT INTO backalert_pg_update_log (backalert_id, status, created_at)
    VALUES (OLD.id, 1, NOW());
END$$

DELIMITER ;

-- Show created triggers
SHOW TRIGGERS LIKE 'backalerts';

-- Test query to verify table structure
DESCRIBE backalert_pg_update_log;