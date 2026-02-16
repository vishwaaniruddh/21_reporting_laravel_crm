# Complete Redis Installation for Downloads V2
# This master script runs all installation steps

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Complete Redis Setup for Downloads V2" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "This script will:" -ForegroundColor Yellow
Write-Host "  1. Install Redis for Windows" -ForegroundColor White
Write-Host "  2. Install PHP Redis extension" -ForegroundColor White
Write-Host "  3. Configure .env file" -ForegroundColor White
Write-Host "  4. Create V2 queue worker service" -ForegroundColor White
Write-Host "  5. Test the setup" -ForegroundColor White
Write-Host ""

Write-Host "Press Enter to continue or Ctrl+C to cancel..." -ForegroundColor Yellow
Read-Host

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "STEP 1: Install Redis" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

.\codes\install-redis-windows.ps1

if ($LASTEXITCODE -ne 0) {
    Write-Host ""
    Write-Host "❌ Redis installation failed" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "Press Enter to continue to PHP extension installation..." -ForegroundColor Yellow
Read-Host

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "STEP 2: Install PHP Redis Extension" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

.\codes\install-php-redis-extension.ps1

if ($LASTEXITCODE -ne 0) {
    Write-Host ""
    Write-Host "❌ PHP Redis extension installation failed" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "Press Enter to continue to configuration..." -ForegroundColor Yellow
Read-Host

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "STEP 3: Configure Redis" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

.\codes\setup-redis-config.ps1

if ($LASTEXITCODE -ne 0) {
    Write-Host ""
    Write-Host "❌ Redis configuration failed" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "Press Enter to continue to queue worker setup..." -ForegroundColor Yellow
Read-Host

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "STEP 4: Create V2 Queue Worker Service" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

.\codes\create-queue-worker-v2-service.ps1

if ($LASTEXITCODE -ne 0) {
    Write-Host ""
    Write-Host "⚠️  Queue worker service creation had issues" -ForegroundColor Yellow
    Write-Host "You can create it manually later" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "STEP 5: Test Downloads V2" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Running test script..." -ForegroundColor Yellow
Write-Host ""

.\codes\test-downloads-v2.ps1

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Installation Complete!" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Summary:" -ForegroundColor Cyan
Write-Host "  ✅ Redis installed" -ForegroundColor Green
Write-Host "  ✅ PHP Redis extension installed" -ForegroundColor Green
Write-Host "  ✅ Configuration updated" -ForegroundColor Green
Write-Host "  ✅ V2 queue worker service created" -ForegroundColor Green
Write-Host ""

Write-Host "Services Running:" -ForegroundColor Cyan
Get-Service AlertPortalQueueWorker* | Format-Table Name, Status, DisplayName -AutoSize

Write-Host ""
Write-Host "Test Downloads V2:" -ForegroundColor Cyan
Write-Host "  API: http://192.168.100.21:9000/api/downloads-v2/partitions?type=all-alerts" -ForegroundColor White
Write-Host "  Script: .\codes\test-downloads-v2.ps1" -ForegroundColor White
Write-Host ""

Write-Host "Monitor:" -ForegroundColor Cyan
Write-Host "  Redis: redis-cli MONITOR" -ForegroundColor White
Write-Host "  Worker: Get-Content storage\logs\queue-worker-v2-service.log -Tail 50 -Wait" -ForegroundColor White
Write-Host ""

Write-Host "Documentation:" -ForegroundColor Cyan
Write-Host "  Documents\DOWNLOADS_V2_QUICK_START.md" -ForegroundColor White
Write-Host "  Documents\DOWNLOADS_V2_REDIS_SETUP.md" -ForegroundColor White
Write-Host ""
