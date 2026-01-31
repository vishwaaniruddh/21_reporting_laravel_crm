# 🚀 QUICK START CARD

## Your Alert System is Running!

**Portal:** http://192.168.100.21:9000  
**Status:** All services operational ✅

---

## One-Command Status Check
```powershell
.\verify-services.ps1
```

---

## Essential Commands

### Check Services
```powershell
Get-Service | Where-Object {$_.Name -like "Alert*"}
```

### Restart Everything
```powershell
Restart-Service AlertPortal, AlertInitialSync, AlertUpdateSync
```

### Check Update Sync
```powershell
php check_update_sync_status.php
```

### View Logs
```powershell
Get-Content "storage\logs\portal-service.log" -Tail 20
Get-Content "storage\logs\initial-sync-service.log" -Tail 20
Get-Content "storage\logs\update-sync-service.log" -Tail 20
```

---

## Services

| Service | Purpose | Interval |
|---------|---------|----------|
| AlertPortal | Web interface | Always on |
| AlertInitialSync | New alerts | 20 minutes |
| AlertUpdateSync | Alert updates | 5 seconds |

---

## What's Automated

✅ Auto-start on boot  
✅ Auto-restart on failure  
✅ Runs without terminal  
✅ Survives system restarts  
✅ Log rotation  
✅ Firewall configured  

---

## Need Help?

Read: `FINAL_STATUS_REPORT.md`  
Or: `SETUP_COMPLETE.md`

---

**Everything is working. No action needed!**
