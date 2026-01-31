# Create Queue Worker Windows Service using NSSM
# This service processes background jobs for CSV exports and other tasks
# Prevents portal blocking by handling long-running tasks in the background

$serviceName = "AlertPortalQueueWorker"
$phpPath = "C:\wamp64\bin\php\php8.2.13\php.exe"
$artisanPath = "C:\wamp64\www\comfort_reporting_crm\dual-database-app\artisan"
$workingDir = "C:\wamp64\www\comfort_reporting_crm\dual-database-app"
$logPath = "$workingDir\storage\logs"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Queue Worker Service Setup" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Check if NSSM is available
$nssmPath = Get-Command nssm -ErrorAction SilentlyContinue
if (-not $nssmPath) {
    Write-Host "❌ ERROR: NSSM not found in PATH" -ForegroundColor Red
    Write-Host "Please install NSSM first: https://nssm.cc/download" -ForegroundColor Yellow
    exit 1
}

# Check if PHP exists
if (-not (Test-Path $phpPath)) {
    Write-Host "❌ ERROR: PHP not found at $phpPath" -ForegroundColor Red
    Write-Host "Please update the `$phpPath variable in this script" -ForegroundColor Yellow
    exit 1
}

# Check if artisan exists
if (-not (Test-Path $artisanPath)) {
    Write-Host "❌ ERROR: Artisan not found at $artisanPath" -ForegroundColor Red
    Write-Host "Please update the `$artisanPath variable in this script" -ForegroundColor Yellow
    exit 1
}

# Stop and remove existing service if it exists
$existingService = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
if ($existingService) {
    Write-Host "⚠️  Existing service found. Stopping and removing..." -ForegroundColor Yellow
    nssm stop $serviceName
    Start-Sleep -Seconds 2
    nssm remove $serviceName confirm
    Start-Sleep -Seconds 2
    Write-Host "✅ Existing service removed" -ForegroundColor Green
    Write-Host ""
}

# Create new service
Write-Host "Creating queue worker service..." -ForegroundColor Cyan
nssm install $serviceName $phpPath "$artisanPath" "queue:work" "--sleep=3" "--tries=3" "--max-time=3600"

if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ Failed to create service" -ForegroundColor Red
    exit 1
}

# Configure service
Write-Host "Configuring service..." -ForegroundColor Cyan
nssm set $serviceName AppDirectory $workingDir
nssm set $serviceName DisplayName "Alert Portal Queue Worker"
nssm set $serviceName Description "Processes background jobs for CSV exports and other tasks. Prevents portal blocking."
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
Write-Host "Starting queue worker service..." -ForegroundColor Cyan
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
    Write-Host "✅ Queue worker service created and started successfully!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Service Details:" -ForegroundColor Cyan
    Write-Host "  Name: $serviceName" -ForegroundColor White
    Write-Host "  Command: $phpPath $artisanPath queue:work --sleep=3 --tries=3 --max-time=3600" -ForegroundColor White
    Write-Host "  Working Directory: $workingDir" -ForegroundColor White
    Write-Host "  Logs: $logPath\queue-worker-service.log" -ForegroundColor White
    Write-Host "  Error Logs: $logPath\queue-worker-service-error.log" -ForegroundColor White
    Write-Host ""
    Write-Host "Configuration:" -ForegroundColor Cyan
    Write-Host "  Sleep: 3 seconds between jobs" -ForegroundColor White
    Write-Host "  Tries: 3 attempts per job" -ForegroundColor White
    Write-Host "  Max Time: 3600 seconds (1 hour) per worker" -ForegroundColor White
    Write-Host ""
    Write-Host "Management Commands:" -ForegroundColor Cyan
    Write-Host "  Start:   nssm start $serviceName" -ForegroundColor White
    Write-Host "  Stop:    nssm stop $serviceName" -ForegroundColor White
    Write-Host "  Restart: nssm restart $serviceName" -ForegroundColor White
    Write-Host "  Status:  nssm status $serviceName" -ForegroundColor White
    Write-Host "  Logs:    Get-Content $logPath\queue-worker-service.log -Tail 50" -ForegroundColor White
    Write-Host ""
} else {
    Write-Host ""
    Write-Host "❌ Failed to start queue worker service" -ForegroundColor Red
    Write-Host "Check error log: $logPath\queue-worker-service-error.log" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Troubleshooting:" -ForegroundColor Cyan
    Write-Host "  1. Check if PHP path is correct: $phpPath" -ForegroundColor White
    Write-Host "  2. Check if artisan path is correct: $artisanPath" -ForegroundColor White
    Write-Host "  3. Check error log for details" -ForegroundColor White
    Write-Host "  4. Try running manually: $phpPath $artisanPath queue:work" -ForegroundColor White
    Write-Host ""
}
