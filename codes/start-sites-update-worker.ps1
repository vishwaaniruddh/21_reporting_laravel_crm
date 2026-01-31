# =====================================================
# Start Sites Update Sync Worker
# =====================================================

Write-Host "=== Starting Sites Update Sync Worker ===" -ForegroundColor Cyan
Write-Host ""

# Check if worker is already running
$existing = Get-Process -Name php -ErrorAction SilentlyContinue | Where-Object {
    $_.CommandLine -like "*sites:update-worker*"
}

if ($existing) {
    Write-Host "⚠ Worker is already running (PID: $($existing.Id))" -ForegroundColor Yellow
    Write-Host ""
    $response = Read-Host "Stop existing worker and start new one? (y/n)"
    if ($response -eq 'y') {
        Stop-Process -Id $existing.Id -Force
        Write-Host "✓ Stopped existing worker" -ForegroundColor Green
        Start-Sleep -Seconds 2
    } else {
        Write-Host "Aborted." -ForegroundColor Yellow
        exit 0
    }
}

Write-Host "Starting worker..." -ForegroundColor Yellow
Write-Host ""
Write-Host "Configuration:" -ForegroundColor Cyan
Write-Host "  Poll Interval: 5 seconds"
Write-Host "  Batch Size: 100"
Write-Host "  Max Retries: 3"
Write-Host ""
Write-Host "Press Ctrl+C to stop the worker gracefully" -ForegroundColor Yellow
Write-Host ""

# Start the worker
php artisan sites:update-worker --poll-interval=5 --batch-size=100 --max-retries=3
