# ============================================
# Start Laravel Scheduler in Background
# ============================================
# This script starts the Laravel scheduler as a hidden background process
# that continues running even after you close PowerShell.

Write-Host "=== Laravel Scheduler Background Starter ===" -ForegroundColor Cyan
Write-Host ""

# Check if scheduler is already running
$existingProcess = Get-Process -Name "php" -ErrorAction SilentlyContinue | Where-Object {
    $_.CommandLine -like "*schedule:work*" -or $_.CommandLine -like "*start-scheduler.ps1*"
}

if ($existingProcess) {
    Write-Host "WARNING: Scheduler appears to be already running!" -ForegroundColor Yellow
    Write-Host "Process ID(s): $($existingProcess.Id -join ', ')" -ForegroundColor Yellow
    Write-Host ""
    $continue = Read-Host "Do you want to start another instance? (y/n)"
    if ($continue -ne 'y') {
        Write-Host "Exiting..." -ForegroundColor Gray
        exit 0
    }
}

Write-Host "Starting scheduler in background..." -ForegroundColor Yellow

# Get the current directory
$scriptPath = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectRoot = Split-Path -Parent $scriptPath

# Create a VBS script to run PowerShell hidden
$vbsScript = @"
Set WshShell = CreateObject("WScript.Shell")
WshShell.Run "powershell.exe -WindowStyle Hidden -ExecutionPolicy Bypass -File ""$scriptPath\start-scheduler.ps1""", 0, False
"@

$vbsPath = "$env:TEMP\start-scheduler-hidden.vbs"
$vbsScript | Out-File -FilePath $vbsPath -Encoding ASCII

# Run the VBS script to start PowerShell hidden
Start-Process -FilePath "wscript.exe" -ArgumentList $vbsPath -WindowStyle Hidden

# Wait a moment for the process to start
Start-Sleep -Seconds 2

# Verify it started
$newProcess = Get-Process -Name "php" -ErrorAction SilentlyContinue | Where-Object {
    $_.CommandLine -like "*schedule:run*"
} | Select-Object -First 1

if ($newProcess) {
    Write-Host "OK Scheduler started successfully in background!" -ForegroundColor Green
    Write-Host "Process ID: $($newProcess.Id)" -ForegroundColor Green
    Write-Host ""
    Write-Host "The scheduler is now running in the background." -ForegroundColor White
    Write-Host "It will continue running even if you close this window." -ForegroundColor White
    Write-Host ""
    Write-Host "To stop the scheduler, run:" -ForegroundColor Yellow
    Write-Host "  .\codes\stop-scheduler.ps1" -ForegroundColor Gray
} else {
    Write-Host "WARNING: Could not verify scheduler started." -ForegroundColor Yellow
    Write-Host "Check Task Manager for 'php.exe' processes." -ForegroundColor Yellow
}

Write-Host ""
Read-Host "Press Enter to exit"

