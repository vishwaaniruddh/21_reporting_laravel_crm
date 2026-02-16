# Create Queue Worker V2 Windows Service using NSSM
# This is a Redis-based queue worker for testing Downloads V2
# Runs parallel to the existing V1 database queue worker

$serviceName = "AlertPortalQueueWorkerV2"
$batchFile = "C:\wamp64\www\comfort_reporting_crm\dual-database-app\codes\queue-worker-v2.bat"
$workingDir = "C:\wamp64\www\comfort_reporting_crm\dual-database-app"
$logPath = "$workingDir\storage\logs"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Queue Worker V2 Service Setup (Redis)" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Check if NSSM is available
$nssmPath = Get-Command nssm -ErrorAction SilentlyContinue
if (-not $nssmPath) {
    Write-Host "❌ ERROR: NSSM not found in PATH" -ForegroundColor Red
    Write-Host "Please install NSSM first: https://nssm.cc/download" -ForegroundColor Yellow
    exit 1
}

# Check if batch file exists
if (-not (Test-Path $batchFile)) {
    Write-Host "❌ ERROR: Batch file not found at $batchFile" -ForegroundColor Red
    exit 1
}

# Check if Redis is running
Write-Host "Checking Redis connection..." -ForegroundColor Cyan
$redisTest = & redis-cli ping 2>&1
if ($redisTest -ne "PONG") {
    Write-Host "⚠️  WARNING: Redis is not responding" -ForegroundColor Yellow
    Write-Host "Make sure Redis is installed and running" -ForegroundColor Yellow
    Write-Host "Continue anyway? (Y/N): " -NoNewline -ForegroundColor Yellow
    $continue = Read-Host
    if ($continue -ne "Y") {
        exit 1
    }
}

# Stop and remove existing service if it exists
$existingService = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
if ($existingService) {
    Write-Host "⚠️  Existing V2 service found. Stopping and removing..." -ForegroundColor Yellow
    nssm stop $serviceName
    Start-Sleep -Seconds 2
    nssm remove $serviceName confirm
    Start-Sleep -Seconds 2
    Write-Host "✅ Existing service removed" -ForegroundColor Green
    Write-Host ""
}

# Create new service
Write-Host "Creating queue worker V2 service..." -ForegroundColor Cyan
nssm install $serviceName $batchFile

if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ Failed to create service" -ForegroundColor Red
    exit 1
}

# Configure service
Write-Host "Configuring service..." -ForegroundColor Cyan
nssm set $serviceName AppDirectory $workingDir
nssm set $serviceName DisplayName "Alert Portal Queue Worker V2 (Redis)"
nssm set $serviceName Description "Redis-based queue worker for testing V2 exports. Runs parallel to V1."
nssm set $serviceName Start SERVICE_AUTO_START

# Configure logging
nssm set $serviceName AppStdout "$logPath\queue-worker-v2-service.log"
nssm set $serviceName AppStderr "$logPath\queue-worker-v2-service-error.log"
nssm set $serviceName AppStdoutCreationDisposition 4
nssm set $serviceName AppStderrCreationDisposition 4
nssm set $serviceName AppRotateFiles 1
nssm set $serviceName AppRotateOnline 1
nssm set $serviceName AppRotateSeconds 86400
nssm set $serviceName AppRotateBytes 10485760

# Start service
Write-Host "Starting queue worker V2 service..." -ForegroundColor Cyan
nssm start $serviceName

Start-Sleep -Seconds 3

# Check status
$status = nssm status $serviceName
Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Service Status: $status" -ForegroundColor $(if ($status -eq "SERVICE_RUNNING") { "Green" } else { "Red" })
Write-Host "========================================" -ForegroundColor Cyan

if ($status -eq "SERVICE_RUNNING") {
    Write-Host ""
    Write-Host "✅ Queue worker V2 service created and started successfully!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Service Details:" -ForegroundColor Cyan
    Write-Host "  Name: $serviceName" -ForegroundColor White
    Write-Host "  Type: Redis-based (Testing)" -ForegroundColor White
    Write-Host "  Queue: exports-v2" -ForegroundColor White
    Write-Host "  Command: php artisan queue:work redis --queue=exports-v2" -ForegroundColor White
    Write-Host "  Working Directory: $workingDir" -ForegroundColor White
    Write-Host "  Logs: $logPath\queue-worker-v2-service.log" -ForegroundColor White
    Write-Host "  Error Logs: $logPath\queue-worker-v2-service-error.log" -ForegroundColor White
    Write-Host ""
    Write-Host "Configuration:" -ForegroundColor Cyan
    Write-Host "  Sleep: 3 seconds between jobs" -ForegroundColor White
    Write-Host "  Tries: 3 attempts per job" -ForegroundColor White
    Write-Host "  Max Time: 3600 seconds (1 hour) per worker" -ForegroundColor White
    Write-Host ""
    Write-Host "Comparison:" -ForegroundColor Cyan
    Write-Host "  V1 (Database): AlertPortalQueueWorker" -ForegroundColor White
    Write-Host "  V2 (Redis):    AlertPortalQueueWorkerV2" -ForegroundColor White
    Write-Host ""
    Write-Host "Management Commands:" -ForegroundColor Cyan
    Write-Host "  Start:   nssm start $serviceName" -ForegroundColor White
    Write-Host "  Stop:    nssm stop $serviceName" -ForegroundColor White
    Write-Host "  Restart: nssm restart $serviceName" -ForegroundColor White
    Write-Host "  Status:  nssm status $serviceName" -ForegroundColor White
    Write-Host "  Logs:    Get-Content $logPath\queue-worker-v2-service.log -Tail 50" -ForegroundColor White
    Write-Host ""
    Write-Host "Testing:" -ForegroundColor Cyan
    Write-Host "  API: POST /api/downloads-v2/request" -ForegroundColor White
    Write-Host "  Monitor: redis-cli MONITOR" -ForegroundColor White
    Write-Host "  Queue: redis-cli LLEN queues:exports-v2" -ForegroundColor White
    Write-Host ""
    Write-Host "📖 Full documentation: Documents\DOWNLOADS_V2_REDIS_SETUP.md" -ForegroundColor Yellow
    Write-Host ""
} else {
    Write-Host ""
    Write-Host "❌ Failed to start queue worker V2 service" -ForegroundColor Red
    Write-Host "Check error log: $logPath\queue-worker-v2-service-error.log" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Troubleshooting:" -ForegroundColor Cyan
    Write-Host "  1. Check if Redis is running: redis-cli ping" -ForegroundColor White
    Write-Host "  2. Check if PHP Redis extension is enabled: php -m | findstr redis" -ForegroundColor White
    Write-Host "  3. Check error log for details" -ForegroundColor White
    Write-Host "  4. Try running manually: php artisan queue:work redis --queue=exports-v2 --once" -ForegroundColor White
    Write-Host ""
}
