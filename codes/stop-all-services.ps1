# ============================================
# Stop All NSSM Services
# ============================================
# Run this script as Administrator

Write-Host ""
Write-Host "=== Stopping All NSSM Services ===" -ForegroundColor Cyan
Write-Host ""

# Get all services (Alert*, BackAlert*, and Sites*)
$alertServices = Get-Service | Where-Object {$_.Name -like 'Alert*'}
$backAlertServices = Get-Service | Where-Object {$_.Name -like 'BackAlert*'}
$sitesServices = Get-Service | Where-Object {$_.Name -like 'Sites*'}
$allServices = $alertServices + $backAlertServices + $sitesServices

if ($allServices.Count -eq 0) {
    Write-Host "No services found!" -ForegroundColor Red
    Write-Host ""
    exit
}

Write-Host "Found $($allServices.Count) service(s) to stop:" -ForegroundColor Yellow
Write-Host ""

foreach ($service in $allServices) {
    Write-Host "Service: $($service.Name)" -ForegroundColor White
    Write-Host "  Current Status: $($service.Status)" -ForegroundColor $(if ($service.Status -eq 'Running') { 'Green' } else { 'Gray' })
    
    if ($service.Status -eq 'Running') {
        try {
            Write-Host "  Stopping..." -ForegroundColor Yellow -NoNewline
            Stop-Service -Name $service.Name -Force -ErrorAction Stop
            Write-Host " STOPPED" -ForegroundColor Green
        } catch {
            Write-Host " FAILED" -ForegroundColor Red
            Write-Host "  Error: $($_.Exception.Message)" -ForegroundColor Red
        }
    } else {
        Write-Host "  Already stopped" -ForegroundColor Gray
    }
    Write-Host ""
}

Write-Host ""
Write-Host "=== Final Status ===" -ForegroundColor Cyan
Write-Host ""

# Refresh and show final status
$allServices = Get-Service Alert*,BackAlert*,Sites*
foreach ($service in $allServices) {
    $statusColor = if ($service.Status -eq 'Stopped') { 'Green' } else { 'Red' }
    Write-Host "$($service.Name): $($service.Status)" -ForegroundColor $statusColor
}

Write-Host ""
Write-Host "All services stopped!" -ForegroundColor Green
Write-Host ""
Write-Host "To start services again, run:" -ForegroundColor Yellow
Write-Host "  .\codes\start-all-services.ps1" -ForegroundColor Gray
Write-Host ""

Read-Host "Press Enter to exit"
