# CSV Download Blocking - Complete Solution

## Problem Analysis

### Root Cause
The portal becomes completely blocked during CSV downloads because:

1. **PHP Worker Exhaustion** - WAMP/Apache has only 5-10 PHP workers
2. **Long-Running Processes** - Each CSV download takes 5-10 minutes
3. **Worker Consumption** - Each download consumes 1 worker for the entire duration
4. **No Workers Left** - When all workers are busy, no requests can be processed (including login)

### Why Session Closing Doesn't Work
- `session_write_close()` only releases the session lock
- The PHP worker is still busy generating the CSV file
- The worker cannot handle other requests until CSV generation completes
- Session closing ≠ Worker release

## Solution: Laravel Queue System

### Overview
Move CSV generation to background queue workers:
1. User clicks download → Job queued immediately
2. Background worker processes the job
3. User gets notification when file is ready
4. User downloads pre-generated file

### Benefits
- ✅ Portal never blocks - request returns immediately
- ✅ Unlimited concurrent downloads - queue handles them sequentially
- ✅ Better user experience - progress tracking, notifications
- ✅ Scalable - add more queue workers as needed
- ✅ Fault tolerant - failed jobs can be retried

## Implementation Steps

### Step 1: Configure Queue Driver (Database)

**File: `.env`**
```env
QUEUE_CONNECTION=database
```

### Step 2: Create Queue Tables

```bash
php artisan queue:table
php artisan queue:failed-table
php artisan migrate
```

### Step 3: Create Export Job

**File: `app/Jobs/GenerateCsvExportJob.php`**
```php
<?php

namespace App\Jobs;

use App\Services\PartitionQueryRouter;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateCsvExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutes
    public $tries = 3;

    protected string $type; // 'all-alerts' or 'vm-alerts'
    protected string $date;
    protected int $userId;
    protected string $jobId;

    public function __construct(string $type, string $date, int $userId, string $jobId)
    {
        $this->type = $type;
        $this->date = $date;
        $this->userId = $userId;
        $this->jobId = $jobId;
    }

    public function handle()
    {
        try {
            Log::info("CSV export job started", [
                'job_id' => $this->jobId,
                'type' => $this->type,
                'date' => $this->date,
                'user_id' => $this->userId
            ]);

            $fromDate = Carbon::parse($this->date)->startOfDay();
            $toDate = $fromDate->copy()->endOfDay();
            
            $filename = $this->type . '_' . $fromDate->format('Y-m-d') . '_' . $this->jobId . '.csv';
            $filepath = 'exports/' . $filename;

            // Generate CSV file
            $tempFile = tmpfile();
            $tempPath = stream_get_meta_data($tempFile)['uri'];
            
            $file = fopen($tempPath, 'w');
            fwrite($file, "\xEF\xBB\xBF"); // UTF-8 BOM

            // Write headers
            $headers = $this->getHeaders();
            fputcsv($file, $headers);

            // Fetch and write data in chunks
            $router = new PartitionQueryRouter();
            $chunkSize = 5000;
            $offset = 0;
            $totalRecords = 0;

            while (true) {
                $filters = $this->getFilters();
                $tablePrefixes = ['alerts', 'backalerts'];
                
                $results = $router->queryDateRange(
                    $fromDate,
                    $toDate,
                    $filters,
                    $tablePrefixes,
                    $chunkSize,
                    $offset
                );

                if ($results->isEmpty()) {
                    break;
                }

                foreach ($results as $record) {
                    $row = $this->formatRow($record, $totalRecords + 1);
                    fputcsv($file, $row);
                    $totalRecords++;
                }

                $offset += $results->count();

                Log::info("CSV export progress", [
                    'job_id' => $this->jobId,
                    'records' => $totalRecords,
                    'offset' => $offset
                ]);
            }

            fclose($file);

            // Store file
            Storage::disk('public')->put($filepath, file_get_contents($tempPath));
            unlink($tempPath);

            Log::info("CSV export job completed", [
                'job_id' => $this->jobId,
                'total_records' => $totalRecords,
                'filepath' => $filepath
            ]);

            // Update job status in database
            \DB::table('export_jobs')->where('job_id', $this->jobId)->update([
                'status' => 'completed',
                'filepath' => $filepath,
                'total_records' => $totalRecords,
                'completed_at' => now(),
            ]);

        } catch (\Exception $e) {
            Log::error("CSV export job failed", [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update job status
            \DB::table('export_jobs')->where('job_id', $this->jobId)->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    private function getHeaders(): array
    {
        if ($this->type === 'vm-alerts') {
            return [
                '#', 'Alert ID', 'Site', 'Zone', 'User', 'Message', 'Status',
                'Priority', 'Date/Time', 'Closed Date/Time', 'Closed By',
                'Reactive', 'Reactive Date/Time', 'Reactive By', 'Send to Client',
                'Client Remarks', 'Alarm Type', 'Partition', 'Account',
                'Receiver', 'Line', 'Reported', 'Cross', 'Round', 'Dialer',
                'Modem', 'Prefix'
            ];
        } else {
            return [
                '#', 'Alert ID', 'Site', 'Zone', 'User', 'Message', 'Status',
                'Priority', 'Date/Time', 'Closed Date/Time', 'Closed By',
                'Reactive', 'Reactive Date/Time', 'Reactive By', 'Send to Client',
                'Client Remarks', 'Alarm Type', 'Partition', 'Account',
                'Receiver', 'Line', 'Reported', 'Cross', 'Round', 'Dialer',
                'Modem', 'Prefix'
            ];
        }
    }

    private function getFilters(): array
    {
        if ($this->type === 'vm-alerts') {
            return [
                'vm_status' => ['O', 'C'],
                'vm_sendtoclient' => 'S',
            ];
        }
        return [];
    }

    private function formatRow($record, int $serialNumber): array
    {
        return [
            $serialNumber,
            $record->alertid ?? '',
            $record->site ?? '',
            $record->zone ?? '',
            $record->user ?? '',
            $record->msg ?? '',
            $record->status ?? '',
            $record->priority ?? '',
            $record->datetime ?? '',
            $record->closedatetime ?? '',
            $record->closeby ?? '',
            $record->reactive ?? '',
            $record->reactivedatetime ?? '',
            $record->reactiveby ?? '',
            $record->sendtoclient ?? '',
            $record->clientremarks ?? '',
            $record->alarmtype ?? '',
            $record->partition ?? '',
            $record->account ?? '',
            $record->receiver ?? '',
            $record->line ?? '',
            $record->reported ?? '',
            $record->cross ?? '',
            $record->round ?? '',
            $record->dialer ?? '',
            $record->modem ?? '',
            $record->prefix ?? '',
        ];
    }
}
```

