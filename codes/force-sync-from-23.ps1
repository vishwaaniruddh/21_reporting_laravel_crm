# Force Sync from Server .23 to .21
# Overwrites all users on .21 with data from .23

Write-Host "=========================================" -ForegroundColor Red
Write-Host "FORCE SYNC: .23 -> .21" -ForegroundColor Red
Write-Host "=========================================" -ForegroundColor Red
Write-Host ""
Write-Host "WARNING: This will overwrite ALL users on .21" -ForegroundColor Yellow
Write-Host "with data from .23, regardless of timestamps." -ForegroundColor Yellow
Write-Host ""

# Preview
Write-Host "Preview of changes:" -ForegroundColor Yellow
Write-Host ""
php codes/sync_users_bidirectional.php --force-from=23

Write-Host ""
Write-Host "=========================================" -ForegroundColor Red
Write-Host ""

# Double confirmation
$confirmation1 = Read-Host "Are you sure you want to FORCE sync from .23 to .21? (yes/no)"

if ($confirmation1 -eq "yes") {
    $confirmation2 = Read-Host "Type 'FORCE SYNC' to confirm"
    
    if ($confirmation2 -eq "FORCE SYNC") {
        Write-Host ""
        Write-Host "Executing force sync..." -ForegroundColor Red
        Write-Host ""
        php codes/sync_users_bidirectional.php --force-from=23 --execute
        
        Write-Host ""
        Write-Host "=========================================" -ForegroundColor Green
        Write-Host "Force sync completed!" -ForegroundColor Green
        Write-Host "=========================================" -ForegroundColor Green
    } else {
        Write-Host ""
        Write-Host "Sync cancelled (confirmation failed)." -ForegroundColor Yellow
    }
} else {
    Write-Host ""
    Write-Host "Sync cancelled." -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Press any key to exit..."
$null = $Host.UI.RawUI.ReadKey('NoEcho,IncludeKeyDown')
