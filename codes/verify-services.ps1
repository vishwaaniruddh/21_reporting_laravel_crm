# Service Verification Script
# Quick check of all Alert services

Write-Host "=== Alert Services Status ===" -ForegroundColor Cyan
Write-Host ""

# Check services
Write-Host "Service Status:" -ForegroundColor Yellow
$services = Get-Service | Where-Object {$_.Name -like "Alert*"}
$services | Format-Table -AutoSize

Write-Host ""
$allRunning = $true
foreach ($service in $services) {
    if ($service.Status -ne "Running") {
        Write-Host "WARNING: $($service.DisplayName) is $($service.Status)" -ForegroundColor Red
        $allRunning = $false
    }
}

if ($allRunning) {
    Write-Host "âœ" All services are running!" -ForegroundColor Green
} else {
    Write-Host "âœ— Some services are not running" -ForegroundColor Red
}

Write-Host ""
Write-Host "Portal Connectivity:" -ForegroundColor Yellow
$connection = Test-NetConnection -ComputerName 192.168.100.21 -Port 9000 -WarningAction SilentlyContinue
if ($connection.TcpTestSucceeded) {
    Write-Host "âœ" Portal is accessible at http://192.168.100.21:9000" -ForegroundColor Green
} else {
    Write-Host "âœ— Portal is not accessible" -ForegroundColor Red
}

Write-Host ""
Write-Host "Vite Dev Server:" -ForegroundColor Yellow
$viteConnection = Test-NetConnection -ComputerName 127.0.0.1 -Port 5173 -WarningAction SilentlyContinue
if ($viteConnection.TcpTestSucceeded) {
    Write-Host "âœ" Vite dev server is running on port 5173" -ForegroundColor Green
} else {
    Write-Host "âœ— Vite dev server is not running" -ForegroundColor Red
}

Write-Host ""
Write-Host "Recent Activity:" -ForegroundColor Yellow

Write-Host "  Portal (last 3 lines):" -ForegroundColor Cyan
Get-Content "storage\logs\portal-service.log" -Tail 3 -ErrorAction SilentlyContinue | ForEach-Object { Write-Host "    $_" }

Write-Host "  Vite Dev (last 3 lines):" -ForegroundColor Cyan
Get-Content "storage\logs\vite-dev-service.log" -Tail 3 -ErrorAction SilentlyContinue | ForEach-Object { Write-Host "    $_" }

Write-Host "  Initial Sync (last 3 lines):" -ForegroundColor Cyan
Get-Content "storage\logs\initial-sync-service.log" -Tail 3 -ErrorAction SilentlyContinue | ForEach-Object { Write-Host "    $_" }

Write-Host "  Update Sync (last 3 lines):" -ForegroundColor Cyan
Get-Content "storage\logs\update-sync-service.log" -Tail 3 -ErrorAction SilentlyContinue | ForEach-Object { Write-Host "    $_" }

Write-Host ""
Write-Host "=== Verification Complete ===" -ForegroundColor Cyan