### Step 4: Create Export Jobs Table

**Migration: `database/migrations/2026_01_31_create_export_jobs_table.php`**
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('type'); // 'all-alerts' or 'vm-alerts'
            $table->date('date');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('filepath')->nullable();
            $table->integer('total_records')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('export_jobs');
    }
};
```

### Step 5: Update Downloads Controller

**File: `app/Http/Controllers/DownloadsController.php`**
```php
use App\Jobs\GenerateCsvExportJob;
use Illuminate\Support\Str;

/**
 * POST /api/downloads/request
 * 
 * Request a CSV export (queued for background processing)
 */
public function requestExport(Request $request): JsonResponse
{
    try {
        $validated = $request->validate([
            'type' => 'required|string|in:all-alerts,vm-alerts',
            'date' => 'required|date',
        ]);

        $jobId = Str::uuid()->toString();
        $userId = auth()->id();

        // Create job record
        \DB::table('export_jobs')->insert([
            'job_id' => $jobId,
            'user_id' => $userId,
            'type' => $validated['type'],
            'date' => $validated['date'],
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Dispatch job to queue
        GenerateCsvExportJob::dispatch(
            $validated['type'],
            $validated['date'],
            $userId,
            $jobId
        );

        return response()->json([
            'success' => true,
            'data' => [
                'job_id' => $jobId,
                'message' => 'Export queued successfully. You will be notified when ready.',
            ],
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to request export', [
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'REQUEST_ERROR',
                'message' => 'Failed to request export',
                'details' => $e->getMessage(),
            ],
        ], 500);
    }
}

/**
 * GET /api/downloads/status/{jobId}
 * 
 * Check export job status
 */
public function checkStatus(string $jobId): JsonResponse
{
    try {
        $job = \DB::table('export_jobs')
            ->where('job_id', $jobId)
            ->where('user_id', auth()->id())
            ->first();

        if (!$job) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Export job not found',
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'job_id' => $job->job_id,
                'status' => $job->status,
                'total_records' => $job->total_records,
                'filepath' => $job->filepath,
                'error_message' => $job->error_message,
                'created_at' => $job->created_at,
                'completed_at' => $job->completed_at,
            ],
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to check export status', [
            'job_id' => $jobId,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'STATUS_ERROR',
                'message' => 'Failed to check export status',
                'details' => $e->getMessage(),
            ],
        ], 500);
    }
}

