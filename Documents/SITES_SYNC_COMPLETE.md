# ✅ Sites Sync System - Complete

## What Was Built

A complete trigger-based synchronization system for `sites`, `dvrsite`, and `dvronline` tables from MySQL to PostgreSQL.

### Architecture
- **MySQL Triggers** → Automatically log changes to `sites_pg_update_log`
- **Update Sync Worker** → Continuously processes log entries and syncs to PostgreSQL
- **NSSM Service** → Runs worker as Windows service (auto-start on boot)
- **Monitoring Tools** → Scripts to check status and logs

### Key Features
✅ **Automatic Sync** - Any INSERT/UPDATE in MySQL automatically syncs to PostgreSQL
✅ **All Columns** - Every column is synced (except `synced_at` to prevent loops)
✅ **Read-Only MySQL** - Never modifies MySQL source tables
✅ **Retry Logic** - Failed entries retry up to 3 times
✅ **Error Tracking** - Failed entries logged with error messages
✅ **Service Management** - Runs as Windows service with auto-restart

---

## 🚀 To Set Up NSSM Service

### Run This One Command:
```powershell
.\codes\create-sites-update-service.ps1
```

This will:
1. Create `SitesUpdateSync` Windows service
2. Configure it to start automatically on boot
3. Set up log rotation
4. Start the service immediately

---

## 📊 To Monitor Everything

### Check All Services (Alerts + Sites):
```powershell
.\codes\check-all-nssm-services.ps1
```

Shows:
- All Alert* and Sites* services status
- Alert update log counts
- Sites update log counts
- Sync status

### Check Sites Sync Details:
```bash
php codes/check-sites-sync-status.php
```

Shows:
- Pending/Completed/Failed breakdown
- Recent log entries
- Failed entries with errors
- MySQL vs PostgreSQL record counts

---

## 🧪 To Test

### 1. Update MySQL Manually:
```sql
UPDATE sites SET City = 'Test City' WHERE SN = 17;
```

### 2. Check Log Entry Created:
```sql
SELECT * FROM sites_pg_update_log ORDER BY id DESC LIMIT 5;
```

You should see an entry with `status=1` (pending).

### 3. Wait 5 Seconds

The worker polls every 5 seconds.

### 4. Check Entry Completed:
```sql
SELECT * FROM sites_pg_update_log ORDER BY id DESC LIMIT 5;
```

Status should change to `2` (completed).

### 5. Verify PostgreSQL Updated:
```sql
-- In PostgreSQL
SELECT City FROM sites WHERE "SN" = 17;
```

Should show 'Test City'.

---

## 📁 All Files Created

### SQL Scripts
- `codes/create-sites-update-log-table.sql` - Creates log table
- `codes/create-sites-triggers.sql` - Creates 6 triggers

### Services
- `app/Services/SitesUpdateSyncService.php` - Update sync logic
- `app/Services/SitesSyncService.php` - Full sync logic
- `app/Services/SitesSyncResult.php` - Result object

### Commands
- `app/Console/Commands/SitesUpdateSyncWorker.php` - Worker command
- `app/Console/Commands/SitesSyncCommand.php` - Manual sync command

### PowerShell Scripts
- `codes/create-sites-update-service.ps1` - Create NSSM service
- `codes/start-sites-update-worker.ps1` - Start worker manually
- `codes/setup-sites-update-sync.ps1` - Complete setup script
- `codes/check-all-nssm-services.ps1` - Check all services (updated)

### Monitoring Scripts
- `codes/check-sites-sync-status.php` - Detailed status
- `codes/test-sites-trigger.php` - Test triggers
- `codes/check-sites-tables.php` - Check table structures
- `codes/check-postgres-sites.php` - Check PostgreSQL tables
- `codes/check-update-columns.php` - Check update columns

### Configuration
- `config/sites-sync.php` - Configuration file

### Documentation
- `SITES_SYNC_GUIDE.md` - Complete guide
- `SITES_SYNC_SETUP_STEPS.md` - Setup steps
- `SITES_SYNC_QUICK_REFERENCE.md` - Quick reference
- `SITES_SYNC_COMPLETE.md` - This file

---

## 🎯 What Happens Now

1. **You update MySQL** (sites, dvrsite, or dvronline)
2. **Trigger fires** → Creates entry in `sites_pg_update_log` (status=1)
3. **Worker polls** → Finds pending entry
4. **Worker reads** → Gets record from MySQL (READ-ONLY)
5. **Worker syncs** → UPSERTs to PostgreSQL
6. **Worker marks** → Updates log entry (status=2)

**All automatic. All columns. No MySQL modifications.**

---

## 🔧 Service Management

```powershell
# Check status
nssm status SitesUpdateSync

# Start
nssm start SitesUpdateSync

# Stop
nssm stop SitesUpdateSync

# Restart
nssm restart SitesUpdateSync

# View logs
Get-Content storage\logs\sites-update-worker-stdout.log -Tail 50 -Wait
```

---

## ✨ You're All Set!

The system is ready. Just run:

```powershell
.\codes\create-sites-update-service.ps1
```

Then update any MySQL field and watch it automatically sync to PostgreSQL!

Check status anytime with:
```powershell
.\codes\check-all-nssm-services.ps1
```

---

## 📞 Need Help?

- **Service not starting?** Check `storage/logs/sites-update-worker-stderr.log`
- **Entries stuck?** Run `php codes/check-sites-sync-status.php`
- **Want to test?** Run `php codes/test-sites-trigger.php`
- **Manual sync?** Run `php artisan sites:sync`

**Everything is READ-ONLY for MySQL source tables!**
