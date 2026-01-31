# ============================================
# Stop Alert Sync Process
# ============================================

Write-Host "=== Stop Alert Sync ===" -ForegroundColor Cyan
Write-Host ""

# Check for NSSM services
Write-Host "Checking for Windows services..." -ForegroundColor Yellow
$services = @("AlertInitialSync", "AlertUpdateSync")
$servicesFound = $false

foreach ($serviceName in $services) {
    $service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
    
    if ($service) {
        $servicesFound = $true
        $status = $service.Status
        
        if ($status -eq "Running") {
            Write-Host "Stopping $serviceName..." -ForegroundColor Yellow
            Stop-Service -Name $serviceName -Force
            Write-Host "OK $serviceName stopped" -ForegroundColor Green
        } else {
            Write-Host "OK $serviceName already stopped ($status)" -ForegroundColor Gray
        }
    }
}

if (-not $servicesFound) {
    Write-Host "No Windows services found" -ForegroundColor Gray
}

Write-Host ""
Write-Host "Checking for running PHP processes..." -ForegroundColor Yellow

# Find PHP processes running sync commands
$phpProcesses = Get-Process -Name php -ErrorAction SilentlyContinue

if ($phpProcesses) {
    Write-Host "Found $($phpProcesses.Count) PHP process(es)" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Do you want to stop these processes? (Y/N)" -ForegroundColor Yellow
    $confirm = Read-Host
    
    if ($confirm -eq "Y" -or $confirm -eq "y") {
        foreach ($proc in $phpProcesses) {
            Write-Host "Stopping process $($proc.Id)..." -ForegroundColor Yellow
            Stop-Process -Id $proc.Id -Force
            Write-Host "OK Process $($proc.Id) stopped" -ForegroundColor Green
        }
    } else {
        Write-Host "Processes not stopped" -ForegroundColor Gray
    }
} else {
    Write-Host "No PHP processes running" -ForegroundColor Gray
}

Write-Host ""
Write-Host "=== Sync Status ===" -ForegroundColor Cyan
Write-Host ""

# Show current sync status
try {
    php artisan sync:partitioned --status 2>$null
} catch {
    Write-Host "Could not retrieve sync status" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "=== All sync processes stopped ===" -ForegroundColor Green
Write-Host ""
Write-Host "To restart sync:" -ForegroundColor Yellow
Write-Host "  Manual mode: .\codes\start-sync.ps1" -ForegroundColor White
Write-Host "  Service mode: Start-Service AlertInitialSync" -ForegroundColor White
Write-Host ""
