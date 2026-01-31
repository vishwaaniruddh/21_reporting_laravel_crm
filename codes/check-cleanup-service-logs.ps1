# ============================================
# Check Cleanup Service Logs and Status
# ============================================

Write-Host "=== Cleanup Service Diagnostics ===" -ForegroundColor Cyan
Write-Host ""

# Check if service exists
$service = Get-Service -Name "AlertCleanup" -ErrorAction SilentlyContinue

if ($service) {
    Write-Host "Service Status:" -ForegroundColor Yellow
    Write-Host "  Name: $($service.Name)" -ForegroundColor White
    Write-Host "  Status: $($service.Status)" -ForegroundColor $(if ($service.Status -eq 'Running') { 'Green' } else { 'Red' })
    Write-Host ""
} else {
    Write-Host "Service not found!" -ForegroundColor Red
    Write-Host ""
}

# Check MySQL status
Write-Host "MySQL Status:" -ForegroundColor Yellow
$mysqlService = Get-Service -Name "wampmysqld*" -ErrorAction SilentlyContinue
if ($mysqlService) {
    Write-Host "  Status: $($mysqlService.Status)" -ForegroundColor $(if ($mysqlService.Status -eq 'Running') { 'Green' } else { 'Red' })
} else {
    Write-Host "  MySQL service not found" -ForegroundColor Yellow
}
Write-Host ""

# Check error log
Write-Host "=== Service Error Log ===" -ForegroundColor Cyan
Write-Host ""

if (Test-Path "storage\logs\cleanup-service-error.log") {
    $errorLog = Get-Content "storage\logs\cleanup-service-error.log" -Tail 50
    if ($errorLog) {
        Write-Host "Last 50 lines of error log:" -ForegroundColor Red
        $errorLog | ForEach-Object { Write-Host $_ -ForegroundColor Gray }
    } else {
        Write-Host "Error log is empty (no errors)" -ForegroundColor Green
    }
} else {
    Write-Host "No error log found" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "=== Service Output Log ===" -ForegroundColor Cyan
Write-Host ""

if (Test-Path "storage\logs\cleanup-service.log") {
    Write-Host "Last 30 lines of output log:" -ForegroundColor Yellow
    Get-Content "storage\logs\cleanup-service.log" -Tail 30 | ForEach-Object { Write-Host $_ -ForegroundColor Gray }
} else {
    Write-Host "No output log found" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "=== Laravel Log ===" -ForegroundColor Cyan
Write-Host ""

if (Test-Path "storage\logs\laravel.log") {
    Write-Host "Last 20 lines (looking for cleanup errors):" -ForegroundColor Yellow
    Get-Content "storage\logs\laravel.log" -Tail 100 | Select-String -Pattern "cleanup|Cleanup|CLEANUP" -Context 2 | Select-Object -Last 20 | ForEach-Object { Write-Host $_ -ForegroundColor Gray }
} else {
    Write-Host "No Laravel log found" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "=== MySQL Error Log ===" -ForegroundColor Cyan
Write-Host ""

$mysqlErrorLog = "C:\wamp64\logs\mysql.log"
if (Test-Path $mysqlErrorLog) {
    Write-Host "Last 20 lines of MySQL error log:" -ForegroundColor Yellow
    Get-Content $mysqlErrorLog -Tail 20 | ForEach-Object { Write-Host $_ -ForegroundColor Gray }
} else {
    Write-Host "MySQL error log not found at: $mysqlErrorLog" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "=== Recommendations ===" -ForegroundColor Cyan
Write-Host ""

if ($mysqlService -and $mysqlService.Status -ne 'Running') {
    Write-Host "⚠️  MySQL is not running!" -ForegroundColor Red
    Write-Host "   The cleanup service may have caused MySQL to crash." -ForegroundColor Yellow
    Write-Host ""
    Write-Host "   Possible causes:" -ForegroundColor Yellow
    Write-Host "   1. Deleting too many records at once (table locks)" -ForegroundColor Gray
    Write-Host "   2. MySQL running out of memory" -ForegroundColor Gray
    Write-Host "   3. InnoDB buffer pool issues" -ForegroundColor Gray
    Write-Host ""
    Write-Host "   Solutions:" -ForegroundColor Yellow
    Write-Host "   1. Stop cleanup service: Stop-Service AlertCleanup" -ForegroundColor Gray
    Write-Host "   2. Reduce batch size in CleanupOldAlertsWorker.php" -ForegroundColor Gray
    Write-Host "   3. Increase check interval (run less frequently)" -ForegroundColor Gray
    Write-Host "   4. Start MySQL: net start wampmysqld64" -ForegroundColor Gray
}

Write-Host ""
Read-Host "Press Enter to exit"