/**
 * GET /api/downloads/file/{jobId}
 * 
 * Download completed export file
 */
public function downloadFile(string $jobId)
{
    try {
        $job = \DB::table('export_jobs')
            ->where('job_id', $jobId)
            ->where('user_id', auth()->id())
            ->where('status', 'completed')
            ->first();

        if (!$job) {
            abort(404, 'Export file not found or not ready');
        }

        if (!Storage::disk('public')->exists($job->filepath)) {
            abort(404, 'Export file not found on disk');
        }

        return Storage::disk('public')->download($job->filepath);

    } catch (\Exception $e) {
        Log::error('Failed to download export file', [
            'job_id' => $jobId,
            'error' => $e->getMessage(),
        ]);

        abort(500, 'Failed to download export file');
    }
}

/**
 * GET /api/downloads/my-exports
 * 
 * Get user's export history
 */
public function myExports(): JsonResponse
{
    try {
        $exports = \DB::table('export_jobs')
            ->where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $exports,
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to fetch export history', [
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'FETCH_ERROR',
                'message' => 'Failed to fetch export history',
                'details' => $e->getMessage(),
            ],
        ], 500);
    }
}
```

### Step 6: Add Routes

**File: `routes/api.php`**
```php
// Queue-based CSV exports
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/downloads/request', [DownloadsController::class, 'requestExport']);
    Route::get('/downloads/status/{jobId}', [DownloadsController::class, 'checkStatus']);
    Route::get('/downloads/file/{jobId}', [DownloadsController::class, 'downloadFile']);
    Route::get('/downloads/my-exports', [DownloadsController::class, 'myExports']);
});
```

### Step 7: Start Queue Worker as Windows Service

**File: `codes/create-queue-worker-service.ps1`**
```powershell
# Create Queue Worker Windows Service using NSSM

$serviceName = "AlertPortalQueueWorker"
$phpPath = "C:\wamp64\bin\php\php8.2.13\php.exe"
$artisanPath = "C:\wamp64\www\alert-portal\artisan"
$logPath = "C:\wamp64\www\alert-portal\storage\logs"

# Stop and remove existing service if it exists
$existingService = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
if ($existingService) {
    Write-Host "Stopping existing service..."
    nssm stop $serviceName
    Start-Sleep -Seconds 2
    Write-Host "Removing existing service..."
    nssm remove $serviceName confirm
    Start-Sleep -Seconds 2
}

# Create new service
Write-Host "Creating queue worker service..."
nssm install $serviceName $phpPath "$artisanPath" "queue:work" "--sleep=3" "--tries=3" "--max-time=3600"

# Configure service
nssm set $serviceName AppDirectory "C:\wamp64\www\alert-portal"
nssm set $serviceName DisplayName "Alert Portal Queue Worker"
nssm set $serviceName Description "Processes background jobs for CSV exports and other tasks"
nssm set $serviceName Start SERVICE_AUTO_START

# Configure logging
nssm set $serviceName AppStdout "$logPath\queue-worker-service.log"
nssm set $serviceName AppStderr "$logPath\queue-worker-service-error.log"
nssm set $serviceName AppStdoutCreationDisposition 4
nssm set $serviceName AppStderrCreationDisposition 4
nssm set $serviceName AppRotateFiles 1
nssm set $serviceName AppRotateOnline 1
nssm set $serviceName AppRotateSeconds 86400
nssm set $serviceName AppRotateBytes 10485760

