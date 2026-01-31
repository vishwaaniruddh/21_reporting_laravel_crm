# Laravel Task Scheduler Runner
# This script runs the Laravel scheduler every minute

Write-Host "Starting Laravel Task Scheduler..." -ForegroundColor Green
Write-Host "Scheduled tasks will run every minute." -ForegroundColor Yellow
Write-Host "Press Ctrl+C to stop." -ForegroundColor Yellow
Write-Host ""

while ($true) {
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    Write-Host "[$timestamp] Running scheduled tasks..." -ForegroundColor Cyan
    
    php artisan schedule:run
    
    Write-Host "[$timestamp] Waiting 60 seconds..." -ForegroundColor Gray
    Start-Sleep -Seconds 60
}
