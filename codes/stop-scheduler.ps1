# ============================================
# Stop Laravel Scheduler
# ============================================
# This script stops all running Laravel scheduler processes

Write-Host "=== Laravel Scheduler Stopper ===" -ForegroundColor Cyan
Write-Host ""

# Find all PHP processes related to the scheduler
$schedulerProcesses = Get-Process -Name "php" -ErrorAction SilentlyContinue | Where-Object {
    $cmdLine = $_.CommandLine
    $cmdLine -like "*schedule:run*" -or $cmdLine -like "*schedule:work*" -or $cmdLine -like "*artisan*"
}

# Also find PowerShell processes running the scheduler script
$psProcesses = Get-Process -Name "powershell" -ErrorAction SilentlyContinue | Where-Object {
    $_.CommandLine -like "*start-scheduler.ps1*"
}

$allProcesses = @()
if ($schedulerProcesses) { $allProcesses += $schedulerProcesses }
if ($psProcesses) { $allProcesses += $psProcesses }

if ($allProcesses.Count -eq 0) {
    Write-Host "No scheduler processes found running." -ForegroundColor Yellow
    Write-Host ""
    Read-Host "Press Enter to exit"
    exit 0
}

Write-Host "Found $($allProcesses.Count) scheduler process(es):" -ForegroundColor Yellow
foreach ($proc in $allProcesses) {
    Write-Host "  - PID: $($proc.Id) | Name: $($proc.ProcessName)" -ForegroundColor Gray
}
Write-Host ""

$confirm = Read-Host "Do you want to stop these processes? (y/n)"

if ($confirm -eq 'y') {
    Write-Host ""
    Write-Host "Stopping scheduler processes..." -ForegroundColor Yellow
    
    foreach ($proc in $allProcesses) {
        try {
            Stop-Process -Id $proc.Id -Force
            Write-Host "OK Stopped process $($proc.Id)" -ForegroundColor Green
        } catch {
            Write-Host "ERROR Failed to stop process $($proc.Id): $_" -ForegroundColor Red
        }
    }
    
    Write-Host ""
    Write-Host "Scheduler stopped successfully!" -ForegroundColor Green
} else {
    Write-Host ""
    Write-Host "Operation cancelled." -ForegroundColor Gray
}

Write-Host ""
Read-Host "Press Enter to exit"

