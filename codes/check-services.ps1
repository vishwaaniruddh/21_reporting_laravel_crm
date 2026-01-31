# ============================================
# Check Alert System Services Status
# ============================================

Write-Host "=== Alert System Services Status ===" -ForegroundColor Cyan
Write-Host ""

# Check if NSSM services exist
$services = @("AlertPortal", "AlertInitialSync", "AlertUpdateSync")
$servicesFound = $false

foreach ($serviceName in $services) {
    $service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
    
    if ($service) {
        $servicesFound = $true
        $status = $service.Status
        $color = if ($status -eq "Running") { "Green" } else { "Yellow" }
        
        Write-Host "$serviceName : " -NoNewline
        Write-Host $status -ForegroundColor $color
    }
}

if (-not $servicesFound) {
    Write-Host "No Alert services found." -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Services have not been set up yet." -ForegroundColor Gray
    Write-Host "To set up services, run:" -ForegroundColor Yellow
    Write-Host "  .\codes\setup-services.ps1" -ForegroundColor White
    Write-Host ""
    Write-Host "Or run sync manually:" -ForegroundColor Yellow
    Write-Host "  .\codes\start-sync.ps1" -ForegroundColor White
} else {
    Write-Host ""
    Write-Host "=== Service Management ===" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "Start all services:" -ForegroundColor Yellow
    Write-Host "  Get-Service Alert* | Start-Service" -ForegroundColor White
    Write-Host ""
    Write-Host "Stop all services:" -ForegroundColor Yellow
    Write-Host "  Get-Service Alert* | Stop-Service" -ForegroundColor White
    Write-Host ""
    Write-Host "Restart all services:" -ForegroundColor Yellow
    Write-Host "  Get-Service Alert* | Restart-Service" -ForegroundColor White
    Write-Host ""
    Write-Host "View service logs:" -ForegroundColor Yellow
    Write-Host "  Get-Content storage\logs\initial-sync-service.log -Tail 50 -Wait" -ForegroundColor White
}

Write-Host ""
