-- =====================================================
-- MySQL Triggers for Sites Sync
-- =====================================================
-- These triggers automatically log INSERT/UPDATE operations
-- to sites_pg_update_log table for PostgreSQL synchronization
-- =====================================================

-- =====================================================
-- SITES TABLE TRIGGERS
-- =====================================================

DELIMITER $$

-- Trigger: After INSERT on sites
DROP TRIGGER IF EXISTS `sites_after_insert`$$
CREATE TRIGGER `sites_after_insert`
AFTER INSERT ON `sites`
FOR EACH ROW
BEGIN
    INSERT INTO `sites_pg_update_log` 
        (`table_name`, `record_id`, `operation`, `status`, `created_at`)
    VALUES 
        ('sites', NEW.SN, 'INSERT', 1, NOW());
END$$

-- Trigger: After UPDATE on sites
DROP TRIGGER IF EXISTS `sites_after_update`$$
CREATE TRIGGER `sites_after_update`
AFTER UPDATE ON `sites`
FOR EACH ROW
BEGIN
    -- Log ALL updates (sync every column change)
    -- Exclude only synced_at column to avoid infinite loops
    IF NOT (
        OLD.Status <=> NEW.Status AND
        OLD.Phase <=> NEW.Phase AND
        OLD.Customer <=> NEW.Customer AND
        OLD.Bank <=> NEW.Bank AND
        OLD.ATMID <=> NEW.ATMID AND
        OLD.ATMID_2 <=> NEW.ATMID_2 AND
        OLD.ATMID_3 <=> NEW.ATMID_3 AND
        OLD.ATMID_4 <=> NEW.ATMID_4 AND
        OLD.TrackerNo <=> NEW.TrackerNo AND
        OLD.ATMShortName <=> NEW.ATMShortName AND
        OLD.SiteAddress <=> NEW.SiteAddress AND
        OLD.City <=> NEW.City AND
        OLD.State <=> NEW.State AND
        OLD.Zone <=> NEW.Zone AND
        OLD.Panel_Make <=> NEW.Panel_Make AND
        OLD.OldPanelID <=> NEW.OldPanelID AND
        OLD.NewPanelID <=> NEW.NewPanelID AND
        OLD.DVRIP <=> NEW.DVRIP AND
        OLD.DVRName <=> NEW.DVRName AND
        OLD.DVR_Model_num <=> NEW.DVR_Model_num AND
        OLD.Router_Model_num <=> NEW.Router_Model_num AND
        OLD.UserName <=> NEW.UserName AND
        OLD.Password <=> NEW.Password AND
        OLD.live <=> NEW.live AND
        OLD.current_dt <=> NEW.current_dt AND
        OLD.mailreceive_dt <=> NEW.mailreceive_dt AND
        OLD.eng_name <=> NEW.eng_name AND
        OLD.addedby <=> NEW.addedby AND
        OLD.editby <=> NEW.editby AND
        OLD.site_remark <=> NEW.site_remark AND
        OLD.PanelIP <=> NEW.PanelIP AND
        OLD.AlertType <=> NEW.AlertType AND
        OLD.live_date <=> NEW.live_date AND
        OLD.RouterIp <=> NEW.RouterIp AND
        OLD.last_modified <=> NEW.last_modified AND
        OLD.partial_live <=> NEW.partial_live AND
        OLD.CTS_LocalBranch <=> NEW.CTS_LocalBranch AND
        OLD.installationDate <=> NEW.installationDate AND
        OLD.old_atmid <=> NEW.old_atmid AND
        OLD.auto_alert <=> NEW.auto_alert AND
        OLD.project <=> NEW.project AND
        OLD.comfortID <=> NEW.comfortID AND
        OLD.panel_power_connection <=> NEW.panel_power_connection AND
        OLD.router_port <=> NEW.router_port AND
        OLD.dvr_port <=> NEW.dvr_port AND
        OLD.panel_port <=> NEW.panel_port AND
        OLD.server_ip <=> NEW.server_ip AND
        OLD.unique_id <=> NEW.unique_id AND
        OLD.isSDK <=> NEW.isSDK AND
        OLD.isAPI <=> NEW.isAPI
        -- Exclude synced_at from comparison to avoid trigger loops
    ) THEN
        INSERT INTO `sites_pg_update_log` 
            (`table_name`, `record_id`, `operation`, `status`, `created_at`)
        VALUES 
            ('sites', NEW.SN, 'UPDATE', 1, NOW());
    END IF;
END$$

-- =====================================================
-- DVRSITE TABLE TRIGGERS
-- =====================================================

-- Trigger: After INSERT on dvrsite
DROP TRIGGER IF EXISTS `dvrsite_after_insert`$$
CREATE TRIGGER `dvrsite_after_insert`
AFTER INSERT ON `dvrsite`
FOR EACH ROW
BEGIN
    INSERT INTO `sites_pg_update_log` 
        (`table_name`, `record_id`, `operation`, `status`, `created_at`)
    VALUES 
        ('dvrsite', NEW.SN, 'INSERT', 1, NOW());
END$$

