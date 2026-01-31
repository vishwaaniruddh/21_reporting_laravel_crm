# ============================================
# Check Alert Update Sync Service Status
# ============================================

Write-Host "=== Alert Update Sync Service Status ===" -ForegroundColor Cyan
Write-Host ""

# Check if service exists
$service = Get-Service -Name "AlertUpdateSync" -ErrorAction SilentlyContinue

if ($service) {
    Write-Host "Service Status:" -ForegroundColor Yellow
    Write-Host "  Name: $($service.Name)" -ForegroundColor White
    Write-Host "  Display Name: $($service.DisplayName)" -ForegroundColor White
    Write-Host "  Status: $($service.Status)" -ForegroundColor $(if ($service.Status -eq 'Running') { 'Green' } else { 'Red' })
    Write-Host "  Start Type: $($service.StartType)" -ForegroundColor White
    Write-Host ""
    
    if ($service.Status -ne 'Running') {
        Write-Host "WARNING: Service is not running!" -ForegroundColor Red
        Write-Host ""
        Write-Host "To start the service:" -ForegroundColor Yellow
        Write-Host "  Start-Service AlertUpdateSync" -ForegroundColor Gray
        Write-Host ""
        Write-Host "Or use NSSM:" -ForegroundColor Yellow
        Write-Host "  nssm start AlertUpdateSync" -ForegroundColor Gray
    } else {
        Write-Host "OK Service is running!" -ForegroundColor Green
    }
} else {
    Write-Host "ERROR: AlertUpdateSync service not found!" -ForegroundColor Red
    Write-Host ""
    Write-Host "The service needs to be created. Run:" -ForegroundColor Yellow
    Write-Host "  .\codes\setup-services.ps1" -ForegroundColor Gray
    Write-Host ""
    Write-Host "Or create it manually with NSSM:" -ForegroundColor Yellow
    Write-Host "  nssm install AlertUpdateSync" -ForegroundColor Gray
}

Write-Host ""
Write-Host "=== Update Log Status ===" -ForegroundColor Cyan
Write-Host ""

# Check update log status
php artisan tinker --execute="
    `$pending = DB::connection('mysql')->table('alert_pg_update_log')->where('status', 1)->count();
    `$completed = DB::connection('mysql')->table('alert_pg_update_log')->where('status', 2)->count();
    `$failed = DB::connection('mysql')->table('alert_pg_update_log')->where('status', 3)->count();
    echo 'Pending: ' . number_format(`$pending) . PHP_EOL;
    echo 'Completed: ' . number_format(`$completed) . PHP_EOL;
    echo 'Failed: ' . number_format(`$failed) . PHP_EOL;
"

Write-Host ""
Write-Host "=== Service Logs ===" -ForegroundColor Cyan
Write-Host ""

$logPath = "storage\logs\update-sync-service.log"
if (Test-Path $logPath) {
    Write-Host "Last 10 lines of service log:" -ForegroundColor Yellow
    Get-Content $logPath -Tail 10
} else {
    Write-Host "No service log found at: $logPath" -ForegroundColor Yellow
}

Write-Host ""
Read-Host "Press Enter to exit"

