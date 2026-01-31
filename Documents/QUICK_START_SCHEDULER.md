# Quick Start: Background Scheduler

## TL;DR - Just Run This

```powershell
.\codes\start-scheduler-service.ps1
```

Then **close the PowerShell window**. The scheduler keeps running in the background!

---

## What This Does

✅ Starts the sync scheduler as a background service  
✅ Syncs records every minute automatically  
✅ Processes ~100,000 records per minute  
✅ Continues running even after you close PowerShell  
✅ No need to keep any windows open  

---

## Check If It's Running

```powershell
.\codes\check-scheduler-status.ps1
```

---

## Stop It (When Needed)

```powershell
.\codes\stop-scheduler.ps1
```

---

## That's It!

The scheduler now runs automatically in the background. You don't need to do anything else. It will sync all records continuously until complete.

**See `SCHEDULER_GUIDE.md` for detailed documentation.**

