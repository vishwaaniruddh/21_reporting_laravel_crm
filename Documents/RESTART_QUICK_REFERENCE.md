# 🚀 Quick Restart Reference Card

**Keep this handy for quick fixes!**

---

## 🔴 Emergency: Everything Down

```powershell
Restart-Service AlertPortal, AlertViteDev, AlertInitialSync, AlertUpdateSync
Start-Sleep -Seconds 10
.\verify-services.ps1
```

---

## 🌐 Portal Issues

### Portal Not Loading (Connection Refused)
```powershell
Restart-Service AlertPortal
```

### Portal Shows Blank Screen
```powershell
Restart-Service AlertViteDev
```

### Portal Completely Broken
```powershell
Restart-Service AlertPortal, AlertViteDev
```

---

## 🔄 Sync Issues

### New Alerts Not Syncing
```powershell
Restart-Service AlertInitialSync
```

### Updates Not Syncing
```powershell
Restart-Service AlertUpdateSync
php check_update_sync_status.php
```

### All Sync Broken
```powershell
Restart-Service AlertInitialSync, AlertUpdateSync
```

---

## 📊 Quick Status Checks

### Check Everything
```powershell
.\verify-services.ps1
```

### Check Services Only
```powershell
Get-Service | Where-Object {$_.Name -like "Alert*"}
```

### Check Sync Status
```powershell
php check_update_sync_status.php
```

### Check Portal Connectivity
```powershell
Test-NetConnection -ComputerName 192.168.100.21 -Port 9000
```

---

## 📝 View Logs

### Portal Logs
```powershell
Get-Content "storage\logs\portal-service.log" -Tail 20
```

### Vite Logs
```powershell
Get-Content "storage\logs\vite-dev-service.log" -Tail 20
```

### Initial Sync Logs
```powershell
Get-Content "storage\logs\initial-sync-service.log" -Tail 20
```

### Update Sync Logs
```powershell
Get-Content "storage\logs\update-sync-service.log" -Tail 20
```

### All Error Logs
```powershell
Get-Content "storage\logs\*-error.log" -Tail 10
```

---

## 🛠️ Service Control

### Start All
```powershell
Start-Service AlertPortal, AlertViteDev, AlertInitialSync, AlertUpdateSync
```

### Stop All
```powershell
Stop-Service AlertPortal, AlertViteDev, AlertInitialSync, AlertUpdateSync
```

### Restart All
```powershell
Restart-Service AlertPortal, AlertViteDev, AlertInitialSync, AlertUpdateSync
```

---

## 🔍 Troubleshooting Decision Tree

```
Is portal accessible?
├─ NO → Restart AlertPortal
└─ YES
    ├─ Is screen blank?
    │   └─ YES → Restart AlertViteDev
    └─ NO
        ├─ Are new alerts syncing?
        │   └─ NO → Restart AlertInitialSync
        └─ Are updates syncing?
            └─ NO → Restart AlertUpdateSync
```

---

## 📞 Portal URL

**Main Portal:** http://192.168.100.21:9000

---

## ⚡ Most Common Fixes

1. **Portal blank screen** → `Restart-Service AlertViteDev`
2. **Portal not loading** → `Restart-Service AlertPortal`
3. **Sync stopped** → `Restart-Service AlertInitialSync, AlertUpdateSync`
4. **Everything broken** → `Restart-Service AlertPortal, AlertViteDev, AlertInitialSync, AlertUpdateSync`

---

## 📚 Full Documentation

For detailed troubleshooting, see: `TROUBLESHOOTING_RESTART_GUIDE.md`

---

**Keep this card accessible for quick reference!**
