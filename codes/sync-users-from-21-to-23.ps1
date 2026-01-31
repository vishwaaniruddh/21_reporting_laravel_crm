# Sync Users from Server .21 to Server .23
# This script syncs all users from 192.168.100.21 to 192.168.100.23

Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "User Sync: .21 -> .23" -ForegroundColor Cyan
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""

# First, run dry-run to preview changes
Write-Host "Step 1: Preview changes (dry run)..." -ForegroundColor Yellow
Write-Host ""
php codes/sync_users_between_servers.php

Write-Host ""
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""

# Ask for confirmation
$confirmation = Read-Host "Do you want to execute the sync? (yes/no)"

if ($confirmation -eq "yes") {
    Write-Host ""
    Write-Host "Step 2: Executing sync..." -ForegroundColor Green
    Write-Host ""
    php codes/sync_users_between_servers.php --execute
    
    Write-Host ""
    Write-Host "=========================================" -ForegroundColor Green
    Write-Host "Sync completed!" -ForegroundColor Green
    Write-Host "=========================================" -ForegroundColor Green
} else {
    Write-Host ""
    Write-Host "Sync cancelled." -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Press any key to exit..."
$null = $Host.UI.RawUI.ReadKey('NoEcho,IncludeKeyDown')
