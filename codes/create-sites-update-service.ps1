# ============================================
# Create Sites Update Sync Service with NSSM
# Run as Administrator
# ============================================
# This service syncs sites/dvrsite/dvronline updates from MySQL to PostgreSQL

# Check if running as Administrator
$currentPrincipal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "ERROR: This script must be run as Administrator!" -ForegroundColor Red
    Write-Host "Right-click PowerShell and select 'Run as Administrator'" -ForegroundColor Yellow
    Read-Host "Press Enter to exit"
    exit 1
}

Write-Host "=== Create Sites Update Sync Service ===" -ForegroundColor Cyan
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
Write-Host "=== Service Configuration ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Tables to sync:" -ForegroundColor Yellow
Write-Host "  - sites" -ForegroundColor White
Write-Host "  - dvrsite" -ForegroundColor White
Write-Host "  - dvronline" -ForegroundColor White
Write-Host ""
Write-Host "Source: MySQL (esurv database)" -ForegroundColor Yellow
Write-Host "Target: PostgreSQL" -ForegroundColor Yellow
Write-Host ""
Write-Host "Poll Interval: 5 seconds" -ForegroundColor Yellow
Write-Host "Batch Size: 100 records" -ForegroundColor Yellow
Write-Host "Max Retries: 3" -ForegroundColor Yellow
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
    $existingService = Get-Service -Name "SitesUpdateSync" -ErrorAction SilentlyContinue
    if ($existingService) {
        Write-Host "  Removing existing service..." -ForegroundColor Gray
        & $NSSM_PATH stop SitesUpdateSync 2>$null
        Start-Sleep -Seconds 2
        & $NSSM_PATH remove SitesUpdateSync confirm 2>$null
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

# Install service
& $NSSM_PATH install SitesUpdateSync $PHP_PATH "artisan" "sites:update-worker" "--poll-interval=5" "--batch-size=100" "--max-retries=3"

if ($LASTEXITCODE -ne 0) {
    Write-Host "  ERROR: Failed to install service" -ForegroundColor Red
    Read-Host "Press Enter to exit"
    exit 1
}

Write-Host "  OK Service installed" -ForegroundColor Green
Write-Host ""

# Step 3: Configure service
Write-Host "Step 3: Configuring service..." -ForegroundColor Yellow

& $NSSM_PATH set SitesUpdateSync AppDirectory $PROJECT_PATH
& $NSSM_PATH set SitesUpdateSync DisplayName "Sites Update Sync Worker"
& $NSSM_PATH set SitesUpdateSync Description "Continuously syncs sites/dvrsite/dvronline updates from MySQL to PostgreSQL"
& $NSSM_PATH set SitesUpdateSync Start SERVICE_AUTO_START
& $NSSM_PATH set SitesUpdateSync AppExit Default Restart
& $NSSM_PATH set SitesUpdateSync AppRestartDelay 5000

# Set log files
$LogDir = Join-Path $PROJECT_PATH "storage\logs"
if (-not (Test-Path $LogDir)) {
    New-Item -ItemType Directory -Path $LogDir -Force | Out-Null
}

& $NSSM_PATH set SitesUpdateSync AppStdout (Join-Path $LogDir "sites-update-sync-service.log")
& $NSSM_PATH set SitesUpdateSync AppStderr (Join-Path $LogDir "sites-update-sync-service-error.log")

# Rotate logs (10MB max)
& $NSSM_PATH set SitesUpdateSync AppStdoutCreationDisposition 4
& $NSSM_PATH set SitesUpdateSync AppStderrCreationDisposition 4
& $NSSM_PATH set SitesUpdateSync AppRotateFiles 1
& $NSSM_PATH set SitesUpdateSync AppRotateOnline 1
& $NSSM_PATH set SitesUpdateSync AppRotateBytes 10485760

Write-Host "  OK Service configured" -ForegroundColor Green
Write-Host ""

# Step 4: Start service
Write-Host "Step 4: Starting service..." -ForegroundColor Yellow

& $NSSM_PATH start SitesUpdateSync

Start-Sleep -Seconds 3

# Check status
$status = & $NSSM_PATH status SitesUpdateSync

if ($status -eq "SERVICE_RUNNING") {
    Write-Host "  OK Service started successfully!" -ForegroundColor Green
} else {
    Write-Host "  WARNING: Service status: $status" -ForegroundColor Yellow
    Write-Host "  Check error log: storage\logs\sites-update-sync-service-error.log" -ForegroundColor Gray
}

Write-Host ""
Write-Host "=== Service Created ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Service Details:" -ForegroundColor Yellow
Write-Host "  Name: SitesUpdateSync" -ForegroundColor White
Write-Host "  Display Name: Sites Update Sync Worker" -ForegroundColor White
Write-Host "  Command: php artisan sites:update-worker --poll-interval=5 --batch-size=100 --max-retries=3" -ForegroundColor White
Write-Host "  Status: $status" -ForegroundColor $(if ($status -eq "SERVICE_RUNNING") { 'Green' } else { 'Yellow' })
Write-Host ""

Write-Host "What it does:" -ForegroundColor Yellow
Write-Host "  - Monitors sites_pg_update_log table every 5 seconds" -ForegroundColor White
Write-Host "  - Syncs INSERT/UPDATE changes to PostgreSQL" -ForegroundColor White
Write-Host "  - Tables: sites, dvrsite, dvronline" -ForegroundColor White
Write-Host "  - Auto-restarts if it crashes" -ForegroundColor White
Write-Host "  - Starts automatically on boot" -ForegroundColor White
Write-Host ""

Write-Host "View logs:" -ForegroundColor Yellow
Write-Host "  Get-Content storage\logs\sites-update-sync-service.log -Tail 50 -Wait" -ForegroundColor Gray
Write-Host ""

Write-Host "Manage service:" -ForegroundColor Yellow
Write-Host "  Get-Service SitesUpdateSync" -ForegroundColor Gray
Write-Host "  Start-Service SitesUpdateSync" -ForegroundColor Gray
Write-Host "  Stop-Service SitesUpdateSync" -ForegroundColor Gray
Write-Host "  Restart-Service SitesUpdateSync" -ForegroundColor Gray
Write-Host ""

Write-Host "Check sync status:" -ForegroundColor Yellow
Write-Host "  php codes\check-sites-sync-status.php" -ForegroundColor Gray
Write-Host ""

Write-Host "Check all services:" -ForegroundColor Yellow
Write-Host "  .\codes\check-all-nssm-services.ps1" -ForegroundColor Gray
Write-Host ""

Read-Host "Press Enter to exit"
