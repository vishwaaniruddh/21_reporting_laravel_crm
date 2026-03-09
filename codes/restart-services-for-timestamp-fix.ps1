# ============================================
# Restart Services to Apply Timestamp Fix
# ============================================
# This script restarts all sync services to apply the timestamp sync fix
# that ensures timestamps are identical between MySQL and PostgreSQL

Write-Host "=== Restart Services for Timestamp Fix ===" -ForegroundColor Cyan
Write-Host ""

Write-Host "This will restart all sync services to apply the timestamp fix." -ForegroundColor Yellow
Write-Host "The fix ensures timestamps are synced identically without timezone conversion." -ForegroundColor Yellow
Write-Host ""

# Get all Alert and Sites services
$alertServices = Get-Service | Where-Object {$_.Name -like 'Alert*'}
$sitesServices = Get-Service | Where-Object {$_.Name -like 'Sites*'}
$backAlertServices = Get-Service | Where-Object {$_.Name -like 'BackAlert*'}
$allServices = $alertServices + $sitesServices + $backAlertServices

if ($allServices.Count -eq 0) {
    Write-Host "No services found!" -ForegroundColor Red
    Write-Host ""
    Write-Host "Services have not been set up yet." -ForegroundColor Yellow
    Write-Host "To set up services, run:" -ForegroundColor Yellow
    Write-Host "  .\codes\setup-services.ps1" -ForegroundColor Gray
    exit 1
}

Write-Host "Found $($allServices.Count) service(s) to restart:" -ForegroundColor Green
foreach ($service in $allServices) {
    Write-Host "  - $($service.Name) [$($service.Status)]" -ForegroundColor White
}
Write-Host ""

$confirm = Read-Host "Do you want to restart these services? (Y/N)"
if ($confirm -ne 'Y' -and $confirm -ne 'y') {
    Write-Host "Cancelled." -ForegroundColor Yellow
    exit 0
}

Write-Host ""
Write-Host "=== Step 1: Stopping Services ===" -ForegroundColor Cyan
Write-Host ""

foreach ($service in $allServices) {
    if ($service.Status -eq 'Running') {
        Write-Host "Stopping $($service.Name)..." -ForegroundColor Yellow
        try {
            Stop-Service -Name $service.Name -Force -ErrorAction Stop
            Write-Host "  ✓ Stopped" -ForegroundColor Green
        } catch {
            Write-Host "  ✗ Failed to stop: $($_.Exception.Message)" -ForegroundColor Red
        }
    } else {
        Write-Host "$($service.Name) is already stopped" -ForegroundColor Gray
    }
}

Write-Host ""
Write-Host "Waiting 5 seconds for services to fully stop..." -ForegroundColor Gray
Start-Sleep -Seconds 5

Write-Host ""
Write-Host "=== Step 2: Starting Services ===" -ForegroundColor Cyan
Write-Host ""

foreach ($service in $allServices) {
    Write-Host "Starting $($service.Name)..." -ForegroundColor Yellow
    try {
        Start-Service -Name $service.Name -ErrorAction Stop
        Write-Host "  ✓ Started" -ForegroundColor Green
    } catch {
        Write-Host "  ✗ Failed to start: $($_.Exception.Message)" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "Waiting 3 seconds for services to initialize..." -ForegroundColor Gray
Start-Sleep -Seconds 3

Write-Host ""
Write-Host "=== Step 3: Verifying Service Status ===" -ForegroundColor Cyan
Write-Host ""

# Refresh service status
$allServices = Get-Service Alert*,BackAlert*,Sites* -ErrorAction SilentlyContinue

$runningCount = 0
$stoppedCount = 0

foreach ($service in $allServices) {
    $statusColor = if ($service.Status -eq 'Running') { 'Green' } else { 'Red' }
    Write-Host "$($service.Name): " -NoNewline
    Write-Host $service.Status -ForegroundColor $statusColor
    
    if ($service.Status -eq 'Running') {
        $runningCount++
    } else {
        $stoppedCount++
    }
}

Write-Host ""
Write-Host "=== Summary ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Total Services: $($allServices.Count)" -ForegroundColor White
Write-Host "Running: $runningCount" -ForegroundColor Green
Write-Host "Stopped: $stoppedCount" -ForegroundColor $(if ($stoppedCount -gt 0) { 'Red' } else { 'Gray' })
Write-Host ""

if ($runningCount -eq $allServices.Count) {
    Write-Host "✓ All services restarted successfully!" -ForegroundColor Green
    Write-Host ""
    Write-Host "The timestamp fix is now active." -ForegroundColor Green
    Write-Host "New syncs will maintain identical timestamps between MySQL and PostgreSQL." -ForegroundColor Green
} else {
    Write-Host "⚠ Some services failed to start!" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Check service logs for errors:" -ForegroundColor Yellow
    Write-Host "  Get-Content storage\logs\*-service.log -Tail 50" -ForegroundColor Gray
}

Write-Host ""
Write-Host "=== Next Steps ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "1. Test the timestamp fix:" -ForegroundColor Yellow
Write-Host "   php test_timestamp_sync_fix.php" -ForegroundColor White
Write-Host ""
Write-Host "2. Monitor sync logs for timestamp validation:" -ForegroundColor Yellow
Write-Host "   Get-Content storage\logs\laravel.log -Tail 50 -Wait | Select-String 'timestamp'" -ForegroundColor White
Write-Host ""
Write-Host "3. Check service status:" -ForegroundColor Yellow
Write-Host "   .\codes\check-all-nssm-services.ps1" -ForegroundColor White
Write-Host ""
Write-Host "4. Verify timestamps in PostgreSQL match MySQL:" -ForegroundColor Yellow
Write-Host "   php codes\check-timestamp-mismatches-fast.php" -ForegroundColor White
Write-Host ""

Read-Host "Press Enter to exit"
