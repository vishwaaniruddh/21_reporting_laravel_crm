# Quick Reference - Automatic Sync System

## 🚀 System Status: RUNNING

### Active Services
- ✅ Laravel Backend: http://localhost:8000
- ✅ Vite Frontend: http://localhost:5173
- ✅ Scheduler: Running (ProcessId: 5)

### Sync Status
- **Progress:** 8.26% (43,600 / 527,832 records)
- **Next Sync:** Every 20 minutes (at :00, :20, :40)
- **Completion:** ~100 minutes (~09:35)

## 📊 Quick Commands

### Check Progress
```bash
php check_sync.php
```

### Check Schedule
```bash
php sync_schedule.php
```

### Manual Sync (Optional)
```bash
php artisan sync:partitioned --max-batches=10
```

## 🌐 Access Points

### Alerts Reports
- **URL:** http://localhost:8000/alerts-reports
- **Login:** admin@example.com / password
- **Default Date:** Current date (2026-01-09)

### Test Queries
- **2026-01-08:** 23,600 records (currently)
- **2026-01-09:** 20,000 records (currently)

## 📈 Expected Final State

After sync completes (~09:35):
- **2026-01-08:** 359,646 records
- **2026-01-09:** 168,186 records
- **Total:** 527,832 records

## 🔧 Troubleshooting

### Restart Scheduler
```bash
powershell -ExecutionPolicy Bypass -File start-scheduler.ps1
```

### Check Logs
```bash
type storage\logs\laravel.log | Select-String "partitioned"
```

### Stop All Services
```bash
# Stop scheduler (Ctrl+C in PowerShell window)
# Or use Kiro's controlPwshProcess tool with action="stop" and processId=5
```

## 📚 Documentation

- `AUTOMATIC_SYNC_STATUS.md` - Detailed status report
- `AUTOMATIC_SYNC_SETUP.md` - Complete setup guide
- `SYNC_COMPLETION_GUIDE.md` - Sync completion guide
- `TESTING_GUIDE.md` - Frontend/backend testing guide

## ⚡ Key Points

1. **Automatic:** Sync runs every 20 minutes
2. **No Action Needed:** Just let it run
3. **Monitor:** Use `php check_sync.php`
4. **Completion:** ~100 minutes from now
5. **Continuous:** Will keep syncing new records

---

**Last Updated:** 2026-01-09 07:55:00
