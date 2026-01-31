# ============================================
# Start Laravel Scheduler as Background Service
# ============================================
# Uses Laravel's schedule:work command which is more efficient
# and runs continuously without the 60-second loop overhead

Write-Host "=== Laravel Scheduler Service Starter ===" -ForegroundColor Cyan
Write-Host ""

# Check if scheduler is already running
$existingProcess = Get-Process -Name "php" -ErrorAction SilentlyContinue | Where-Object {
    $_.CommandLine -like "*schedule:work*"
}

if ($existingProcess) {
    Write-Host "WARNING: Scheduler service is already running!" -ForegroundColor Yellow
    Write-Host "Process ID: $($existingProcess.Id)" -ForegroundColor Yellow
    Write-Host ""
    $continue = Read-Host "Do you want to restart it? (y/n)"
    if ($continue -eq 'y') {
        Write-Host "Stopping existing scheduler..." -ForegroundColor Yellow
        Stop-Process -Id $existingProcess.Id -Force
        Start-Sleep -Seconds 2
    } else {
        Write-Host "Exiting..." -ForegroundColor Gray
        exit 0
    }
}

Write-Host "Starting scheduler service in background..." -ForegroundColor Yellow
Write-Host ""

# Get the current directory
$scriptPath = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectRoot = Split-Path -Parent $scriptPath

# Start the scheduler using schedule:work (runs continuously)
$process = Start-Process -FilePath "php" `
    -ArgumentList "artisan", "schedule:work" `
    -WorkingDirectory $projectRoot `
    -WindowStyle Hidden `
    -PassThru

# Wait a moment for the process to start
Start-Sleep -Seconds 2

# Verify it started
if ($process -and !$process.HasExited) {
    Write-Host "OK Scheduler service started successfully!" -ForegroundColor Green
    Write-Host "Process ID: $($process.Id)" -ForegroundColor Green
    Write-Host ""
    Write-Host "The scheduler is now running as a background service." -ForegroundColor White
    Write-Host "It will continue running even if you close this window." -ForegroundColor White
    Write-Host ""
    Write-Host "Benefits of schedule:work:" -ForegroundColor Cyan
    Write-Host "  - More efficient (no 60-second loop overhead)" -ForegroundColor Gray
    Write-Host "  - Runs tasks exactly on schedule" -ForegroundColor Gray
    Write-Host "  - Lower CPU usage" -ForegroundColor Gray
    Write-Host ""
    Write-Host "To stop the scheduler, run:" -ForegroundColor Yellow
    Write-Host "  .\codes\stop-scheduler.ps1" -ForegroundColor Gray
    Write-Host ""
    Write-Host "To check status:" -ForegroundColor Yellow
    Write-Host "  php artisan pipeline:status" -ForegroundColor Gray
} else {
    Write-Host "ERROR: Failed to start scheduler service!" -ForegroundColor Red
    Write-Host "Check that PHP and Laravel are properly configured." -ForegroundColor Yellow
}

Write-Host ""
Read-Host "Press Enter to exit"

