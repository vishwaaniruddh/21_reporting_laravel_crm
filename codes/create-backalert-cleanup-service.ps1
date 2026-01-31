# ============================================
# Create BackAlertCleanup Service with NSSM
# Run as Administrator
# ============================================
# This service deletes old records from MySQL backalerts table

# Check if running as Administrator
$currentPrincipal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "ERROR: This script must be run as Administrator!" -ForegroundColor Red
    Write-Host "Right-click PowerShell and select 'Run as Administrator'" -ForegroundColor Yellow
    Read-Host "Press Enter to exit"
    exit 1
}

Write-Host "=== Create BackAlertCleanup Service ===" -ForegroundColor Cyan
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
Write-Host "  Table: backalerts" -ForegroundColor White
Write-Host "  Retention: 48 hours (2 days)" -ForegroundColor White
Write-Host "  Batch Size: 5000 records per batch" -ForegroundColor White
Write-Host "  Poll Interval: 60 seconds (checks every minute)" -ForegroundColor White
Write-Host "  Mode: Sync-only (only deletes records that have been synced to PostgreSQL)" -ForegroundColor White
Write-Host ""
Write-Host "To change these settings, edit:" -ForegroundColor Yellow
Write-Host "  app\Console\Commands\BackAlertCleanupWorker.php" -ForegroundColor Gray
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
    $existingService = Get-Service -Name "BackAlertCleanup" -ErrorAction SilentlyContinue
    if ($existingService) {
        Write-Host "  Removing existing service..." -ForegroundColor Gray
        & $NSSM_PATH stop BackAlertCleanup 2>$null
        Start-Sleep -Seconds 2
        & $NSSM_PATH remove BackAlertCleanup confirm 2>$null
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

# Install service with backalert cleanup worker
# Aggressive settings: 5000 records per batch, check every 60 seconds
& $NSSM_PATH install BackAlertCleanup $PHP_PATH "artisan" "backalerts:cleanup-worker" "--hours=48" "--batch-size=5000" "--poll-interval=60" "--sync-only"

if ($LASTEXITCODE -ne 0) {
    Write-Host "  ERROR: Failed to install service" -ForegroundColor Red
    Read-Host "Press Enter to exit"
    exit 1
}

Write-Host "  OK Service installed" -ForegroundColor Green
Write-Host ""

# Step 3: Configure service
Write-Host "Step 3: Configuring service..." -ForegroundColor Yellow

& $NSSM_PATH set BackAlertCleanup AppDirectory $PROJECT_PATH
& $NSSM_PATH set BackAlertCleanup DisplayName "BackAlert Cleanup Worker"
& $NSSM_PATH set BackAlertCleanup Description "Deletes old records from MySQL backalerts table (older than 48 hours, synced only)"
& $NSSM_PATH set BackAlertCleanup Start SERVICE_AUTO_START
& $NSSM_PATH set BackAlertCleanup AppExit Default Restart
& $NSSM_PATH set BackAlertCleanup AppRestartDelay 5000

# Set log files
$LogDir = Join-Path $PROJECT_PATH "storage\logs"
if (-not (Test-Path $LogDir)) {
    New-Item -ItemType Directory -Path $LogDir -Force | Out-Null
}

& $NSSM_PATH set BackAlertCleanup AppStdout (Join-Path $LogDir "backalert-cleanup-service.log")
& $NSSM_PATH set BackAlertCleanup AppStderr (Join-Path $LogDir "backalert-cleanup-service-error.log")

# Rotate logs (10MB max)
& $NSSM_PATH set BackAlertCleanup AppStdoutCreationDisposition 4
& $NSSM_PATH set BackAlertCleanup AppStderrCreationDisposition 4
& $NSSM_PATH set BackAlertCleanup AppRotateFiles 1
& $NSSM_PATH set BackAlertCleanup AppRotateOnline 1
& $NSSM_PATH set BackAlertCleanup AppRotateBytes 10485760

Write-Host "  OK Service configured" -ForegroundColor Green
Write-Host ""

# Step 4: Ask if user wants to start now
Write-Host "Step 4: Start service now?" -ForegroundColor Yellow
Write-Host ""
Write-Host "The service will:" -ForegroundColor White
Write-Host "  - Check every 60 seconds (1 minute) for old records" -ForegroundColor Gray
Write-Host "  - Delete 5000 records per batch (up to 300,000 records/hour)" -ForegroundColor Gray
Write-Host "  - Delete records from 'backalerts' older than 48 hours" -ForegroundColor Gray
Write-Host "  - Only delete records that have been synced to PostgreSQL" -ForegroundColor Gray
Write-Host "  - Log all deletions to storage\logs\backalert-cleanup-service.log" -ForegroundColor Gray
Write-Host ""

$startNow = Read-Host "Start service now? (y/n)"

if ($startNow -eq 'y') {
    Write-Host ""
    Write-Host "Starting service..." -ForegroundColor Yellow
    
    & $NSSM_PATH start BackAlertCleanup
    
    Start-Sleep -Seconds 3
    
    # Check status
    $status = & $NSSM_PATH status BackAlertCleanup
    
    if ($status -eq "SERVICE_RUNNING") {
        Write-Host "  OK Service started successfully!" -ForegroundColor Green
    } else {
        Write-Host "  WARNING: Service status: $status" -ForegroundColor Yellow
        Write-Host "  Check error log: storage\logs\backalert-cleanup-service-error.log" -ForegroundColor Gray
    }
} else {
    Write-Host ""
    Write-Host "Service created but not started." -ForegroundColor Yellow
    Write-Host "To start later: Start-Service BackAlertCleanup" -ForegroundColor Gray
}

Write-Host ""
Write-Host "=== Service Created ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Service Details:" -ForegroundColor Yellow
Write-Host "  Name: BackAlertCleanup" -ForegroundColor White
Write-Host "  Display Name: BackAlert Cleanup Worker" -ForegroundColor White
Write-Host "  Command: php artisan backalerts:cleanup-worker --hours=48 --batch-size=5000 --poll-interval=60 --sync-only" -ForegroundColor White
Write-Host ""

Write-Host "Configuration:" -ForegroundColor Yellow
Write-Host "  Table: backalerts" -ForegroundColor White
Write-Host "  Retention: 48 hours (2 days)" -ForegroundColor White
Write-Host "  Poll Interval: 60 seconds (checks every minute)" -ForegroundColor White
Write-Host "  Batch Size: 5000 records per batch" -ForegroundColor White
Write-Host "  Throughput: Up to 300,000 records/hour" -ForegroundColor White
Write-Host "  Mode: Sync-only (only deletes synced records)" -ForegroundColor White
Write-Host ""

Write-Host "To change configuration:" -ForegroundColor Yellow
Write-Host "  1. Stop service: Stop-Service BackAlertCleanup" -ForegroundColor Gray
Write-Host "  2. Edit service parameters with NSSM:" -ForegroundColor Gray
Write-Host "     nssm edit BackAlertCleanup" -ForegroundColor Gray
Write-Host "  3. Or edit: app\Console\Commands\BackAlertCleanupWorker.php" -ForegroundColor Gray
Write-Host "  4. Restart service: Start-Service BackAlertCleanup" -ForegroundColor Gray
Write-Host ""

Write-Host "View logs:" -ForegroundColor Yellow
Write-Host "  Get-Content storage\logs\backalert-cleanup-service.log -Tail 50 -Wait" -ForegroundColor Gray
Write-Host ""

Write-Host "Manage service:" -ForegroundColor Yellow
Write-Host "  Get-Service BackAlertCleanup" -ForegroundColor Gray
Write-Host "  Start-Service BackAlertCleanup" -ForegroundColor Gray
Write-Host "  Stop-Service BackAlertCleanup" -ForegroundColor Gray
Write-Host "  Restart-Service BackAlertCleanup" -ForegroundColor Gray
Write-Host ""

Write-Host "Check all services:" -ForegroundColor Yellow
Write-Host "  .\codes\check-all-nssm-services.ps1" -ForegroundColor Gray
Write-Host ""

Write-Host "IMPORTANT NOTES:" -ForegroundColor Yellow
Write-Host "  - This service only deletes records that have been synced to PostgreSQL" -ForegroundColor White
Write-Host "  - Records with synced_at = NULL will NOT be deleted" -ForegroundColor White
Write-Host "  - This ensures no data loss if sync is behind" -ForegroundColor White
Write-Host ""

Read-Host "Press Enter to exit"
