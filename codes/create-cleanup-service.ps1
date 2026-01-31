# ============================================
# Create AlertCleanup Service with NSSM
# Run as Administrator
# ============================================
# This service deletes old records from MySQL alerts_2 table

# Check if running as Administrator
$currentPrincipal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "ERROR: This script must be run as Administrator!" -ForegroundColor Red
    Write-Host "Right-click PowerShell and select 'Run as Administrator'" -ForegroundColor Yellow
    Read-Host "Press Enter to exit"
    exit 1
}

Write-Host "=== Create AlertCleanup Service ===" -ForegroundColor Cyan
Write-Host ""

# Configuration
$PHP_PATH = "C:\wamp64\bin\php\php8.4.11\php.exe"
$PROJECT_PATH = (Get-Location).Path
$NSSM_PATH = "nssm"  # Assuming nssm is in PATH

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

# Show warning
Write-Host "=== WARNING ===" -ForegroundColor Red
Write-Host ""
Write-Host "This service will DELETE old records from MySQL!" -ForegroundColor Red
Write-Host ""
Write-Host "Current Configuration:" -ForegroundColor Yellow
Write-Host "  Table: alerts_2" -ForegroundColor White
Write-Host "  Retention: 48 hours (2 days)" -ForegroundColor White
Write-Host "  Check Interval: 1 hour (checks every hour for old records)" -ForegroundColor White
Write-Host ""
Write-Host "To change these settings, edit:" -ForegroundColor Yellow
Write-Host "  app\Console\Commands\CleanupOldAlertsWorker.php" -ForegroundColor Gray
Write-Host "  Lines 30-48 (TABLE NAME and RETENTION HOURS)" -ForegroundColor Gray
Write-Host ""

$confirm = Read-Host "Do you want to create this service? (type 'YES' to confirm)"

if ($confirm -ne 'YES') {
    Write-Host "Operation cancelled." -ForegroundColor Yellow
    Read-Host "Press Enter to exit"
    exit 0
}

Write-Host ""

