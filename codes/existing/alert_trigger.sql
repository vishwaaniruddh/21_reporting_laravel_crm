CREATE TRIGGER `trg_alert_status_change` AFTER UPDATE ON `alerts`
 FOR EACH ROW BEGIN
    IF NEW.status = 'C'
       AND NEW.sendtoclient = 'S'
       AND OLD.status <> 'C' THEN
        INSERT INTO alert_pg_update_log (
            alert_id,
            status,
            created_at,
            updated_at
        )
        VALUES (
            NEW.id,
            1,
            NOW(),
            NULL
        );
    END IF;
END