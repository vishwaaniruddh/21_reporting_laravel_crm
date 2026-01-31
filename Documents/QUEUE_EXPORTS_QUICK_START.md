# Queue-Based Exports - Quick Start Guide

## Problem
CSV downloads block the entire portal for 5-10 minutes, preventing all users from accessing the system.

## Solution
Laravel Queue system - CSV files are generated in the background, portal never blocks.

## What's Done

✅ Database table created (`export_jobs`)
✅ Job class created (`GenerateCsvExportJob`)
✅ API endpoints added to `DownloadsController`
✅ Routes configured
✅ Queue worker batch file created

## What's Needed

⚠️ **Queue Worker Service** - Must be created manually
⚠️ **Frontend** - React components not yet implemented

## Quick Setup (5 Minutes)

### Step 1: Create Queue Worker Service

Run these commands in PowerShell (as Administrator):

```powershell
# Install service
nssm install AlertPortalQueueWorker "C:\wamp64\www\comfort_reporting_crm\dual-database-app\codes\queue-worker.bat"

# Configure
nssm set AlertPortalQueueWorker AppDirectory "C:\wamp64\www\comfort_reporting_crm\dual-database-app"
nssm set AlertPortalQueueWorker DisplayName "Alert Portal Queue Worker"
nssm set AlertPortalQueueWorker Start SERVICE_AUTO_START
nssm set AlertPortalQueueWorker AppStdout "C:\wamp64\www\comfort_reporting_crm\dual-database-app\storage\logs\queue-worker-service.log"
nssm set AlertPortalQueueWorker AppStderr "C:\wamp64\www\comfort_reporting_crm\dual-database-app\storage\logs\queue-worker-service-error.log"

# Start
nssm start AlertPortalQueueWorker

# Verify
Get-Service AlertPortalQueueWorker
```

### Step 2: Test It

```powershell
# Test manually first
php artisan queue:work --once

# If that works, the service should work too
```

### Step 3: Monitor

```powershell
# Check service
Get-Service AlertPortalQueueWorker

# View logs
Get-Content storage\logs\queue-worker-service.log -Tail 50 -Wait
```

## API Endpoints (Ready to Use)

```
POST   /api/downloads/request          - Queue a new export
GET    /api/downloads/status/{jobId}   - Check export status
GET    /api/downloads/file/{jobId}     - Download completed file
GET    /api/downloads/my-exports       - Get user's export history
DELETE /api/downloads/delete/{jobId}   - Delete an export
```

## Frontend TODO

The Downloads page needs to be updated to:
1. Show "Request Export" button instead of direct download
2. Display list of user's exports with status
3. Auto-refresh to poll job status
4. Show download button when export is ready

See `Documents/QUEUE_BASED_EXPORTS_IMPLEMENTATION.md` for detailed frontend implementation guide.

## Benefits

- ✅ Portal never blocks
- ✅ Users can continue working while exports generate
- ✅ Multiple users can request exports simultaneously
- ✅ Failed exports can be retried
- ✅ Export history tracking

## Troubleshooting

**Service won't start?**
```powershell
# Check error log
Get-Content storage\logs\queue-worker-service-error.log

# Try running manually
php artisan queue:work --once
```

**Jobs not processing?**
```powershell
# Restart service
Restart-Service AlertPortalQueueWorker

# Check if jobs are in queue
php artisan queue:failed
```

## Full Documentation

- Complete solution: `Documents/CSV_BLOCKING_SOLUTION.md`
- Implementation details: `Documents/QUEUE_BASED_EXPORTS_IMPLEMENTATION.md`
