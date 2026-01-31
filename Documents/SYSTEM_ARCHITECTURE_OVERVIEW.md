# 🏗️ Alert System - Complete Architecture Overview

**Comprehensive System Documentation**  
**Last Updated:** January 9, 2026

---

## Table of Contents

1. [System Overview](#system-overview)
2. [Architecture Diagram](#architecture-diagram)
3. [Component Details](#component-details)
4. [Data Flow](#data-flow)
5. [Service Dependencies](#service-dependencies)
6. [Database Schema](#database-schema)
7. [Configuration](#configuration)
8. [Monitoring](#monitoring)

---

## System Overview

### Purpose
Alert management system that syncs alerts from MySQL to PostgreSQL with date-partitioned tables, providing a web interface for viewing and managing alerts.

### Technology Stack
- **Backend:** Laravel 12 (PHP 8.4.11)
- **Frontend:** React 19 + Vite 7
- **Databases:** MySQL 8 (source) + PostgreSQL (target)
- **Services:** Windows Services via NSSM
- **Styling:** Tailwind CSS 4

### Key Features
- Real-time alert synchronization
- Date-partitioned PostgreSQL tables
- Automatic partition management
- Update tracking and sync
- Web-based portal interface
- 24/7 operation via Windows Services

---

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                     USER BROWSER                             │
│              http://192.168.100.21:9000                      │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│                  FRONTEND LAYER                              │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  AlertViteDev Service (Port 5173)                    │   │
│  │  - Vite Dev Server                                   │   │
│  │  - React Components                                  │   │
│  │  - Hot Module Replacement                            │   │
│  │  - Asset Compilation                                 │   │
│  └──────────────────────────────────────────────────────┘   │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│                  BACKEND LAYER                               │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  AlertPortal Service (Port 9000)                     │   │
│  │  - Laravel Application                               │   │
│  │  - API Endpoints                                     │   │
│  │  - Business Logic                                    │   │
│  │  - Authentication                                    │   │
│  └──────────────────────────────────────────────────────┘   │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│                  SYNC LAYER                                  │
│  ┌────────────────────────────┬────────────────────────┐    │
│  │ AlertInitialSync Service   │ AlertUpdateSync Service│    │
│  │ (Every 20 minutes)         │ (Every 5 seconds)      │    │
│  │ - Sync new alerts          │ - Sync alert updates   │    │
│  │ - Batch processing         │ - Track changes        │    │
│  │ - Partition management     │ - UPSERT operations    │    │
│  └────────────────────────────┴────────────────────────┘    │
└────────────────────┬────────────────────┬───────────────────┘
                     │                    │
                     ▼                    ▼
┌──────────────────────────┐  ┌──────────────────────────┐
│   MySQL Database         │  │  PostgreSQL Database     │
│   (Source)               │  │  (Target)                │
│                          │  │                          │
│  - alerts table          │  │  - alerts_YYYY_MM_DD     │
│  - alert_pg_update_log   │  │  - partition_registry    │
│  - Other tables          │  │  - sync_metadata         │
└──────────────────────────┘  └──────────────────────────┘
```

---

## Component Details

### 1. AlertPortal Service

**Purpose:** Web server hosting the Laravel application

**Details:**
- **Service Name:** AlertPortal
- **Display Name:** Alert System Portal
- **Port:** 9000
- **Host:** 192.168.100.21
- **Command:** `php artisan serve --host=192.168.100.21 --port=9000`
- **Auto-Start:** Yes
- **Auto-Restart:** Yes (5-second delay)

**Responsibilities:**
- Serve web interface
- Handle HTTP requests
- Execute business logic
- Query PostgreSQL for alert data
- Provide API endpoints
- Manage user sessions

**Log Files:**
- `storage/logs/portal-service.log`
- `storage/logs/portal-service-error.log`
- `storage/logs/laravel.log`

**Health Check:**
```powershell
Test-NetConnection -ComputerName 192.168.100.21 -Port 9000
```

---

### 2. AlertViteDev Service

**Purpose:** Development server for frontend assets

**Details:**
- **Service Name:** AlertViteDev
- **Display Name:** Alert Vite Dev Server
- **Port:** 5173
- **Host:** 127.0.0.1
- **Command:** `npm run dev`
- **Auto-Start:** Yes
- **Auto-Restart:** Yes (5-second delay)

**Responsibilities:**
- Compile React components
- Process Tailwind CSS
- Serve JavaScript/CSS assets
- Hot Module Replacement (HMR)
- Asset optimization

**Log Files:**
- `storage/logs/vite-dev-service.log`
- `storage/logs/vite-dev-service-error.log`

**Health Check:**
```powershell
Test-NetConnection -ComputerName 127.0.0.1 -Port 5173
```

**Important:** Portal requires BOTH AlertPortal AND AlertViteDev to function properly!

---

### 3. AlertInitialSync Service

**Purpose:** Sync new alerts from MySQL to PostgreSQL

**Details:**
- **Service Name:** AlertInitialSync
- **Display Name:** Alert Initial Sync Worker
- **Interval:** Every 20 minutes (1200 seconds)
- **Command:** `php continuous-initial-sync.php`
- **Auto-Start:** Yes
- **Auto-Restart:** Yes (5-second delay)

**Responsibilities:**
- Read new alerts from MySQL `alerts` table
- Extract date from `receivedtime` column
- Determine target partition (e.g., `alerts_2026_01_09`)
- Create partition if doesn't exist
- Insert alerts into correct partition
- Update `partition_registry` record counts
- Track last synced ID in `sync_metadata`

**Process Flow:**
1. Check `sync_metadata` for last synced ID
2. Query MySQL for alerts with ID > last synced ID
3. Group alerts by date
4. For each date group:
   - Ensure partition exists
   - Batch insert alerts
   - Update partition registry
5. Update last synced ID
6. Sleep for 20 minutes
7. Repeat

**Log Files:**
- `storage/logs/initial-sync-service.log`
- `storage/logs/initial-sync-service-error.log`

**Health Check:**
```powershell
php artisan sync:partitioned --status
```

---

### 4. AlertUpdateSync Service

**Purpose:** Sync alert updates from MySQL to PostgreSQL

**Details:**
- **Service Name:** AlertUpdateSync
- **Display Name:** Alert Update Sync Worker
- **Interval:** Every 5 seconds
- **Batch Size:** 100 records
- **Command:** `php artisan sync:update-worker --poll-interval=5 --batch-size=100`
- **Auto-Start:** Yes
- **Auto-Restart:** Yes (5-second delay)

**Responsibilities:**
- Monitor `alert_pg_update_log` table for pending updates (status=1)
- Read updated alert from MySQL
- Extract date from `receivedtime`
- Determine target partition
- UPSERT alert to correct partition
- Update `alert_pg_update_log` status to completed (status=2)

**Process Flow:**
1. Query `alert_pg_update_log` for status=1 (pending)
2. Limit to batch size (100)
3. For each pending entry:
   - Read alert from MySQL by alert_id
   - Extract date from receivedtime
   - Determine partition table name
   - UPSERT to partition table
   - Update log entry to status=2
4. Sleep for 5 seconds
5. Repeat

**Log Files:**
- `storage/logs/update-sync-service.log`
- `storage/logs/update-sync-service-error.log`

**Health Check:**
```powershell
php check_update_sync_status.php
```

---

## Data Flow

### Initial Sync Flow

```
MySQL alerts table
    │
    ├─ Read new alerts (ID > last_synced_id)
    │
    ▼
AlertInitialSync Service
    │
    ├─ Extract receivedtime
    ├─ Determine partition (alerts_YYYY_MM_DD)
    ├─ Create partition if needed
    │
    ▼
PostgreSQL alerts_YYYY_MM_DD
    │
    ├─ Insert alerts
    ├─ Update partition_registry
    └─ Update sync_metadata
```

### Update Sync Flow

```
MySQL alerts table (UPDATE)
    │
    ├─ Trigger creates entry in alert_pg_update_log
    │   (alert_id, status=1)
    │
    ▼
AlertUpdateSync Service
    │
    ├─ Poll alert_pg_update_log for status=1
    ├─ Read alert from MySQL
    ├─ Extract receivedtime
    ├─ Determine partition
    │
    ▼
PostgreSQL alerts_YYYY_MM_DD
    │
    ├─ UPSERT alert (INSERT ON CONFLICT UPDATE)
    └─ Update alert_pg_update_log (status=2)
```

### Portal Data Flow

```
User Browser
    │
    ├─ HTTP Request → http://192.168.100.21:9000
    │
    ▼
AlertPortal Service (Laravel)
    │
    ├─ Route request
    ├─ Execute controller
    ├─ Query PostgreSQL
    │   └─ Use PartitionQueryRouter to query correct partitions
    │
    ▼
PostgreSQL alerts_YYYY_MM_DD
    │
    ├─ Return data
    │
    ▼
AlertPortal Service
    │
    ├─ Render view with React
    │
    ▼
AlertViteDev Service
    │
    ├─ Serve compiled React components
    ├─ Serve CSS assets
    │
    ▼
User Browser
    │
    └─ Display interface
```

---

## Service Dependencies

### Dependency Chain

```
AlertViteDev (Frontend Assets)
    ↓
AlertPortal (Backend)
    ↓
PostgreSQL Database
    ↑
AlertInitialSync (New Alerts)
    ↑
MySQL Database
    ↑
AlertUpdateSync (Alert Updates)
```

### Critical Dependencies

**AlertPortal depends on:**
- AlertViteDev (for frontend assets)
- PostgreSQL (for data)
- MySQL (for authentication, if used)

**AlertViteDev depends on:**
- Node.js/NPM
- Project dependencies (node_modules)

**AlertInitialSync depends on:**
- MySQL (source data)
- PostgreSQL (target data)
- PartitionManager service
- DateExtractor service

**AlertUpdateSync depends on:**
- MySQL (source data + update log)
- PostgreSQL (target data)
- AlertSyncService
- PartitionManager service

---

## Database Schema

### MySQL Tables

#### alerts
```sql
- id (PRIMARY KEY)
- receivedtime (DATETIME) -- Used for partition determination
- [other alert columns]
```

#### alert_pg_update_log
```sql
- id (PRIMARY KEY)
- alert_id (FOREIGN KEY → alerts.id)
- status (TINYINT) -- 1=pending, 2=completed, 3=failed
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)
```

### PostgreSQL Tables

#### alerts_YYYY_MM_DD (Partition Tables)
```sql
- id (PRIMARY KEY)
- receivedtime (TIMESTAMP)
- [other alert columns matching MySQL]
```

#### partition_registry
```sql
- id (PRIMARY KEY)
- partition_name (VARCHAR) -- e.g., 'alerts_2026_01_09'
- partition_date (DATE)
- record_count (INTEGER)
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)
```

#### sync_metadata
```sql
- id (PRIMARY KEY)
- key (VARCHAR) -- e.g., 'last_synced_id'
- value (TEXT)
- updated_at (TIMESTAMP)
```

### Partition Naming Convention

Format: `alerts_YYYY_MM_DD`

Examples:
- `alerts_2026_01_09` - January 9, 2026
- `alerts_2026_01_10` - January 10, 2026
- `alerts_2025_12_31` - December 31, 2025

---

## Configuration

### Environment Variables (.env)

```ini
# Application
APP_NAME="Alert System"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://192.168.100.21:9000

# MySQL (Source)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=esurv
DB_USERNAME=root
DB_PASSWORD=

# PostgreSQL (Target)
PGSQL_HOST=127.0.0.1
PGSQL_PORT=5432
PGSQL_DATABASE=esurv_pg
PGSQL_USERNAME=postgres
PGSQL_PASSWORD=root
```

### Vite Configuration (vite.config.js)

```javascript
server: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
    hmr: {
        host: '127.0.0.1',
    },
}
```

### Service Configuration

All services configured via NSSM with:
- Auto-start on boot
- Auto-restart on failure (5-second delay)
- Log rotation (10MB per file)
- Working directory: Project root

---

## Monitoring

### Service Status

```powershell
# Quick check
.\verify-services.ps1

# Detailed check
Get-Service | Where-Object {$_.Name -like "Alert*"} | Format-List *
```

### Sync Monitoring

```powershell
# Initial sync status
php artisan sync:partitioned --status

# Update sync status
php check_update_sync_status.php

# Partition count
php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo 'Partitions: ' . DB::connection('pgsql')->table('partition_registry')->count() . PHP_EOL;"
```

### Log Monitoring

```powershell
# View all service logs
Get-ChildItem storage\logs\*-service.log | ForEach-Object { 
    Write-Host "`n=== $($_.Name) ===" -ForegroundColor Cyan
    Get-Content $_.FullName -Tail 5 
}

# Monitor live
Get-Content "storage\logs\portal-service.log" -Tail 20 -Wait
```

### Performance Metrics

```powershell
# Check sync performance
Get-Content "storage\logs\initial-sync-service.log" | Select-String "Average Records/Second"

# Check update sync throughput
Get-Content "storage\logs\update-sync-service.log" | Select-String "processed in"
```

---

## System Requirements

### Software Requirements
- Windows Server 2016+ or Windows 10+
- PHP 8.4.11
- Node.js 18+ with NPM
- MySQL 8.0+
- PostgreSQL 12+
- NSSM (Non-Sucking Service Manager)

### Hardware Requirements
- CPU: 2+ cores recommended
- RAM: 4GB minimum, 8GB recommended
- Disk: 50GB+ for database growth
- Network: 100Mbps+ for sync operations

### Port Requirements
- 9000: AlertPortal (web interface)
- 5173: AlertViteDev (frontend assets)
- 3306: MySQL
- 5432: PostgreSQL

---

## Backup & Recovery

### Database Backup

```powershell
# MySQL backup
mysqldump -u root esurv > backup_mysql_$(Get-Date -Format 'yyyyMMdd').sql

# PostgreSQL backup
pg_dump -U postgres esurv_pg > backup_pgsql_$(Get-Date -Format 'yyyyMMdd').sql
```

### Service Configuration Backup

```powershell
# Export service configurations
nssm dump AlertPortal > backup_AlertPortal.txt
nssm dump AlertViteDev > backup_AlertViteDev.txt
nssm dump AlertInitialSync > backup_AlertInitialSync.txt
nssm dump AlertUpdateSync > backup_AlertUpdateSync.txt
```

### Recovery

```powershell
# Restore MySQL
mysql -u root esurv < backup_mysql_20260109.sql

# Restore PostgreSQL
psql -U postgres esurv_pg < backup_pgsql_20260109.sql

# Recreate services
.\quick-setup.ps1
```

---

## Security Considerations

### Network Security
- Portal accessible only on local network (192.168.100.21)
- Vite dev server bound to localhost (127.0.0.1)
- Firewall rule for port 9000

### Database Security
- MySQL: Root access (should be restricted in production)
- PostgreSQL: Password-protected
- No external database access

### Application Security
- Laravel security features enabled
- CSRF protection
- Session management
- Input validation

---

## Performance Optimization

### Sync Performance
- Batch size: 100 records per update sync cycle
- Initial sync: Continuous mode for faster processing
- Partition-based queries for efficient data access

### Database Performance
- Indexed columns: id, receivedtime
- Partitioned tables for faster queries
- Connection pooling

### Frontend Performance
- Vite HMR for fast development
- Asset optimization
- Lazy loading components

---

## Troubleshooting Resources

### Quick Reference
- `RESTART_QUICK_REFERENCE.md` - Quick fix commands
- `TROUBLESHOOTING_RESTART_GUIDE.md` - Detailed troubleshooting

### Verification Tools
- `verify-services.ps1` - Service status check
- `check_update_sync_status.php` - Sync status check

### Log Analysis
- Service logs in `storage/logs/`
- Laravel logs in `storage/logs/laravel.log`

---

## Future Enhancements

### Potential Improvements
1. Production build mode (replace Vite dev with compiled assets)
2. Database connection pooling optimization
3. Monitoring dashboard
4. Automated backup system
5. Performance metrics collection
6. Alert notifications for sync failures
7. Web-based service management interface

---

**Document Version:** 1.0  
**Last Updated:** January 9, 2026  
**Maintained By:** System Administrator
