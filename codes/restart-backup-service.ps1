# Restart MySQL Backup Service
# This script restarts the AlertMysqlBackup service to apply fixes

Write-Host "============================================" -ForegroundColor Cyan
Write-Host "Restarting MySQL Backup Service" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

$serviceName = "AlertMysqlBackup"

# Check if service exists
$service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue

if ($null -eq $service) {
    Write-Host "Service not found: $serviceName" -ForegroundColor Red
    Write-Host ""
    Write-Host "Press any key to exit..."
    $null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
    exit 1
}

Write-Host "Current status: $($service.Status)" -ForegroundColor Yellow
Write-Host ""

# Stop the service
Write-Host "Stopping service..." -ForegroundColor Yellow
Stop-Service -Name $serviceName -Force

Start-Sleep -Seconds 2

# Start the service
Write-Host "Starting service..." -ForegroundColor Yellow
Start-Service -Name $serviceName

Start-Sleep -Seconds 2

# Check status
$service = Get-Service -Name $serviceName
Write-Host ""
Write-Host "============================================" -ForegroundColor Green
Write-Host "Service Restarted" -ForegroundColor Green
Write-Host "============================================" -ForegroundColor Green
Write-Host ""
Write-Host "New status: $($service.Status)" -ForegroundColor Green
Write-Host ""
Write-Host "The service has been restarted with the fix applied." -ForegroundColor Green
Write-Host "It will now properly handle backup scheduling." -ForegroundColor Green
Write-Host ""
Write-Host "Press any key to exit..."
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
