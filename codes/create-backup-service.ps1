# ============================================
# Create MySQL File Backup Service with NSSM
# Run as Administrator
# ============================================
# This service backs up MySQL physical data files daily

# Check if running as Administrator
$currentPrincipal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "ERROR: This script must be run as Administrator!" -ForegroundColor Red
    Write-Host "Right-click PowerShell and select 'Run as Administrator'" -ForegroundColor Yellow
    Read-Host "Press Enter to exit"
    exit 1
}

Write-Host "=== Create MySQL File Backup Service ===" -ForegroundColor Cyan
Write-Host ""

# Configuration
$PHP_PATH = "C:\wamp64\bin\php\php8.4.11\php.exe"
$PROJECT_PATH = (Get-Location).Path
$NSSM_PATH = "nssm"

# Verify paths
Write-Host "Checking configuration..." -ForegroundColor Yellow
Write-Host "  PHP: $PHP_PATH" -ForegroundColor Gray
Write-Host "  Project: $PROJECT_PATH" -ForegroundColor Gray
Write-Host ""

if (-not (Test-Path $PHP_PATH)) {
    Write-Host "ERROR: PHP not found at $PHP_PATH" -ForegroundColor Red
    Read-Host "Press Enter to exit"
    exit 1
}

# Show configuration
Write-Host "=== Backup Configuration ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Source:" -ForegroundColor Yellow
Write-Host "  C:\wamp64\bin\mysql\mysql5.7.19\data\esurv" -ForegroundColor White
Write-Host ""
Write-Host "Files to backup:" -ForegroundColor Yellow
Write-Host "  - alerts.frm" -ForegroundColor White
Write-Host "  - alerts.ibd" -ForegroundColor White
Write-Host "  - alerts.TRG" -ForegroundColor White
Write-Host ""
Write-Host "Destination:" -ForegroundColor Yellow
Write-Host "  D:\MysqlFileSystemBackup\YEAR\MONTH\DATE\" -ForegroundColor White
Write-Host ""
Write-Host "Schedule:" -ForegroundColor Yellow
Write-Host "  Daily at 02:00 AM (2 AM)" -ForegroundColor White
Write-Host ""
Write-Host "To change these settings, edit:" -ForegroundColor Yellow
Write-Host "  app\Console\Commands\MysqlFileBackupWorker.php" -ForegroundColor Gray
Write-Host "  Lines 30-55 (SOURCE, DESTINATION, FILES)" -ForegroundColor Gray
Write-Host ""

$confirm = Read-Host "Do you want to create this service? (y/n)"

if ($confirm -ne 'y') {
    Write-Host "Operation cancelled." -ForegroundColor Yellow
    Read-Host "Press Enter to exit"
    exit 0
}

Write-Host ""

# Step 1: Remove service if it exists
Write-Host "Step 1: Checking for existing service..." -ForegroundColor Yellow
try {
    $existingService = Get-Service -Name "AlertMysqlBackup" -ErrorAction SilentlyContinue
    if ($existingService) {
        Write-Host "  Removing existing service..." -ForegroundColor Gray
        & $NSSM_PATH stop AlertMysqlBackup 2>$null
        Start-Sleep -Seconds 2
        & $NSSM_PATH remove AlertMysqlBackup confirm 2>$null
        Start-Sleep -Seconds 2
        Write-Host "  OK Old service removed" -ForegroundColor Green
    } else {
        Write-Host "  No existing service found" -ForegroundColor Gray
    }
} catch {
    Write-Host "  (Continuing...)" -ForegroundColor Gray
}
Write-Host ""

# Step 2: Create new service
Write-Host "Step 2: Creating new service..." -ForegroundColor Yellow

# Install service with backup worker
& $NSSM_PATH install AlertMysqlBackup $PHP_PATH "artisan" "backup:mysql-files-worker" "--backup-time=02:00"

if ($LASTEXITCODE -ne 0) {
    Write-Host "  ERROR: Failed to install service" -ForegroundColor Red
    Read-Host "Press Enter to exit"
    exit 1
}

Write-Host "  OK Service installed" -ForegroundColor Green
Write-Host ""

# Step 3: Configure service
Write-Host "Step 3: Configuring service..." -ForegroundColor Yellow

& $NSSM_PATH set AlertMysqlBackup AppDirectory $PROJECT_PATH
& $NSSM_PATH set AlertMysqlBackup DisplayName "MySQL File Backup Worker"
& $NSSM_PATH set AlertMysqlBackup Description "Daily backup of MySQL physical data files to D:\MysqlFileSystemBackup"
& $NSSM_PATH set AlertMysqlBackup Start SERVICE_AUTO_START
& $NSSM_PATH set AlertMysqlBackup AppExit Default Restart
& $NSSM_PATH set AlertMysqlBackup AppRestartDelay 5000

