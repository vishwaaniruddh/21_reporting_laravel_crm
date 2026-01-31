# Test the logs endpoint directly
Write-Host "Testing logs endpoint..." -ForegroundColor Cyan

# Test reading a log file directly
$logPath = "C:\wamp64\www\comfort_reporting_crm\dual-database-app\storage\logs\update-sync-service.log"

Write-Host "Log file exists: $(Test-Path $logPath)" -ForegroundColor Yellow

if (Test-Path $logPath) {
    Write-Host "Reading last 10 lines..." -ForegroundColor Yellow
    Get-Content $logPath -Tail 10
}

Write-Host "`nDone!" -ForegroundColor Green
