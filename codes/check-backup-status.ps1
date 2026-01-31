# Check MySQL Backup Status
# This script checks the backup folder and shows recent backups

Write-Host "============================================" -ForegroundColor Cyan
Write-Host "MySQL Backup Status" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

$backupRoot = "D:\MysqlFileSystemBackup"
$currentYear = (Get-Date).Year
$currentMonth = (Get-Date).ToString("MM")

# Check if backup root exists
if (!(Test-Path $backupRoot)) {
    Write-Host "Backup root not found: $backupRoot" -ForegroundColor Red
    exit 1
}

Write-Host "Backup Location: $backupRoot" -ForegroundColor Cyan
Write-Host ""

# Get current month's backups
$monthPath = Join-Path $backupRoot "$currentYear\$currentMonth"

if (Test-Path $monthPath) {
    Write-Host "Backups for $currentYear-$currentMonth:" -ForegroundColor Yellow
    Write-Host ""
    
    $backups = Get-ChildItem $monthPath -Directory | Sort-Object Name
    
    foreach ($backup in $backups) {
        $date = $backup.Name
        $fullPath = $backup.FullName
        
        # Get files in backup
        $files = Get-ChildItem $fullPath -File
        $totalSize = ($files | Measure-Object -Property Length -Sum).Sum
        $sizeMB = [math]::Round($totalSize / 1MB, 2)
        
        $dateObj = Get-Date "$currentYear-$currentMonth-$date"
        $dayOfWeek = $dateObj.ToString("dddd")
        
        Write-Host "  Date: $currentYear-$currentMonth-$date ($dayOfWeek)" -ForegroundColor White
        Write-Host "    Files: $($files.Count)" -ForegroundColor Gray
        Write-Host "    Size: $sizeMB MB" -ForegroundColor Gray
        Write-Host "    Path: $fullPath" -ForegroundColor Gray
        
        foreach ($file in $files) {
            $fileSizeMB = [math]::Round($file.Length / 1MB, 2)
            Write-Host "      - $($file.Name) ($fileSizeMB MB)" -ForegroundColor DarkGray
        }
        
        Write-Host ""
    }
    
    Write-Host "Total backups this month: $($backups.Count)" -ForegroundColor Green
} else {
    Write-Host "No backups found for $currentYear-$currentMonth" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "Service Status" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

$service = Get-Service -Name "AlertMysqlBackup" -ErrorAction SilentlyContinue

if ($null -ne $service) {
    Write-Host "Service: AlertMysqlBackup" -ForegroundColor White
    Write-Host "Status: $($service.Status)" -ForegroundColor $(if ($service.Status -eq 'Running') { 'Green' } else { 'Red' })
    Write-Host "Start Type: $($service.StartType)" -ForegroundColor Gray
} else {
    Write-Host "Service not found: AlertMysqlBackup" -ForegroundColor Red
}

Write-Host ""
Write-Host "Press any key to exit..."
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
