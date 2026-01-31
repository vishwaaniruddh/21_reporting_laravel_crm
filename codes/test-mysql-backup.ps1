# ============================================
# Test MySQL File Backup (Manual Run)
# ============================================
# This script runs the backup once to test it

Write-Host "=== Test MySQL File Backup ===" -ForegroundColor Cyan
Write-Host ""

Write-Host "This will backup MySQL files to:" -ForegroundColor Yellow
Write-Host "  D:\MysqlFileSystemBackup\$(Get-Date -Format 'yyyy\MM\dd')\" -ForegroundColor White
Write-Host ""

Write-Host "Files to backup:" -ForegroundColor Yellow
Write-Host "  - alerts.frm" -ForegroundColor White
Write-Host "  - alerts.ibd" -ForegroundColor White
Write-Host "  - alerts.TRG" -ForegroundColor White
Write-Host ""

$confirm = Read-Host "Run backup now? (y/n)"

if ($confirm -eq 'y') {
    Write-Host ""
    Write-Host "Running backup..." -ForegroundColor Yellow
    Write-Host ""
    
    php artisan backup:mysql-files-worker --run-once
    
    Write-Host ""
    Write-Host "Backup complete!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Check backup location:" -ForegroundColor Yellow
    Write-Host "  D:\MysqlFileSystemBackup\$(Get-Date -Format 'yyyy\MM\dd')\" -ForegroundColor Gray
} else {
    Write-Host "Cancelled." -ForegroundColor Yellow
}

Write-Host ""
Read-Host "Press Enter to exit"