# Start service
Write-Host "Starting queue worker service..."
nssm start $serviceName

Start-Sleep -Seconds 2

# Check status
$status = nssm status $serviceName
Write-Host "`nService Status: $status"

if ($status -eq "SERVICE_RUNNING") {
    Write-Host "`n✅ Queue worker service created and started successfully!" -ForegroundColor Green
    Write-Host "`nService Details:"
    Write-Host "  Name: $serviceName"
    Write-Host "  Command: $phpPath $artisanPath queue:work --sleep=3 --tries=3 --max-time=3600"
    Write-Host "  Logs: $logPath\queue-worker-service.log"
    Write-Host "  Error Logs: $logPath\queue-worker-service-error.log"
} else {
    Write-Host "`n❌ Failed to start queue worker service" -ForegroundColor Red
    Write-Host "Check error log: $logPath\queue-worker-service-error.log"
}
```

### Step 8: Update Frontend

**File: `resources/js/pages/Downloads.jsx`**
```jsx
// Add polling for job status
const [exportJobs, setExportJobs] = useState([]);
const [polling, setPolling] = useState(false);

const requestExport = async (type, date) => {
    try {
        const response = await axios.post('/api/downloads/request', {
            type,
            date
        });
        
        if (response.data.success) {
            toast.success('Export queued! You will be notified when ready.');
            pollJobStatus(response.data.data.job_id);
        }
    } catch (error) {
        toast.error('Failed to request export');
    }
};

const pollJobStatus = async (jobId) => {
    const interval = setInterval(async () => {
        try {
            const response = await axios.get(`/api/downloads/status/${jobId}`);
            const job = response.data.data;
            
            if (job.status === 'completed') {
                clearInterval(interval);
                toast.success('Export ready! Click to download.');
                fetchMyExports();
            } else if (job.status === 'failed') {
                clearInterval(interval);
                toast.error('Export failed: ' + job.error_message);
                fetchMyExports();
            }
        } catch (error) {
            clearInterval(interval);
        }
    }, 5000); // Poll every 5 seconds
};

const downloadFile = async (jobId) => {
    window.location.href = `/api/downloads/file/${jobId}`;
};
```

## Deployment Steps

1. **Update .env**
   ```bash
   QUEUE_CONNECTION=database
   ```

2. **Run migrations**
   ```bash
   php artisan queue:table
   php artisan queue:failed-table
   php artisan migrate
   ```

3. **Create queue worker service**
   ```powershell
   .\codes\create-queue-worker-service.ps1
   ```

4. **Verify service is running**
   ```powershell
   Get-Service AlertPortalQueueWorker
   ```

5. **Test with small export**
   - Request export from Downloads page
   - Check queue worker log: `storage\logs\queue-worker-service.log`
   - Verify job completes and file is downloadable

## Monitoring

### Check Queue Status
```bash
php artisan queue:work --once  # Process one job
php artisan queue:failed       # List failed jobs
php artisan queue:retry all    # Retry failed jobs
```

### Check Service Logs
```powershell
Get-Content storage\logs\queue-worker-service.log -Tail 50
Get-Content storage\logs\queue-worker-service-error.log -Tail 50
```

### Check Export Jobs
```sql
SELECT * FROM export_jobs ORDER BY created_at DESC LIMIT 10;
```

## Benefits Summary

✅ **Portal Never Blocks** - Requests return immediately
✅ **Unlimited Concurrent Requests** - Queue handles them sequentially
✅ **Better UX** - Progress tracking, notifications
✅ **Scalable** - Add more queue workers as needed
✅ **Fault Tolerant** - Failed jobs can be retried
✅ **Resource Efficient** - Dedicated workers for background tasks
✅ **Production Ready** - Industry standard solution

## Alternative: Increase PHP Workers (Quick Fix)

If you need a quick fix before implementing queues:

**File: `C:\wamp64\bin\apache\apache2.4.XX\conf\extra\httpd-mpm.conf`**
```apache
<IfModule mpm_winnt_module>
    ThreadsPerChild      250  # Increase from 150
    MaxRequestsPerChild  0
</IfModule>
```

Then restart Apache. This gives you more workers but doesn't solve the fundamental problem.
