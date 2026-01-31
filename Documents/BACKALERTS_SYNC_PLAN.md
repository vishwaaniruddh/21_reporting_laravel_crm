# BackAlerts Sync System Plan

## Overview
Create a complete sync system for the `backalerts` MySQL table to PostgreSQL partitioned tables, similar to the existing alerts sync system but with separate tracking and no deletion from MySQL.

## Current System Analysis

### Existing Alerts Sync Components:
1. **MySQL Table**: `alerts` → **PostgreSQL**: `alerts_YYYY_MM_DD` partitions
2. **Update Tracking**: `alert_pg_update_log` table
3. **Service**: `AlertUpdateSync` (sync:update-worker)
4. **Commands**: `sync:update-worker`, `sync:partitioned`

### New BackAlerts Sync Requirements:
1. **MySQL Table**: `backalerts` → **PostgreSQL**: `backalerts_YYYY_MM_DD` partitions
2. **Update Tracking**: `backalert_pg_update_log` table (NEW)
3. **Service**: `BackAlertUpdateSync` (NEW)
4. **Commands**: `backalerts:update-worker`, `backalerts:partitioned` (NEW)
5. **No Deletion**: Keep all records in MySQL `backalerts` table

## Implementation Plan

### Phase 1: Database Setup

#### 1.1 Create Update Log Table
```sql
CREATE TABLE backalert_pg_update_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    backalert_id INT NOT NULL,
    operation_type ENUM('insert', 'update', 'delete') NOT NULL,
    old_data JSON NULL,
    new_data JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status TINYINT DEFAULT 1 COMMENT '1=pending, 2=completed, 3=failed',
    processed_at TIMESTAMP NULL,
    error_message TEXT NULL,
    retry_count INT DEFAULT 0,
    INDEX idx_status_created (status, created_at),
    INDEX idx_backalert_id (backalert_id),
    INDEX idx_created_at (created_at)
);
```

#### 1.2 Create Triggers on backalerts Table
```sql
-- Insert Trigger
DELIMITER $$
CREATE TRIGGER backalerts_after_insert
AFTER INSERT ON backalerts
FOR EACH ROW
BEGIN
    INSERT INTO backalert_pg_update_log (backalert_id, operation_type, new_data)
    VALUES (NEW.id, 'insert', JSON_OBJECT(
        'id', NEW.id,
        'panelid', NEW.panelid,
        'seqno', NEW.seqno,
        'zone', NEW.zone,
        'alarm', NEW.alarm,
        'createtime', NEW.createtime,
        'receivedtime', NEW.receivedtime,
        'comment', NEW.comment,
        'status', NEW.status,
        'sendtoclient', NEW.sendtoclient,
        'closedBy', NEW.closedBy,
        'closedtime', NEW.closedtime,
        'sendip', NEW.sendip,
        'alerttype', NEW.alerttype,
        'location', NEW.location,
        'priority', NEW.priority,
        'AlertUserStatus', NEW.AlertUserStatus,
        'level', NEW.level,
        'sip2', NEW.sip2,
        'c_status', NEW.c_status,
        'auto_alert', NEW.auto_alert,
        'critical_alerts', NEW.critical_alerts,
        'Readstatus', NEW.Readstatus
    ));
END$$

-- Update Trigger
CREATE TRIGGER backalerts_after_update
AFTER UPDATE ON backalerts
FOR EACH ROW
BEGIN
    INSERT INTO backalert_pg_update_log (backalert_id, operation_type, old_data, new_data)
    VALUES (NEW.id, 'update', 
        JSON_OBJECT(
            'id', OLD.id,
            'panelid', OLD.panelid,
            'seqno', OLD.seqno,
            'zone', OLD.zone,
            'alarm', OLD.alarm,
            'createtime', OLD.createtime,
            'receivedtime', OLD.receivedtime,
            'comment', OLD.comment,
            'status', OLD.status,
            'sendtoclient', OLD.sendtoclient,
            'closedBy', OLD.closedBy,
            'closedtime', OLD.closedtime,
            'sendip', OLD.sendip,
            'alerttype', OLD.alerttype,
            'location', OLD.location,
            'priority', OLD.priority,
            'AlertUserStatus', OLD.AlertUserStatus,
            'level', OLD.level,
            'sip2', OLD.sip2,
            'c_status', OLD.c_status,
            'auto_alert', OLD.auto_alert,
            'critical_alerts', OLD.critical_alerts,
            'Readstatus', OLD.Readstatus
        ),
        JSON_OBJECT(
            'id', NEW.id,
            'panelid', NEW.panelid,
            'seqno', NEW.seqno,
            'zone', NEW.zone,
            'alarm', NEW.alarm,
            'createtime', NEW.createtime,
            'receivedtime', NEW.receivedtime,
            'comment', NEW.comment,
            'status', NEW.status,
            'sendtoclient', NEW.sendtoclient,
            'closedBy', NEW.closedBy,
            'closedtime', NEW.closedtime,
            'sendip', NEW.sendip,
            'alerttype', NEW.alerttype,
            'location', NEW.location,
            'priority', NEW.priority,
            'AlertUserStatus', NEW.AlertUserStatus,
            'level', NEW.level,
            'sip2', NEW.sip2,
            'c_status', NEW.c_status,
            'auto_alert', NEW.auto_alert,
            'critical_alerts', NEW.critical_alerts,
            'Readstatus', NEW.Readstatus
        )
    );
END$$

-- Delete Trigger
CREATE TRIGGER backalerts_after_delete
AFTER DELETE ON backalerts
FOR EACH ROW
BEGIN
    INSERT INTO backalert_pg_update_log (backalert_id, operation_type, old_data)
    VALUES (OLD.id, 'delete', JSON_OBJECT(
        'id', OLD.id,
        'panelid', OLD.panelid,
        'seqno', OLD.seqno,
        'zone', OLD.zone,
        'alarm', OLD.alarm,
        'createtime', OLD.createtime,
        'receivedtime', OLD.receivedtime,
        'comment', OLD.comment,
        'status', OLD.status,
        'sendtoclient', OLD.sendtoclient,
        'closedBy', OLD.closedBy,
        'closedtime', OLD.closedtime,
        'sendip', OLD.sendip,
        'alerttype', OLD.alerttype,
        'location', OLD.location,
        'priority', OLD.priority,
        'AlertUserStatus', OLD.AlertUserStatus,
        'level', OLD.level,
        'sip2', OLD.sip2,
        'c_status', OLD.c_status,
        'auto_alert', OLD.auto_alert,
        'critical_alerts', OLD.critical_alerts,
        'Readstatus', OLD.Readstatus
    ));
END$$
DELIMITER ;
```