# Set log files
$LogDir = Join-Path $PROJECT_PATH "storage\logs"
if (-not (Test-Path $LogDir)) {
    New-Item -ItemType Directory -Path $LogDir -Force | Out-Null
}

& $NSSM_PATH set AlertMysqlBackup AppStdout (Join-Path $LogDir "mysql-backup-service.log")
& $NSSM_PATH set AlertMysqlBackup AppStderr (Join-Path $LogDir "mysql-backup-service-error.log")

# Rotate logs (10MB max)
& $NSSM_PATH set AlertMysqlBackup AppStdoutCreationDisposition 4
& $NSSM_PATH set AlertMysqlBackup AppStderrCreationDisposition 4
& $NSSM_PATH set AlertMysqlBackup AppRotateFiles 1
& $NSSM_PATH set AlertMysqlBackup AppRotateOnline 1
& $NSSM_PATH set AlertMysqlBackup AppRotateBytes 10485760

Write-Host "  OK Service configured" -ForegroundColor Green
Write-Host ""

# Step 4: Start service
Write-Host "Step 4: Starting service..." -ForegroundColor Yellow

& $NSSM_PATH start AlertMysqlBackup

Start-Sleep -Seconds 3

# Check status
$status = & $NSSM_PATH status AlertMysqlBackup

if ($status -eq "SERVICE_RUNNING") {
    Write-Host "  OK Service started successfully!" -ForegroundColor Green
} else {
    Write-Host "  WARNING: Service status: $status" -ForegroundColor Yellow
    Write-Host "  Check error log: storage\logs\mysql-backup-service-error.log" -ForegroundColor Gray
}

Write-Host ""
Write-Host "=== Service Created ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Service Details:" -ForegroundColor Yellow
Write-Host "  Name: AlertMysqlBackup" -ForegroundColor White
Write-Host "  Display Name: MySQL File Backup Worker" -ForegroundColor White
Write-Host "  Command: php artisan backup:mysql-files-worker --backup-time=02:00" -ForegroundColor White
Write-Host "  Status: $status" -ForegroundColor $(if ($status -eq "SERVICE_RUNNING") { 'Green' } else { 'Yellow' })
Write-Host ""

Write-Host "What it does:" -ForegroundColor Yellow
Write-Host "  - Runs daily at 02:00 AM" -ForegroundColor White
Write-Host "  - Copies MySQL data files to D:\MysqlFileSystemBackup" -ForegroundColor White
Write-Host "  - Organizes backups by date: YEAR\MONTH\DATE" -ForegroundColor White
Write-Host "  - Auto-restarts if it crashes" -ForegroundColor White
Write-Host "  - Starts automatically on boot" -ForegroundColor White
Write-Host ""

Write-Host "Configuration:" -ForegroundColor Yellow
Write-Host "  Source: C:\wamp64\bin\mysql\mysql5.7.19\data\esurv" -ForegroundColor White
Write-Host "  Destination: D:\MysqlFileSystemBackup\YEAR\MONTH\DATE" -ForegroundColor White
Write-Host "  Files: alerts.frm, alerts.ibd, alerts.TRG" -ForegroundColor White
Write-Host "  Schedule: Daily at 02:00 AM" -ForegroundColor White
Write-Host ""

Write-Host "To change configuration:" -ForegroundColor Yellow
Write-Host "  1. Edit: app\Console\Commands\MysqlFileBackupWorker.php" -ForegroundColor Gray
Write-Host "     - Line 38: Change source directory" -ForegroundColor Gray
Write-Host "     - Line 45: Change backup directory" -ForegroundColor Gray
Write-Host "     - Lines 50-54: Change files to backup" -ForegroundColor Gray
Write-Host "  2. Restart service: Restart-Service AlertMysqlBackup" -ForegroundColor Gray
Write-Host ""

Write-Host "View logs:" -ForegroundColor Yellow
Write-Host "  Get-Content storage\logs\mysql-backup-service.log -Tail 50 -Wait" -ForegroundColor Gray
Write-Host ""

Write-Host "Manage service:" -ForegroundColor Yellow
Write-Host "  Get-Service AlertMysqlBackup" -ForegroundColor Gray
Write-Host "  Start-Service AlertMysqlBackup" -ForegroundColor Gray
Write-Host "  Stop-Service AlertMysqlBackup" -ForegroundColor Gray
Write-Host "  Restart-Service AlertMysqlBackup" -ForegroundColor Gray
Write-Host ""

Write-Host "Test backup manually:" -ForegroundColor Yellow
Write-Host "  php artisan backup:mysql-files-worker --run-once" -ForegroundColor Gray
Write-Host ""

Write-Host "Check all services:" -ForegroundColor Yellow
Write-Host "  .\codes\check-all-nssm-services.ps1" -ForegroundColor Gray
Write-Host ""

Read-Host "Press Enter to exit"

