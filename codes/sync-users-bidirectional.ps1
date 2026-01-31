# Bidirectional User Sync
# Intelligently syncs users between both servers using most recent data

Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "Bidirectional User Sync" -ForegroundColor Cyan
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""

# First, run dry-run to preview changes
Write-Host "Step 1: Analyzing differences..." -ForegroundColor Yellow
Write-Host ""
php codes/sync_users_bidirectional.php

Write-Host ""
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""

# Ask for confirmation
$confirmation = Read-Host "Do you want to execute the sync? (yes/no)"

if ($confirmation -eq "yes") {
    Write-Host ""
    Write-Host "Step 2: Executing bidirectional sync..." -ForegroundColor Green
    Write-Host ""
    php codes/sync_users_bidirectional.php --execute
    
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