# Step 1: Remove service if it exists
Write-Host "Step 1: Checking for existing service..." -ForegroundColor Yellow
try {
    $existingService = Get-Service -Name "AlertCleanup" -ErrorAction SilentlyContinue
    if ($existingService) {
        Write-Host "  Removing existing service..." -ForegroundColor Gray
        & $NSSM_PATH stop AlertCleanup 2>$null
        Start-Sleep -Seconds 2
        & $NSSM_PATH remove AlertCleanup confirm 2>$null
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

# Install service with cleanup worker
& $NSSM_PATH install AlertCleanup $PHP_PATH "artisan" "cleanup:old-alerts-worker" "--check-interval=3600"

if ($LASTEXITCODE -ne 0) {
    Write-Host "  ERROR: Failed to install service" -ForegroundColor Red
    Read-Host "Press Enter to exit"
    exit 1
}

Write-Host "  OK Service installed" -ForegroundColor Green
Write-Host ""

# Step 3: Configure service
Write-Host "Step 3: Configuring service..." -ForegroundColor Yellow

& $NSSM_PATH set AlertCleanup AppDirectory $PROJECT_PATH
& $NSSM_PATH set AlertCleanup DisplayName "Alert Cleanup Worker"
& $NSSM_PATH set AlertCleanup Description "Deletes old records from MySQL alerts_2 table (older than 48 hours)"
& $NSSM_PATH set AlertCleanup Start SERVICE_AUTO_START
& $NSSM_PATH set AlertCleanup AppExit Default Restart
& $NSSM_PATH set AlertCleanup AppRestartDelay 5000

# Set log files
$LogDir = Join-Path $PROJECT_PATH "storage\logs"
if (-not (Test-Path $LogDir)) {
    New-Item -ItemType Directory -Path $LogDir -Force | Out-Null
}

& $NSSM_PATH set AlertCleanup AppStdout (Join-Path $LogDir "cleanup-service.log")
& $NSSM_PATH set AlertCleanup AppStderr (Join-Path $LogDir "cleanup-service-error.log")

# Rotate logs (10MB max)
& $NSSM_PATH set AlertCleanup AppStdoutCreationDisposition 4
& $NSSM_PATH set AlertCleanup AppStderrCreationDisposition 4
& $NSSM_PATH set AlertCleanup AppRotateFiles 1
& $NSSM_PATH set AlertCleanup AppRotateOnline 1
& $NSSM_PATH set AlertCleanup AppRotateBytes 10485760

Write-Host "  OK Service configured" -ForegroundColor Green
Write-Host ""

# Step 4: Ask if user wants to start now
Write-Host "Step 4: Start service now?" -ForegroundColor Yellow
Write-Host ""
Write-Host "The service will:" -ForegroundColor White
Write-Host "  - Check every 1 hour for old records" -ForegroundColor Gray
Write-Host "  - Delete records from 'alerts_2' older than 48 hours" -ForegroundColor Gray
Write-Host "  - Log all deletions to storage\logs\cleanup-service.log" -ForegroundColor Gray
Write-Host ""

$startNow = Read-Host "Start service now? (y/n)"

if ($startNow -eq 'y') {
    Write-Host ""
    Write-Host "Starting service..." -ForegroundColor Yellow
    
    & $NSSM_PATH start AlertCleanup
    
    Start-Sleep -Seconds 3
    
    # Check status
    $status = & $NSSM_PATH status AlertCleanup
    
    if ($status -eq "SERVICE_RUNNING") {
        Write-Host "  OK Service started successfully!" -ForegroundColor Green
    } else {
        Write-Host "  WARNING: Service status: $status" -ForegroundColor Yellow
        Write-Host "  Check error log: storage\logs\cleanup-service-error.log" -ForegroundColor Gray
    }
} else {
    Write-Host ""
    Write-Host "Service created but not started." -ForegroundColor Yellow
    Write-Host "To start later: Start-Service AlertCleanup" -ForegroundColor Gray
}

Write-Host ""
Write-Host "=== Service Created ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Service Details:" -ForegroundColor Yellow
Write-Host "  Name: AlertCleanup" -ForegroundColor White
Write-Host "  Display Name: Alert Cleanup Worker" -ForegroundColor White
Write-Host "  Command: php artisan cleanup:old-alerts-worker --check-interval=3600" -ForegroundColor White
Write-Host ""

Write-Host "Configuration:" -ForegroundColor Yellow
Write-Host "  Table: alerts_2" -ForegroundColor White
Write-Host "  Retention: 48 hours (2 days)" -ForegroundColor White
Write-Host "  Check Interval: 1 hour (3600 seconds)" -ForegroundColor White
Write-Host ""

Write-Host "To change configuration:" -ForegroundColor Yellow
Write-Host "  1. Edit: app\Console\Commands\CleanupOldAlertsWorker.php" -ForegroundColor Gray
Write-Host "     - Line 35: Change table name (currently 'alerts_2')" -ForegroundColor Gray
Write-Host "     - Line 48: Change retention hours (currently 48)" -ForegroundColor Gray
Write-Host "  2. Restart service: Restart-Service AlertCleanup" -ForegroundColor Gray
Write-Host ""

Write-Host "View logs:" -ForegroundColor Yellow
Write-Host "  Get-Content storage\logs\cleanup-service.log -Tail 50 -Wait" -ForegroundColor Gray
Write-Host ""

Write-Host "Manage service:" -ForegroundColor Yellow
Write-Host "  Get-Service AlertCleanup" -ForegroundColor Gray
Write-Host "  Start-Service AlertCleanup" -ForegroundColor Gray
Write-Host "  Stop-Service AlertCleanup" -ForegroundColor Gray
Write-Host "  Restart-Service AlertCleanup" -ForegroundColor Gray
Write-Host ""

Write-Host "Check all services:" -ForegroundColor Yellow
Write-Host "  .\codes\check-all-nssm-services.ps1" -ForegroundColor Gray
Write-Host ""

Read-Host "Press Enter to exit"

