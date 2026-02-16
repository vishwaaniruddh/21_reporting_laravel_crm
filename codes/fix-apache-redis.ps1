# Fix Apache PHP Redis Extension
# This script fixes the extension_dir path and enables Redis

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Fix Apache PHP Redis Extension" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$apachePhpIni = "C:\wamp64\bin\apache\apache2.4.27\bin\php.ini"

Write-Host "Step 1: Backup php.ini..." -ForegroundColor Yellow
Copy-Item $apachePhpIni "$apachePhpIni.backup" -Force
Write-Host "✅ Backup created: $apachePhpIni.backup" -ForegroundColor Green

Write-Host ""
Write-Host "Step 2: Fix extension_dir path..." -ForegroundColor Yellow

# Read the file
$content = Get-Content $apachePhpIni -Raw

# Replace old PHP 5.6 path with PHP 8.4
$content = $content -replace 'extension_dir ="c:/wamp64/bin/php/php5\.6\.31/ext/"', 'extension_dir ="c:/wamp64/bin/php/php8.4.11/ext/"'

# Write back
Set-Content -Path $apachePhpIni -Value $content -NoNewline

Write-Host "✅ Updated extension_dir to PHP 8.4.11" -ForegroundColor Green

Write-Host ""
Write-Host "Step 3: Verify changes..." -ForegroundColor Yellow
Get-Content $apachePhpIni | Select-String "extension_dir|extension=redis" | ForEach-Object {
    Write-Host "  $_" -ForegroundColor White
}

Write-Host ""
Write-Host "Step 4: Restart Apache..." -ForegroundColor Yellow
Restart-Service wampapache64
Start-Sleep -Seconds 5
Write-Host "✅ Apache restarted" -ForegroundColor Green

Write-Host ""
Write-Host "Step 5: Test Redis in web..." -ForegroundColor Yellow
Write-Host "Opening test page in browser..." -ForegroundColor Gray

Start-Process "http://192.168.100.21:9000/test-redis.php"

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Fix Complete!" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Check the browser - you should see:" -ForegroundColor Yellow
Write-Host "  ✅ Redis class is available" -ForegroundColor Green
Write-Host "  ✅ Redis connection successful" -ForegroundColor Green
Write-Host "  ✅ Redis set/get works" -ForegroundColor Green
Write-Host ""

Write-Host "If it works, test Downloads V2:" -ForegroundColor Cyan
Write-Host "  .\codes\test-downloads-v2.ps1" -ForegroundColor White
Write-Host ""
