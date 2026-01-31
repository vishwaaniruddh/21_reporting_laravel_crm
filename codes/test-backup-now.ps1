# Test MySQL Backup Now
# This script runs a backup immediately to test if it's working

Write-Host "============================================" -ForegroundColor Cyan
Write-Host "Testing MySQL Backup" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Running backup now..." -ForegroundColor Yellow
Write-Host ""

# Run backup once
php artisan backup:mysql-files-worker --run-once

Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "Checking Backup Location" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

$today = Get-Date -Format "yyyy\\MM\\dd"
$backupPath = "D:\MysqlFileSystemBackup\$today"

if (Test-Path $backupPath) {
    Write-Host "✓ Backup folder exists: $backupPath" -ForegroundColor Green
    Write-Host ""
    Write-Host "Files backed up:" -ForegroundColor Yellow
    Get-ChildItem $backupPath | ForEach-Object {
        $size = [math]::Round($_.Length / 1MB, 2)
        Write-Host "  - $($_.Name) ($size MB)" -ForegroundColor White
    }
} else {
    Write-Host "✗ Backup folder not found: $backupPath" -ForegroundColor Red
}

Write-Host ""
Write-Host "Press any key to exit..."
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
