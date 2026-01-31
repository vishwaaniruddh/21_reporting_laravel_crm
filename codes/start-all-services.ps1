# ============================================
# Start All NSSM Services
# ============================================
# Run this script as Administrator

Write-Host ""
Write-Host "=== Starting All NSSM Services ===" -ForegroundColor Cyan
Write-Host ""

# Get all services (Alert* and Sites*)
$alertServices = Get-Service | Where-Object {$_.Name -like 'Alert*'}
$sitesServices = Get-Service | Where-Object {$_.Name -like 'Sites*'}
$allServices = $alertServices + $sitesServices

if ($allServices.Count -eq 0) {
    Write-Host "No services found!" -ForegroundColor Red
    Write-Host ""
    Write-Host "To create services, run:" -ForegroundColor Yellow
    Write-Host "  .\codes\setup-services.ps1" -ForegroundColor Gray
    Write-Host "  .\codes\create-sites-update-service.ps1" -ForegroundColor Gray
    Write-Host ""
    exit
}

Write-Host "Found $($allServices.Count) service(s) to start:" -ForegroundColor Yellow
Write-Host ""

foreach ($service in $allServices) {
    Write-Host "Service: $($service.Name)" -ForegroundColor White
    Write-Host "  Current Status: $($service.Status)" -ForegroundColor $(if ($service.Status -eq 'Stopped') { 'Red' } else { 'Green' })
    
    if ($service.Status -ne 'Running') {
        try {
            Write-Host "  Starting..." -ForegroundColor Yellow -NoNewline
            Start-Service -Name $service.Name -ErrorAction Stop
            Write-Host " STARTED" -ForegroundColor Green
        } catch {
            Write-Host " FAILED" -ForegroundColor Red
            Write-Host "  Error: $($_.Exception.Message)" -ForegroundColor Red
        }
    } else {
        Write-Host "  Already running" -ForegroundColor Gray
    }
    Write-Host ""
}

Write-Host ""
Write-Host "=== Final Status ===" -ForegroundColor Cyan
Write-Host ""

# Refresh and show final status
$allServices = Get-Service Alert*,Sites*
foreach ($service in $allServices) {
    $statusColor = if ($service.Status -eq 'Running') { 'Green' } else { 'Red' }
    Write-Host "$($service.Name): $($service.Status)" -ForegroundColor $statusColor
}

Write-Host ""
Write-Host "All services started!" -ForegroundColor Green
Write-Host ""
Write-Host "To check service status, run:" -ForegroundColor Yellow
Write-Host "  .\codes\check-all-nssm-services.ps1" -ForegroundColor Gray
Write-Host ""
Write-Host "To stop services, run:" -ForegroundColor Yellow
Write-Host "  .\codes\stop-all-services.ps1" -ForegroundColor Gray
Write-Host ""

Read-Host "Press Enter to exit"
