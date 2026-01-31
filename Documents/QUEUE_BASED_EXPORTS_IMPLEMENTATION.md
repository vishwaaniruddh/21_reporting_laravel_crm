# Queue-Based CSV Exports Implementation

## Problem Solved

**Critical Issue**: CSV downloads were blocking the entire portal for 5-10 minutes, preventing all users from accessing the system (including login).

**Root Cause**: PHP worker exhaustion - WAMP/Apache has only 5-10 workers, and each CSV download consumed one worker for the entire generation time.

**Solution**: Laravel Queue system for background CSV generation - requests return immediately, files are generated in the background.

## Implementation Status

✅ **Backend Complete**
- Job class created: `GenerateCsvExportJob`
- Database table created: `export_jobs`
- Controller methods added to `DownloadsController`
- API routes configured
- Queue worker batch file created

⚠️ **Queue Worker Service** - Needs manual setup (see below)
⚠️ **Frontend** - Not yet implemented (needs React components)

## What Was Implemented

### 1. Database Migration
**File**: `database/migrations/2026_01_31_121638_create_export_jobs_table.php`

Creates `export_jobs` table to track background export jobs:
- `job_id` - Unique UUID for each export
- `user_id` - Who requested the export
- `type` - 'all-alerts' or 'vm-alerts'
- `date` - Date to export
- `status` - 'pending', 'processing', 'completed', 'failed'
- `filepath` - Path to generated file
- `total_records` - Number of records exported
- `error_message` - Error details if failed

**Status**: ✅ Migrated successfully

### 2. Job Class
**File**: `app/Jobs/GenerateCsvExportJob.php`

Background job that generates CSV files:
- Processes exports in chunks of 5,000 records
- Stores files in `storage/app/public/exports/`
- Updates job status in database
- Logs progress every 25,000 records
- Timeout: 30 minutes
- Retries: 3 attempts

### 3. Controller Methods
**File**: `app/Http/Controllers/DownloadsController.php`

New API endpoints:
- `POST /api/downloads/request` - Queue a new export
- `GET /api/downloads/status/{jobId}` - Check export status
- `GET /api/downloads/file/{jobId}` - Download completed file
- `GET /api/downloads/my-exports` - Get user's export history
- `DELETE /api/downloads/delete/{jobId}` - Delete an export

### 4. API Routes
**File**: `routes/api.php`

All routes require authentication and `reports.view` permission.

### 5. Queue Worker Batch File
**File**: `codes/queue-worker.bat`

Simple batch file to run the queue worker:
```batch
@echo off
cd /d C:\wamp64\www\comfort_reporting_crm\dual-database-app
C:\wamp64\bin\php\php8.2.13\php.exe artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

## Setup Instructions

### Step 1: Verify Queue Configuration

The queue is already configured to use database. Verify in `.env`:
```env
QUEUE_CONNECTION=database
```

### Step 2: Create Queue Worker Windows Service

**Option A: Manual NSSM Setup** (Recommended)

```powershell
# Install service
nssm install AlertPortalQueueWorker "C:\wamp64\www\comfort_reporting_crm\dual-database-app\codes\queue-worker.bat"

# Configure service
nssm set AlertPortalQueueWorker AppDirectory "C:\wamp64\www\comfort_reporting_crm\dual-database-app"
nssm set AlertPortalQueueWorker DisplayName "Alert Portal Queue Worker"
nssm set AlertPortalQueueWorker Description "Processes background jobs for CSV exports. Prevents portal blocking."
nssm set AlertPortalQueueWorker Start SERVICE_AUTO_START

# Configure logging
nssm set AlertPortalQueueWorker AppStdout "C:\wamp64\www\comfort_reporting_crm\dual-database-app\storage\logs\queue-worker-service.log"
nssm set AlertPortalQueueWorker AppStderr "C:\wamp64\www\comfort_reporting_crm\dual-database-app\storage\logs\queue-worker-service-error.log"

# Start service
nssm start AlertPortalQueueWorker

# Check status
Get-Service AlertPortalQueueWorker
```

**Option B: Test Manually First**

Before creating the service, test the queue worker manually:
```powershell
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

Leave this running in a terminal. If it works, proceed with creating the Windows service.

### Step 3: Test the Queue System

**Test with a small export:**

```powershell
# Using curl or Postman
curl -X POST http://192.168.100.21:9000/api/downloads/request `
  -H "Authorization: Bearer YOUR_TOKEN" `
  -H "Content-Type: application/json" `
  -d '{"type":"all-alerts","date":"2026-01-30"}'
```

**Check job status:**
```powershell
# Monitor queue worker log
Get-Content storage\logs\queue-worker-service.log -Tail 50 -Wait

# Check export_jobs table
php artisan tinker
>>> DB::table('export_jobs')->latest()->first();
```

### Step 4: Monitor Queue Worker

**Check service status:**
```powershell
Get-Service AlertPortalQueueWorker
nssm status AlertPortalQueueWorker
```

**View logs:**
```powershell
# Worker output
Get-Content storage\logs\queue-worker-service.log -Tail 50

