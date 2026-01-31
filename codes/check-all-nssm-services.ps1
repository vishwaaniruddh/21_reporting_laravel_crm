# ============================================
# Check All NSSM Services
# ============================================

Write-Host "=== All NSSM Services ===" -ForegroundColor Cyan
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
} else {
    Write-Host "Found $($allServices.Count) service(s):" -ForegroundColor Green
    Write-Host ""
    
    foreach ($service in $allServices) {
        Write-Host "Service: $($service.Name)" -ForegroundColor Yellow
        Write-Host "  Display Name: $($service.DisplayName)" -ForegroundColor White
        Write-Host "  Status: $($service.Status)" -ForegroundColor $(if ($service.Status -eq 'Running') { 'Green' } else { 'Red' })
        Write-Host "  Start Type: $($service.StartType)" -ForegroundColor White
        
        # Try to get NSSM configuration
        try {
            $appPath = nssm get $service.Name Application 2>$null
            $appParams = nssm get $service.Name AppParameters 2>$null
            $appDir = nssm get $service.Name AppDirectory 2>$null
            
            if ($appPath) {
                Write-Host "  Command: $appPath $appParams" -ForegroundColor Gray
                Write-Host "  Directory: $appDir" -ForegroundColor Gray
            }
        } catch {
            Write-Host "  (Could not get NSSM config)" -ForegroundColor Gray
        }
        
        Write-Host ""
    }
}

Write-Host ""
Write-Host "=== Service Logs ===" -ForegroundColor Cyan
Write-Host ""

# Check for service logs
$logFiles = @(
    "storage\logs\portal-service.log",
    "storage\logs\initial-sync-service.log",
    "storage\logs\update-sync-service.log",
    "storage\logs\portal-service-error.log",
    "storage\logs\initial-sync-service-error.log",
    "storage\logs\update-sync-service-error.log"
)

foreach ($logFile in $logFiles) {
    if (Test-Path $logFile) {
        $fileInfo = Get-Item $logFile
        $size = [math]::Round($fileInfo.Length / 1KB, 2)
        Write-Host "$logFile" -ForegroundColor Yellow
        Write-Host "  Size: $size KB" -ForegroundColor Gray
        Write-Host "  Last Modified: $($fileInfo.LastWriteTime)" -ForegroundColor Gray
        
        # Show last 5 lines
        Write-Host "  Last 5 lines:" -ForegroundColor Cyan
        $lastLines = Get-Content $logFile -Tail 5 -ErrorAction SilentlyContinue
        if ($lastLines) {
            foreach ($line in $lastLines) {
                Write-Host "    $line" -ForegroundColor Gray
            }
        } else {
            Write-Host "    (empty or no recent output)" -ForegroundColor Gray
        }
        Write-Host ""
    }
}

Write-Host ""
Write-Host "=== Alert Update Log Status ===" -ForegroundColor Cyan
Write-Host ""

# Check alert update log counts
php artisan tinker --execute="
    `$pending = DB::connection('mysql')->table('alert_pg_update_log')->where('status', 1)->count();
    `$completed = DB::connection('mysql')->table('alert_pg_update_log')->where('status', 2)->count();
    `$failed = DB::connection('mysql')->table('alert_pg_update_log')->where('status', 3)->count();
    echo 'Pending (status=1): ' . number_format(`$pending) . PHP_EOL;
    echo 'Completed (status=2): ' . number_format(`$completed) . PHP_EOL;
    echo 'Failed (status=3): ' . number_format(`$failed) . PHP_EOL;
"

Write-Host ""
Write-Host "=== Sites Update Log Status ===" -ForegroundColor Cyan
Write-Host ""

# Check sites update log counts
php artisan tinker --execute="
    try {
        `$pending = DB::connection('mysql')->table('sites_pg_update_log')->where('status', 1)->count();
        `$completed = DB::connection('mysql')->table('sites_pg_update_log')->where('status', 2)->count();
        `$failed = DB::connection('mysql')->table('sites_pg_update_log')->where('status', 3)->count();
        echo 'Pending (status=1): ' . number_format(`$pending) . PHP_EOL;
        echo 'Completed (status=2): ' . number_format(`$completed) . PHP_EOL;
        echo 'Failed (status=3): ' . number_format(`$failed) . PHP_EOL;
    } catch (Exception `$e) {
        echo 'Table not found (run setup first)' . PHP_EOL;
    }
"

Write-Host ""
Write-Host "=== Sync Status ===" -ForegroundColor Cyan
Write-Host ""

php artisan sync:partitioned --status

Write-Host ""
Write-Host "=== Commands to Manage Services ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "View specific service log:" -ForegroundColor Yellow
Write-Host "  Get-Content storage\logs\update-sync-service.log -Tail 50 -Wait" -ForegroundColor Gray
Write-Host ""
Write-Host "Restart a service:" -ForegroundColor Yellow
Write-Host "  Restart-Service AlertUpdateSync" -ForegroundColor Gray
Write-Host ""
Write-Host "Stop all services:" -ForegroundColor Yellow
Write-Host "  Get-Service Alert*,Sites* | Stop-Service" -ForegroundColor Gray
Write-Host ""
Write-Host "Start all services:" -ForegroundColor Yellow
Write-Host "  Get-Service Alert*,Sites* | Start-Service" -ForegroundColor Gray
Write-Host ""
Write-Host "Check Sites sync status:" -ForegroundColor Yellow
Write-Host "  php codes\check-sites-sync-status.php" -ForegroundColor Gray
Write-Host ""

Read-Host "Press Enter to exit"

