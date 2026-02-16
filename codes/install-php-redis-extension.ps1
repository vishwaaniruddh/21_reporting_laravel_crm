# Install PHP Redis Extension
# This script downloads and configures the Redis extension for PHP

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "PHP Redis Extension Installation" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Detect PHP version and architecture
$phpPath = "C:\wamp64\bin\php\php8.4.11"
$phpIni = "$phpPath\php.ini"
$extPath = "$phpPath\ext"

Write-Host "Step 1: Detecting PHP Configuration..." -ForegroundColor Yellow
Write-Host "  PHP Path: $phpPath" -ForegroundColor Gray
Write-Host "  PHP INI: $phpIni" -ForegroundColor Gray
Write-Host "  Extensions: $extPath" -ForegroundColor Gray

if (-not (Test-Path $phpPath)) {
    Write-Host "❌ PHP not found at $phpPath" -ForegroundColor Red
    Write-Host "Please update the script with your PHP path" -ForegroundColor Yellow
    exit 1
}

Write-Host "✅ PHP found" -ForegroundColor Green
Write-Host ""

Write-Host "Step 2: Downloading PHP Redis Extension..." -ForegroundColor Yellow
Write-Host ""
Write-Host "⚠️  MANUAL DOWNLOAD REQUIRED" -ForegroundColor Yellow
Write-Host ""
Write-Host "Please follow these steps:" -ForegroundColor Cyan
Write-Host ""
Write-Host "1. Go to: https://pecl.php.net/package/redis" -ForegroundColor White
Write-Host "2. Click on 'DLL' link for the latest version" -ForegroundColor White
Write-Host "3. Download the file matching your PHP:" -ForegroundColor White
Write-Host "   - PHP 8.4" -ForegroundColor Yellow
Write-Host "   - Thread Safe (TS)" -ForegroundColor Yellow
Write-Host "   - x64" -ForegroundColor Yellow
Write-Host "   Example: php_redis-6.0.2-8.4-ts-vs16-x64.zip" -ForegroundColor Gray
Write-Host ""
Write-Host "4. Extract the ZIP file" -ForegroundColor White
Write-Host "5. Copy 'php_redis.dll' to: $extPath" -ForegroundColor White
Write-Host ""

Write-Host "Press Enter when you've copied php_redis.dll to the ext folder..." -ForegroundColor Yellow
Read-Host

Write-Host ""
Write-Host "Step 3: Checking if php_redis.dll exists..." -ForegroundColor Yellow

if (Test-Path "$extPath\php_redis.dll") {
    Write-Host "✅ php_redis.dll found!" -ForegroundColor Green
} else {
    Write-Host "❌ php_redis.dll not found in $extPath" -ForegroundColor Red
    Write-Host "Please copy the file and run this script again" -ForegroundColor Yellow
    exit 1
}

Write-Host ""
Write-Host "Step 4: Updating php.ini..." -ForegroundColor Yellow

# Check if extension is already enabled
$iniContent = Get-Content $phpIni -Raw
if ($iniContent -match "extension=redis") {
    Write-Host "  Redis extension already enabled in php.ini" -ForegroundColor Yellow
} else {
    # Add extension to php.ini
    Add-Content -Path $phpIni -Value "`nextension=redis"
    Write-Host "✅ Added 'extension=redis' to php.ini" -ForegroundColor Green
}

Write-Host ""
Write-Host "Step 5: Restarting Apache..." -ForegroundColor Yellow

try {
    Restart-Service wampapache64 -ErrorAction Stop
    Write-Host "✅ Apache restarted" -ForegroundColor Green
} catch {
    Write-Host "⚠️  Could not restart Apache automatically" -ForegroundColor Yellow
    Write-Host "Please restart Apache manually from WAMP" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Step 6: Verifying Installation..." -ForegroundColor Yellow

Start-Sleep -Seconds 2

$redisCheck = php -m | Select-String "redis"
if ($redisCheck) {
    Write-Host "✅ Redis extension is loaded!" -ForegroundColor Green
    Write-Host "  $redisCheck" -ForegroundColor White
} else {
    Write-Host "❌ Redis extension not loaded" -ForegroundColor Red
    Write-Host ""
    Write-Host "Troubleshooting:" -ForegroundColor Yellow
    Write-Host "  1. Check if php_redis.dll is in: $extPath" -ForegroundColor White
    Write-Host "  2. Check if 'extension=redis' is in: $phpIni" -ForegroundColor White
    Write-Host "  3. Restart Apache from WAMP control panel" -ForegroundColor White
    Write-Host "  4. Run: php -m | findstr redis" -ForegroundColor White
    exit 1
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "PHP Redis Extension Installed!" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Next Steps:" -ForegroundColor Cyan
Write-Host "  1. Update .env file with Redis configuration" -ForegroundColor White
Write-Host "  2. Test Redis connection: php artisan tinker" -ForegroundColor White
Write-Host "     >>> Redis::ping();" -ForegroundColor Gray
Write-Host "  3. Create V2 queue worker service" -ForegroundColor White
Write-Host ""

Write-Host "Run: .\codes\setup-redis-config.ps1" -ForegroundColor Yellow
Write-Host ""
