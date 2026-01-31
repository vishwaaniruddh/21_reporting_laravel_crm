# ============================================
# Check Laravel Scheduler Status
# ============================================
# Shows if the scheduler is running and provides sync status

Write-Host "=== Laravel Scheduler Status ===" -ForegroundColor Cyan
Write-Host ""

# Check for running scheduler processes
$schedulerProcesses = Get-Process -Name "php" -ErrorAction SilentlyContinue | Where-Object {
    $_.CommandLine -like "*schedule:work*" -or $_.CommandLine -like "*schedule:run*"
}

if ($schedulerProcesses) {
    Write-Host "OK Scheduler is RUNNING" -ForegroundColor Green
    Write-Host ""
    Write-Host "Active Processes:" -ForegroundColor Yellow
    foreach ($proc in $schedulerProcesses) {
        $uptime = (Get-Date) - $proc.StartTime
        Write-Host "  - PID: $($proc.Id)" -ForegroundColor White
        Write-Host "    Started: $($proc.StartTime.ToString('yyyy-MM-dd HH:mm:ss'))" -ForegroundColor Gray
        Write-Host "    Uptime: $($uptime.Hours)h $($uptime.Minutes)m $($uptime.Seconds)s" -ForegroundColor Gray
        Write-Host "    Memory: $([math]::Round($proc.WorkingSet64 / 1MB, 2)) MB" -ForegroundColor Gray
    }
} else {
    Write-Host "WARNING: Scheduler is NOT running" -ForegroundColor Red
    Write-Host ""
    Write-Host "To start the scheduler, run:" -ForegroundColor Yellow
    Write-Host "  .\codes\start-scheduler-service.ps1" -ForegroundColor Gray
}

Write-Host ""
Write-Host "=== Sync Status ===" -ForegroundColor Cyan
Write-Host ""

# Get sync status from Laravel
php artisan sync:partitioned --status

Write-Host ""
Write-Host "=== Pipeline Configuration ===" -ForegroundColor Cyan
Write-Host ""

# Show pipeline status
php artisan pipeline:schedule-list

Write-Host ""
Read-Host "Press Enter to exit"