# Worker errors
Get-Content storage\logs\queue-worker-service-error.log -Tail 50

# Laravel log (job execution)
Get-Content storage\logs\laravel.log -Tail 50 | Select-String "CSV export"
```

**Restart service:**
```powershell
Restart-Service AlertPortalQueueWorker
# or
nssm restart AlertPortalQueueWorker
```

## Frontend Implementation (TODO)

The frontend needs to be updated to use the new queue-based system. Here's what needs to be done:

### 1. Update Downloads Page

**File**: `resources/js/pages/Downloads.jsx`

Add these features:
- Button to request export (calls `/api/downloads/request`)
- List of user's exports with status (calls `/api/downloads/my-exports`)
- Auto-refresh to poll job status every 5 seconds
- Download button for completed exports
- Delete button for old exports
- Status indicators: Pending (⏳), Processing (🔄), Completed (✅), Failed (❌)

### 2. Create Export Service

**File**: `resources/js/services/exportService.js`

```javascript
import axios from 'axios';

export const requestExport = async (type, date) => {
    const response = await axios.post('/api/downloads/request', { type, date });
    return response.data;
};

export const checkStatus = async (jobId) => {
    const response = await axios.get(`/api/downloads/status/${jobId}`);
    return response.data;
};

export const downloadFile = (jobId) => {
    window.location.href = `/api/downloads/file/${jobId}`;
};

export const getMyExports = async () => {
    const response = await axios.get('/api/downloads/my-exports');
    return response.data;
};

export const deleteExport = async (jobId) => {
    const response = await axios.delete(`/api/downloads/delete/${jobId}`);
    return response.data;
};
```

### 3. Add Toast Notifications

Use react-toastify or similar to show:
- "Export queued successfully"
- "Export completed - ready to download"
- "Export failed - please try again"

## Benefits

✅ **Portal Never Blocks** - Requests return immediately (< 1 second)
✅ **Unlimited Concurrent Requests** - Queue handles them sequentially
✅ **Better User Experience** - Progress tracking, notifications
✅ **Scalable** - Add more queue workers as needed
✅ **Fault Tolerant** - Failed jobs can be retried
✅ **Resource Efficient** - Dedicated workers for background tasks
✅ **Production Ready** - Industry standard solution

## Monitoring

### Check Queue Status
```bash
# See pending jobs
php artisan queue:work --once

# List failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Clear failed jobs
php artisan queue:flush
```

### Check Export Jobs
```sql
-- Recent exports
SELECT * FROM export_jobs ORDER BY created_at DESC LIMIT 10;

-- Failed exports
SELECT * FROM export_jobs WHERE status = 'failed' ORDER BY created_at DESC;

-- Pending exports
SELECT * FROM export_jobs WHERE status = 'pending' ORDER BY created_at ASC;

-- Export statistics
SELECT 
    status,
    COUNT(*) as count,
    AVG(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as avg_duration_seconds
FROM export_jobs 
WHERE completed_at IS NOT NULL
GROUP BY status;
```

## Troubleshooting

### Queue Worker Not Processing Jobs

**Check if service is running:**
```powershell
Get-Service AlertPortalQueueWorker
```

**Check logs for errors:**
```powershell
Get-Content storage\logs\queue-worker-service-error.log -Tail 50
```

**Restart service:**
```powershell
Restart-Service AlertPortalQueueWorker
```

### Jobs Failing

**Check Laravel log:**
```powershell
Get-Content storage\logs\laravel.log -Tail 100 | Select-String "CSV export job failed"
```

**Check export_jobs table:**
```sql
SELECT * FROM export_jobs WHERE status = 'failed' ORDER BY created_at DESC LIMIT 5;
```

**Retry failed jobs:**
```bash
php artisan queue:retry all
```

### Files Not Downloading

**Check if file exists:**
```powershell
Get-ChildItem storage\app\public\exports\
```

**Check file permissions:**
```powershell
icacls storage\app\public\exports\
```

**Create symbolic link if needed:**
```bash
php artisan storage:link
```

## Next Steps

1. ✅ Backend implementation complete
2. ⚠️ **Create queue worker Windows service** (manual setup required)
3. ⚠️ **Test queue system** with small export
4. ⚠️ **Implement frontend** (React components)
5. ⚠️ **Add toast notifications** for user feedback
6. ⚠️ **Update Downloads page** to use new API
7. ⚠️ **Test with production data**
8. ⚠️ **Monitor performance** and adjust as needed

## Alternative: Quick Fix (Not Recommended)

If you need a quick fix before implementing queues, you can increase PHP workers in Apache:

**File**: `C:\wamp64\bin\apache\apache2.4.XX\conf\extra\httpd-mpm.conf`
```apache
<IfModule mpm_winnt_module>
    ThreadsPerChild      250  # Increase from 150
    MaxRequestsPerChild  0
</IfModule>
```

Then restart Apache. This gives you more workers but doesn't solve the fundamental problem.

## Documentation

- Complete solution: `Documents/CSV_BLOCKING_SOLUTION.md`
- This implementation summary: `Documents/QUEUE_BASED_EXPORTS_IMPLEMENTATION.md`