-- Trigger: After UPDATE on dvrsite
DROP TRIGGER IF EXISTS `dvrsite_after_update`$$
CREATE TRIGGER `dvrsite_after_update`
AFTER UPDATE ON `dvrsite`
FOR EACH ROW
BEGIN
    -- Log ALL updates (sync every column change)
    IF NOT (
        OLD.Status <=> NEW.Status AND
        OLD.Phase <=> NEW.Phase AND
        OLD.Customer <=> NEW.Customer AND
        OLD.Bank <=> NEW.Bank AND
        OLD.ATMID <=> NEW.ATMID AND
        OLD.ATMID_2 <=> NEW.ATMID_2 AND
        OLD.ATMID_3 <=> NEW.ATMID_3 AND
        OLD.ATMID_4 <=> NEW.ATMID_4 AND
        OLD.TrackerNo <=> NEW.TrackerNo AND
        OLD.ATMShortName <=> NEW.ATMShortName AND
        OLD.SiteAddress <=> NEW.SiteAddress AND
        OLD.City <=> NEW.City AND
        OLD.State <=> NEW.State AND
        OLD.Zone <=> NEW.Zone AND
        OLD.DVRIP <=> NEW.DVRIP AND
        OLD.DVRName <=> NEW.DVRName AND
        OLD.DVR_Model_num <=> NEW.DVR_Model_num AND
        OLD.DVR_Serial_num <=> NEW.DVR_Serial_num AND
        OLD.CTSLocalBranch <=> NEW.CTSLocalBranch AND
        OLD.CTS_BM_Name <=> NEW.CTS_BM_Name AND
        OLD.CTS_BM_Number <=> NEW.CTS_BM_Number AND
        OLD.HDD <=> NEW.HDD AND
        OLD.Camera1 <=> NEW.Camera1 AND
        OLD.Camera2 <=> NEW.Camera2 AND
        OLD.Camera3 <=> NEW.Camera3 AND
        OLD.Attachment1 <=> NEW.Attachment1 AND
        OLD.Attachment2 <=> NEW.Attachment2 AND
        OLD.liveDate <=> NEW.liveDate AND
        OLD.install_Status <=> NEW.install_Status AND
        OLD.UserName <=> NEW.UserName AND
        OLD.Password <=> NEW.Password AND
        OLD.live <=> NEW.live AND
        OLD.current_dt <=> NEW.current_dt AND
        OLD.mailreceive_dt <=> NEW.mailreceive_dt AND
        OLD.addedby <=> NEW.addedby AND
        OLD.editby <=> NEW.editby AND
        OLD.site_remark <=> NEW.site_remark AND
        OLD.PanelIP <=> NEW.PanelIP AND
        OLD.last_modified <=> NEW.last_modified AND
        OLD.old_atmid <=> NEW.old_atmid AND
        OLD.installationDate <=> NEW.installationDate AND
        OLD.project <=> NEW.project AND
        OLD.unique_id <=> NEW.unique_id
        -- Exclude synced_at from comparison if it exists
    ) THEN
        INSERT INTO `sites_pg_update_log` 
            (`table_name`, `record_id`, `operation`, `status`, `created_at`)
        VALUES 
            ('dvrsite', NEW.SN, 'UPDATE', 1, NOW());
    END IF;
END$$

-- =====================================================
-- DVRONLINE TABLE TRIGGERS
-- =====================================================

-- Trigger: After INSERT on dvronline
DROP TRIGGER IF EXISTS `dvronline_after_insert`$$
CREATE TRIGGER `dvronline_after_insert`
AFTER INSERT ON `dvronline`
FOR EACH ROW
BEGIN
    INSERT INTO `sites_pg_update_log` 
        (`table_name`, `record_id`, `operation`, `status`, `created_at`)
    VALUES 
        ('dvronline', NEW.id, 'INSERT', 1, NOW());
END$$

-- Trigger: After UPDATE on dvronline
DROP TRIGGER IF EXISTS `dvronline_after_update`$$
CREATE TRIGGER `dvronline_after_update`
AFTER UPDATE ON `dvronline`
FOR EACH ROW
BEGIN
    -- Log ALL updates (sync every column change)
    IF NOT (
        OLD.ATMID <=> NEW.ATMID AND
        OLD.Address <=> NEW.Address AND
        OLD.Location <=> NEW.Location AND
        OLD.State <=> NEW.State AND
        OLD.IPAddress <=> NEW.IPAddress AND
        OLD.`Rourt ID` <=> NEW.`Rourt ID` AND
        OLD.LiveDate <=> NEW.LiveDate AND
        OLD.UserName <=> NEW.UserName AND
        OLD.Password <=> NEW.Password AND
        OLD.Status <=> NEW.Status AND
        OLD.dvrname <=> NEW.dvrname AND
        OLD.customer <=> NEW.customer AND
        OLD.Bank <=> NEW.Bank AND
        OLD.ATMID2 <=> NEW.ATMID2 AND
        OLD.remark <=> NEW.remark AND
        OLD.zone <=> NEW.zone AND
        OLD.city <=> NEW.city AND
        OLD.old_atm <=> NEW.old_atm AND
        OLD.installationDate <=> NEW.installationDate AND
        OLD.project <=> NEW.project AND
        OLD.sn <=> NEW.sn AND
        OLD.unique_id <=> NEW.unique_id
        -- Exclude synced_at from comparison if it exists
    ) THEN
        INSERT INTO `sites_pg_update_log` 
            (`table_name`, `record_id`, `operation`, `status`, `created_at`)
        VALUES 
            ('dvronline', NEW.id, 'UPDATE', 1, NOW());
    END IF;
END$$

DELIMITER ;

-- =====================================================
-- Verification Queries
-- =====================================================

-- Check if triggers were created
SELECT 
    TRIGGER_NAME,
    EVENT_MANIPULATION,
    EVENT_OBJECT_TABLE,
    ACTION_TIMING
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
AND EVENT_OBJECT_TABLE IN ('sites', 'dvrsite', 'dvronline')
ORDER BY EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION;

-- Check log table structure
DESCRIBE sites_pg_update_log;
