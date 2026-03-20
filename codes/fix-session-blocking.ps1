# Fix Session Blocking Issue
# Changes session driver from database to file to prevent blocking during downloads

Write-Host "Fix Session Blocking Issue" -ForegroundColor Cyan
Write-Host ""

# Backup .env file
Write-Host "Creating backup of .env file..." -ForegroundColor Yellow
Copy-Item ".env" ".env.backup.$(Get-Date -Format 'yyyyMMdd_HHmmss')"
Write-Host "Backup created" -ForegroundColor Green
Write-Host ""

# Update SESSION_DRIVER in .env
Write-Host "Changing SESSION_DRIVER from database to file..." -ForegroundColor Yellow
$envContent = Get-Content ".env" -Raw
$envContent = $envContent -replace 'SESSION_DRIVER=database', 'SESSION_DRIVER=file'
Set-Content ".env" $envContent -NoNewline
Write-Host "SESSION_DRIVER updated to file" -ForegroundColor Green
Write-Host ""

# Clear config cache
Write-Host "Clearing configuration cache..." -ForegroundColor Yellow
php artisan config:clear
Write-Host "Config cache cleared" -ForegroundColor Green
Write-Host ""

# Create sessions directory if it doesn't exist
Write-Host "Ensuring sessions directory exists..." -ForegroundColor Yellow
$sessionsDir = "storage\framework\sessions"
if (!(Test-Path $sessionsDir)) {
    New-Item -ItemType Directory -Path $sessionsDir -Force | Out-Null
    Write-Host "Created $sessionsDir" -ForegroundColor Green
} else {
    Write-Host "Directory already exists" -ForegroundColor Green
}
Write-Host ""

# Set proper permissions (Windows)
Write-Host "Setting directory permissions..." -ForegroundColor Yellow
icacls $sessionsDir /grant "Everyone:(OI)(CI)F" /T | Out-Null
Write-Host "Permissions set" -ForegroundColor Green
Write-Host ""

Write-Host "============================================================" -ForegroundColor Cyan
Write-Host "SESSION BLOCKING FIX COMPLETE!" -ForegroundColor Green
Write-Host "============================================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "What Changed:" -ForegroundColor Yellow
Write-Host "  - Session driver: database to file" -ForegroundColor White
Write-Host "  - Session storage: database table to storage/framework/sessions" -ForegroundColor White
Write-Host ""
Write-Host "Expected Behavior:" -ForegroundColor Yellow
Write-Host "  - Downloads will NO LONGER block other API calls" -ForegroundColor White
Write-Host "  - Portal will remain responsive during downloads" -ForegroundColor White
Write-Host "  - Multiple users can download simultaneously" -ForegroundColor White
Write-Host ""
Write-Host "How to Test:" -ForegroundColor Yellow
Write-Host "  1. Start a large CSV download" -ForegroundColor White
Write-Host "  2. Navigate to another page" -ForegroundColor White
Write-Host "  3. Page should load immediately" -ForegroundColor White
Write-Host ""
Write-Host "IMPORTANT:" -ForegroundColor Red
Write-Host "  - All users will be logged out" -ForegroundColor White
Write-Host "  - Users need to log in again" -ForegroundColor White
Write-Host ""
Write-Host "To Revert:" -ForegroundColor Yellow
Write-Host "  1. Change SESSION_DRIVER=file back to SESSION_DRIVER=database in .env" -ForegroundColor White
Write-Host "  2. Run: php artisan config:clear" -ForegroundColor White
Write-Host ""
Write-Host "Press any key to continue..." -ForegroundColor Cyan
$null = $Host.UI.RawUI.ReadKey('NoEcho,IncludeKeyDown')