### Phase 2: Laravel Models & Services

#### 2.1 Create BackAlert Model
```php
// app/Models/BackAlert.php
class BackAlert extends Model
{
    protected $table = 'backalerts';
    protected $connection = 'mysql';
    // ... similar to Alert model
}
```

#### 2.2 Create BackAlertUpdateLog Model
```php
// app/Models/BackAlertUpdateLog.php
class BackAlertUpdateLog extends Model
{
    protected $table = 'backalert_pg_update_log';
    protected $connection = 'mysql';
    // ... similar to AlertUpdateLog model
}
```

#### 2.3 Create BackAlertSyncService
```php
// app/Services/BackAlertSyncService.php
// Similar to AlertSyncService but for backalerts
```

### Phase 3: Console Commands

#### 3.1 BackAlert Update Worker Command
```php
// app/Console/Commands/BackAlertUpdateWorker.php
// Similar to UpdateSyncWorker but for backalerts
// Command: backalerts:update-worker
```

#### 3.2 BackAlert Partitioned Sync Command
```php
// app/Console/Commands/BackAlertPartitionedSync.php
// Similar to RunPartitionedSyncJob but for backalerts
// Command: backalerts:partitioned
```

#### 3.3 BackAlert Initial Sync Command
```php
// app/Console/Commands/BackAlertInitialSync.php
// For syncing existing backalerts data
// Command: backalerts:initial-sync
```

### Phase 4: NSSM Services

#### 4.1 BackAlertUpdateSync Service
```powershell
nssm install BackAlertUpdateSync "C:\wamp64\bin\php\php8.4.11\php.exe"
nssm set BackAlertUpdateSync AppParameters "artisan backalerts:update-worker --poll-interval=5 --batch-size=100"
nssm set BackAlertUpdateSync AppDirectory "C:\wamp64\www\comfort_reporting_crm\dual-database-app"
nssm set BackAlertUpdateSync DisplayName "BackAlert Update Sync Worker"
nssm set BackAlertUpdateSync Start SERVICE_AUTO_START
```

#### 4.2 BackAlertInitialSync Service (Temporary)
```powershell
nssm install BackAlertInitialSync "C:\wamp64\bin\php\php8.4.11\php.exe"
nssm set BackAlertInitialSync AppParameters "artisan backalerts:initial-sync --continuous"
nssm set BackAlertInitialSync AppDirectory "C:\wamp64\www\comfort_reporting_crm\dual-database-app"
nssm set BackAlertInitialSync DisplayName "BackAlert Initial Sync Worker (Temporary)"
nssm set BackAlertInitialSync Start SERVICE_DEMAND_START
```

