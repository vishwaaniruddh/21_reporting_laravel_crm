# CRITICAL: CSV Downloads Blocking Portal Access

## URGENT ISSUE
**Status**: 🔴 ACTIVE - Users cannot login when large CSV downloads are in progress

## Problem Description
When users download large CSV files (1M+ records):
1. Download takes 5-10 minutes to complete
2. PHP worker is 100% busy during this time
3. **All other users are blocked** from accessing the portal
4. New users cannot login
5. Portal appears "down" for everyone else

## Current Situation
- Someone is downloading 400,000+ records right now
- This is blocking other users from logging in
- The issue will persist until the download completes

## Immediate Actions

### For Users (RIGHT NOW)
1. **STOP using "Download CSV" button on report pages**
2. **USE the Downloads page instead**: http://192.168.100.21:9000/reports/downloads
3. Downloads page uses batched downloads (doesn't block other users)

### For Administrators

#### 1. Monitor Active Downloads
```powershell
# Check who is downloading
Get-Content storage\logs\laravel.log -Tail 100 | Select-String "CSV export"

# Check current PHP processes
Get-Process php | Select-Object Id, CPU, WorkingSet, StartTime
```

#### 2. If Portal is Completely Blocked
```powershell
# Restart the portal service (will cancel active downloads)
Restart-Service AlertPortal

# Check service status
Get-Service AlertPortal
```

⚠️ **WARNING**: Restarting will cancel any active downloads!

#### 3. Temporary Limit (Emergency)
Add a record limit to prevent huge downloads:

Edit `app/Http/Controllers/AlertsReportController.php` line 655:
```php
// Add before: $limit = $validated['limit'] ?? 1000000;
if ($limit > 500000) {
    return response()->json([
        'success' => false,
        'error' => [
            'code' => 'LIMIT_EXCEEDED',
            'message' => 'Maximum 500,000 records per download. Use Downloads page for larger datasets.',
        ],
    ], 400);
}
```

## Root Causes

### 1. PHP Worker Exhaustion
- WAMP/Apache has limited PHP workers (typically 5-10)
- Each CSV download consumes 1 worker for 5-10 minutes
- 2-3 simultaneous downloads = portal blocked

### 2. Synchronous Processing
- CSV generation is synchronous (blocks the request)
- No background processing
- No queue system

### 3. No Rate Limiting
- Users can start multiple downloads
- No limit on concurrent downloads per user
- No limit on dataset size

## Solutions

### Short-term (Implement Today)

#### 1. Add Record Limit Warning ✅ DONE
- Show warning when dataset > 500K records
- Direct users to Downloads page
- Added to VM Alerts page

#### 2. Redirect Large Downloads
Force users to use Downloads page for large datasets:
- Detect if total records > 500K
- Show error message
- Provide link to Downloads page

#### 3. Add Download Queue Status
Show users how many downloads are in progress:
- "2 downloads in progress - your download may be slow"
- Discourage simultaneous downloads

### Medium-term (This Week)

#### 1. Implement Queue System
Use Laravel Queues for CSV generation:
- Downloads run in background
- Users get email when ready
- Portal stays responsive

#### 2. Increase PHP Workers
Configure Apache/WAMP for more workers:
- Current: ~5-10 workers
- Target: 20-30 workers
- Requires server resources

#### 3. Add Rate Limiting
Limit concurrent downloads:
- Max 1 download per user at a time
- Max 3 downloads system-wide
- Queue additional requests

### Long-term (Next Month)

#### 1. Pre-generate Reports
Generate CSV files overnight:
- Cron job generates daily reports
- Users download pre-generated files
- Instant downloads, no blocking

#### 2. Streaming Downloads
Implement true streaming:
- Send data as it's generated
- Reduce memory usage
- Better user experience

#### 3. Separate Download Server
Dedicated server for downloads:
- Doesn't affect main portal
- Can handle heavy loads
- Better scalability

## Monitoring

### Check Portal Health
```powershell
# Check if portal is responsive
Invoke-WebRequest -Uri "http://192.168.100.21:9000/api/health" -TimeoutSec 5

# Check active downloads
Get-Content storage\logs\laravel.log -Tail 50 | Select-String "CSV export progress"

# Check PHP memory usage
Get-Process php | Measure-Object WorkingSet -Sum | Select-Object @{N='TotalMB';E={[math]::Round($_.Sum/1MB,2)}}
```

### Alert Thresholds
- ⚠️ Warning: 2+ active downloads
- 🔴 Critical: 3+ active downloads or portal unresponsive

## User Communication

### Message to Send to Users
```
IMPORTANT: Portal Performance Issue

If you need to download large reports (500K+ records):
1. Use the Downloads page: http://192.168.100.21:9000/reports/downloads
2. Do NOT use "Download CSV" button on report pages
3. Downloads page uses batched downloads and won't block other users

Large downloads from report pages can make the portal unavailable for other users.

Thank you for your cooperation!
```

## Technical Details

### Why Downloads Page Works Better
1. **Batched Downloads**: Splits large datasets into 470K chunks
2. **Token-based**: No session locking
3. **Session Closed Early**: Doesn't block other requests
4. **Parallel Safe**: Multiple users can download simultaneously

### Why Report Page Downloads Block
1. **Single Request**: Entire dataset in one request
2. **Session Lock**: Blocks other requests from same user
3. **Worker Exhaustion**: Consumes PHP worker for entire duration
4. **No Chunking**: Loads all data into memory

## Status Tracking

| Date | Issue | Status | Action Taken |
|------|-------|--------|--------------|
| 2026-01-31 | Users blocked during CSV download | 🔴 Active | Added warnings, documented issue |

## Next Steps

1. ✅ Add warning to VM Alerts page (DONE)
2. ⏳ Add warning to All Alerts page
3. ⏳ Implement 500K record limit
4. ⏳ Add queue system for large downloads
5. ⏳ Increase PHP workers
6. ⏳ Communicate to users

## Date Identified
January 31, 2026

## Priority
🔴 **CRITICAL** - Affects all users, blocks portal access
