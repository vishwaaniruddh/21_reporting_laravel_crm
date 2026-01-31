# Run This Now - Setup Windows Services

## ✅ NSSM is already installed

Great! You're ready to set up the services.

## 🚀 Quick Setup (2 Steps)

### Step 1: Find Your Paths

```powershell
# Find PHP path
where.exe php

# Example output: C:\php\php.exe
# Copy this path!
```

```powershell
# Find NSSM path
where.exe nssm

# Example output: C:\nssm\nssm-2.24\win64\nssm.exe
# Copy this path!
```

```powershell
# Get current project path
cd C:\path\to\your\project
pwd

# Example output: C:\xampp\htdocs\alert-system
# This is your project path!
```

### Step 2: Edit and Run Setup Script

Open `setup-services.ps1` in a text editor and update these 3 lines at the top:

```powershell
# Line 7-9: Update these paths
$PHP_PATH = "C:\php\php.exe"  # ← Your PHP path from Step 1
$PROJECT_PATH = (Get-Location).Path  # ← Auto-detected (current directory)
$NSSM_PATH = "C:\nssm\nssm-2.24\win64\nssm.exe"  # ← Your NSSM path from Step 1
```

Then run:

```powershell
# Open PowerShell as Administrator
# Right-click PowerShell → Run as Administrator

# Navigate to project
cd C:\path\to\your\project

# Run setup
.\setup-services.ps1
```

## ✅ Expected Output

```
=== Alert System Services Setup ===

✓ PHP found: C:\php\php.exe
✓ Project found: C:\path\to\your\project
✓ NSSM found: C:\nssm\nssm-2.24\win64\nssm.exe

Creating service: Alert System Portal...
✓ Service created and started: Alert System Portal

Creating service: Alert Initial Sync Worker...
✓ Service created and started: Alert Initial Sync Worker

Creating service: Alert Update Sync Worker...
✓ Service created and started: Alert Update Sync Worker

✓ Firewall rule created for port 9000

=== Setup Complete ===

Services created and started:
  1. AlertPortal - http://192.168.100.21:9000
  2. AlertInitialSync - Syncing new alerts every 20 minutes
  3. AlertUpdateSync - Syncing alert updates every 5 seconds
```

## 🔍 Verify Services

```powershell
# Check services are running
Get-Service | Where-Object {$_.Name -like "Alert*"}
```

**Expected:**
```
Status   Name               DisplayName
------   ----               -----------
Running  AlertPortal        Alert System Portal
Running  AlertInitialSync   Alert Initial Sync Worker
Running  AlertUpdateSync    Alert Update Sync Worker
```

## 🌐 Test Portal

Open browser: http://192.168.100.21:9000

**Should see:** Your Alert System portal

## 📊 Check Logs

```powershell
# Portal logs
Get-Content "storage\logs\portal-service.log" -Tail 20

# Initial sync logs
Get-Content "storage\logs\initial-sync-service.log" -Tail 20

# Update sync logs
Get-Content "storage\logs\update-sync-service.log" -Tail 20
```

## ✨ Done!

Your services are now running 24/7!

- ✅ Portal accessible at http://192.168.100.21:9000
- ✅ Initial sync running every 20 minutes
- ✅ Update sync running every 5 seconds
- ✅ All services auto-start on boot
- ✅ All services auto-restart on crash

## 🎯 What's Next?

**Nothing!** Your system is production-ready.

Services will:
- Start automatically when Windows boots
- Run in background (no terminal needed)
- Restart automatically if they crash
- Keep running even if you log out
- Survive system restarts

**Your Alert System is now running 24/7!** 🎉
