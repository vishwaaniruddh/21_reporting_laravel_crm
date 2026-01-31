# Cleanup Laravel Log File
# This script backs up and clears the large laravel.log file

Write-Host "============================================" -ForegroundColor Cyan
Write-Host "Laravel Log Cleanup" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

$logPath = "storage/logs/laravel.log"
$backupPath = "storage/logs/laravel.log.backup." + (Get-Date -Format "yyyy-MM-dd_HH-mm-ss")

# Check if log file exists
if (Test-Path $logPath) {
    $logSize = (Get-Item $logPath).Length / 1GB
    Write-Host "Current log file size: $([math]::Round($logSize, 2)) GB" -ForegroundColor Yellow
    Write-Host ""
    
    # Ask for confirmation
    Write-Host "This will:" -ForegroundColor Yellow
    Write-Host "  1. Create a backup of the current log file" -ForegroundColor White
    Write-Host "  2. Clear the log file to start fresh" -ForegroundColor White
    Write-Host ""
    
    $confirm = Read-Host "Do you want to proceed? (yes/no)"
    
    if ($confirm -eq "yes") {
        Write-Host ""
        Write-Host "Creating backup..." -ForegroundColor Yellow
        
        # Create backup
        Copy-Item $logPath $backupPath
        
        if (Test-Path $backupPath) {
            Write-Host "✓ Backup created: $backupPath" -ForegroundColor Green
            
            # Clear the log file
            Write-Host "Clearing log file..." -ForegroundColor Yellow
            Clear-Content $logPath
            
            Write-Host "✓ Log file cleared" -ForegroundColor Green
            Write-Host ""
            Write-Host "============================================" -ForegroundColor Green
            Write-Host "Success!" -ForegroundColor Green
            Write-Host "============================================" -ForegroundColor Green
            Write-Host ""
            Write-Host "The log file has been cleared." -ForegroundColor Green
            Write-Host "Backup saved to: $backupPath" -ForegroundColor Cyan
            Write-Host ""
            Write-Host "Note: Logging level has been changed to 'error'" -ForegroundColor Yellow
            Write-Host "Only errors and critical failures will be logged from now on." -ForegroundColor Yellow
            Write-Host "Warnings and info messages will NOT be logged." -ForegroundColor Yellow
        } else {
            Write-Host "✗ Failed to create backup" -ForegroundColor Red
        }
    } else {
        Write-Host ""
        Write-Host "Operation cancelled." -ForegroundColor Yellow
    }
} else {
    Write-Host "Log file not found: $logPath" -ForegroundColor Red
}

Write-Host ""
Write-Host "Press any key to exit..."
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