### Phase 5: PostgreSQL Partition Tables

#### 5.1 Partition Table Structure
```sql
-- Example: backalerts_2026_01_27
CREATE TABLE backalerts_2026_01_27 (
    id BIGINT PRIMARY KEY,
    panelid VARCHAR(255),
    seqno VARCHAR(255),
    zone VARCHAR(255),
    alarm VARCHAR(255),
    createtime TIMESTAMP,
    receivedtime TIMESTAMP,
    comment TEXT,
    status VARCHAR(255),
    sendtoclient VARCHAR(255),
    closedBy VARCHAR(255),
    closedtime TIMESTAMP,
    sendip VARCHAR(255),
    alerttype VARCHAR(255),
    location VARCHAR(255),
    priority VARCHAR(255),
    AlertUserStatus VARCHAR(255),
    level INTEGER,
    sip2 VARCHAR(255),
    c_status VARCHAR(255),
    auto_alert VARCHAR(255),
    critical_alerts VARCHAR(255),
    Readstatus VARCHAR(255),
    synced_at TIMESTAMP,
    sync_batch_id INTEGER NOT NULL
);

CREATE INDEX idx_backalerts_2026_01_27_receivedtime ON backalerts_2026_01_27(receivedtime);
CREATE INDEX idx_backalerts_2026_01_27_panelid ON backalerts_2026_01_27(panelid);
CREATE INDEX idx_backalerts_2026_01_27_status ON backalerts_2026_01_27(status);
```

## Implementation Steps

### Step 1: Database Setup (Immediate)
1. Create `backalert_pg_update_log` table
2. Create triggers on `backalerts` table
3. Test triggers with sample data

### Step 2: Laravel Components (Day 1)
1. Create BackAlert model
2. Create BackAlertUpdateLog model
3. Create BackAlertSyncService
4. Test basic functionality

### Step 3: Console Commands (Day 2)
1. Create backalerts:update-worker command
2. Create backalerts:partitioned command
3. Create backalerts:initial-sync command
4. Test commands manually

### Step 4: Services Setup (Day 3)
1. Create BackAlertUpdateSync NSSM service
2. Create BackAlertInitialSync NSSM service
3. Test services
4. Monitor logs

### Step 5: Production Deployment (Day 4)
1. Start BackAlertUpdateSync service
2. Run initial sync for existing data
3. Monitor performance
4. Verify data integrity

## Key Differences from Alerts Sync

### ✅ Similarities:
- Partitioned PostgreSQL tables by date
- Update log tracking system
- Trigger-based change capture
- Batch processing
- Error handling and retry logic

### 🔄 Differences:
- **Table Names**: `backalerts` → `backalerts_YYYY_MM_DD`
- **Log Table**: `backalert_pg_update_log`
- **Services**: `BackAlertUpdateSync`, `BackAlertInitialSync`
- **Commands**: `backalerts:*` prefix
- **No Deletion**: Keep all MySQL records (unlike alerts_all_data)

## Monitoring & Maintenance

### Status Commands:
```bash
# Check backalert update log status
php artisan tinker --execute="
    \$pending = DB::connection('mysql')->table('backalert_pg_update_log')->where('status', 1)->count();
    \$completed = DB::connection('mysql')->table('backalert_pg_update_log')->where('status', 2)->count();
    \$failed = DB::connection('mysql')->table('backalert_pg_update_log')->where('status', 3)->count();
    echo 'Pending: ' . number_format(\$pending) . PHP_EOL;
    echo 'Completed: ' . number_format(\$completed) . PHP_EOL;
    echo 'Failed: ' . number_format(\$failed) . PHP_EOL;
"

# Check partition sync status
php artisan backalerts:partitioned --status
```

### Service Management:
```powershell
# Check services
Get-Service BackAlert*

# Monitor logs
Get-Content storage\logs\backalert-update-sync-service.log -Tail 20 -Wait
Get-Content storage\logs\backalert-initial-sync-service.log -Tail 20 -Wait
```

## Estimated Timeline
- **Database Setup**: 2-4 hours
- **Laravel Components**: 1 day
- **Console Commands**: 1 day  
- **Services Setup**: 4-6 hours
- **Testing & Deployment**: 1 day
- **Total**: 3-4 days

## Next Steps
1. **Approve Plan**: Review and approve this implementation plan
2. **Start Phase 1**: Create database table and triggers
3. **Parallel Development**: Work on Laravel components while testing database
4. **Incremental Testing**: Test each component before moving to next phase
5. **Production Deployment**: Deploy in stages with monitoring

---
**Created**: January 27, 2026  
**Purpose**: Complete sync system for backalerts table  
**Status**: Plan ready for implementation