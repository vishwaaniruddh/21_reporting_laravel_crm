# ============================================
# Alert System Windows Services Setup
# Run as Administrator
# ============================================

# Configuration - CHANGE THESE PATHS!
$PHP_PATH = "C:\wamp64\bin\php\php8.4.11\php.exe"
$PROJECT_PATH = (Get-Location).Path  # Current directory
$NSSM_PATH = "C:\ProgramData\chocolatey\bin\nssm.exe"

# Check if running as Administrator
$currentPrincipal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "ERROR: This script must be run as Administrator!" -ForegroundColor Red
    Write-Host "Right-click PowerShell and select 'Run as Administrator'" -ForegroundColor Yellow
    Read-Host "Press Enter to exit"
    exit 1
}

Write-Host "=== Alert System Services Setup ===" -ForegroundColor Cyan
Write-Host ""

# Verify paths
Write-Host "Checking paths..." -ForegroundColor Yellow

if (-not (Test-Path $PHP_PATH)) {
    Write-Host "ERROR: PHP not found at $PHP_PATH" -ForegroundColor Red
    Write-Host "Please update PHP_PATH in this script" -ForegroundColor Yellow
    
    # Try to find PHP
    $phpLocations = @("C:\wamp64\bin\php\php8.4.11\php.exe", "C:\xampp\php\php.exe", "C:\wamp\bin\php\php.exe")
    foreach ($loc in $phpLocations) {
        if (Test-Path $loc) {
            Write-Host "Found PHP at: $loc" -ForegroundColor Green
            Write-Host "Update the script with this path" -ForegroundColor Yellow
        }
    }
    
    Read-Host "Press Enter to exit"
    exit 1
}

if (-not (Test-Path $PROJECT_PATH)) {
    Write-Host "ERROR: Project not found at $PROJECT_PATH" -ForegroundColor Red
    Read-Host "Press Enter to exit"
    exit 1
}

if (-not (Test-Path $NSSM_PATH)) {
    Write-Host "ERROR: NSSM not found at $NSSM_PATH" -ForegroundColor Red
    Write-Host ""
    Write-Host "Please download NSSM from: https://nssm.cc/download" -ForegroundColor Yellow
    Write-Host "Extract to C:\nssm and update NSSM_PATH in this script" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Quick install:" -ForegroundColor Cyan
    Write-Host '  Invoke-WebRequest -Uri "https://nssm.cc/release/nssm-2.24.zip" -OutFile "C:\nssm.zip"' -ForegroundColor Gray
    Write-Host '  Expand-Archive -Path "C:\nssm.zip" -DestinationPath "C:\nssm"' -ForegroundColor Gray
    Write-Host ""
    Read-Host "Press Enter to exit"
    exit 1
}

Write-Host "✓ PHP found: $PHP_PATH" -ForegroundColor Green
Write-Host "✓ Project found: $PROJECT_PATH" -ForegroundColor Green
Write-Host "✓ NSSM found: $NSSM_PATH" -ForegroundColor Green
Write-Host ""

# Function to create service
function Create-AlertService {
    param(
        [string]$ServiceName,
        [string]$DisplayName,
        [string]$Description,
        [string]$Command,
        [string]$LogPrefix
    )
    
    Write-Host "Creating service: $DisplayName..." -ForegroundColor Yellow
    
    # Remove service if it exists
    $existingService = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    if ($existingService) {
        Write-Host "  Removing existing service..." -ForegroundColor Gray
        & $NSSM_PATH stop $ServiceName 2>$null
        Start-Sleep -Seconds 2
        & $NSSM_PATH remove $ServiceName confirm 2>$null
        Start-Sleep -Seconds 2
    }
    
    # Install service
    Write-Host "  Installing service..." -ForegroundColor Gray
    & $NSSM_PATH install $ServiceName $PHP_PATH $Command
    
    if ($LASTEXITCODE -ne 0) {
        Write-Host "  ERROR: Failed to install service" -ForegroundColor Red
        return
    }
    
    # Configure service
    Write-Host "  Configuring service..." -ForegroundColor Gray
    & $NSSM_PATH set $ServiceName AppDirectory $PROJECT_PATH
    & $NSSM_PATH set $ServiceName DisplayName $DisplayName
    & $NSSM_PATH set $ServiceName Description $Description
    & $NSSM_PATH set $ServiceName Start SERVICE_AUTO_START
    & $NSSM_PATH set $ServiceName AppExit Default Restart
    & $NSSM_PATH set $ServiceName AppRestartDelay 5000
    
    # Set log files
    $LogDir = Join-Path $PROJECT_PATH "storage\logs"
    if (-not (Test-Path $LogDir)) {
        New-Item -ItemType Directory -Path $LogDir -Force | Out-Null
    }
    
    & $NSSM_PATH set $ServiceName AppStdout (Join-Path $LogDir "$LogPrefix-service.log")
    & $NSSM_PATH set $ServiceName AppStderr (Join-Path $LogDir "$LogPrefix-service-error.log")
    
    # Rotate logs (10MB max)
    & $NSSM_PATH set $ServiceName AppStdoutCreationDisposition 4
    & $NSSM_PATH set $ServiceName AppStderrCreationDisposition 4
    & $NSSM_PATH set $ServiceName AppRotateFiles 1
    & $NSSM_PATH set $ServiceName AppRotateOnline 1
    & $NSSM_PATH set $ServiceName AppRotateBytes 10485760
    
    # Start service
    Write-Host "  Starting service..." -ForegroundColor Gray
    & $NSSM_PATH start $ServiceName
    
    Start-Sleep -Seconds 2
    
    # Check status
    $status = & $NSSM_PATH status $ServiceName
    if ($status -eq "SERVICE_RUNNING") {
        Write-Host "✓ Service created and started: $DisplayName" -ForegroundColor Green
    } else {
        Write-Host "⚠ Service created but not running: $DisplayName (Status: $status)" -ForegroundColor Yellow
        Write-Host "  Check logs at: $LogDir\$LogPrefix-service-error.log" -ForegroundColor Gray
    }
    Write-Host ""
}

# Create Service 1: Web Portal
Create-AlertService `
    -ServiceName "AlertPortal" `
    -DisplayName "Alert System Portal" `
    -Description "Laravel web portal accessible at http://192.168.100.21:9000" `
    -Command "artisan serve --host=192.168.100.21 --port=9000" `
    -LogPrefix "portal"

# Create Service 2: Initial Sync Worker
Create-AlertService `
    -ServiceName "AlertInitialSync" `
    -DisplayName "Alert Initial Sync Worker" `
    -Description "Continuously syncs new alerts from MySQL to PostgreSQL" `
    -Command "artisan sync:partitioned --poll-interval=20" `
    -LogPrefix "initial-sync"

# Create Service 3: Update Sync Worker
Create-AlertService `
    -ServiceName "AlertUpdateSync" `
    -DisplayName "Alert Update Sync Worker" `
    -Description "Continuously syncs alert updates from MySQL to PostgreSQL" `
    -Command "artisan sync:update-worker --poll-interval=5 --batch-size=100" `
    -LogPrefix "update-sync"

# Configure firewall for portal
Write-Host "Configuring firewall..." -ForegroundColor Yellow
$firewallRule = Get-NetFirewallRule -DisplayName "Alert Portal" -ErrorAction SilentlyContinue
if (-not $firewallRule) {
    New-NetFirewallRule -DisplayName "Alert Portal" -Direction Inbound -LocalPort 9000 -Protocol TCP -Action Allow | Out-Null
    Write-Host "✓ Firewall rule created for port 9000" -ForegroundColor Green
} else {
    Write-Host "✓ Firewall rule already exists" -ForegroundColor Green
}
Write-Host ""

Write-Host "=== Setup Complete ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Services created and started:" -ForegroundColor Green
Write-Host "  1. AlertPortal - http://192.168.100.21:9000" -ForegroundColor White
Write-Host "  2. AlertInitialSync - Syncing new alerts every 20 minutes" -ForegroundColor White
Write-Host "  3. AlertUpdateSync - Syncing alert updates every 5 seconds" -ForegroundColor White
Write-Host ""
Write-Host "Check service status:" -ForegroundColor Yellow
Write-Host "  Get-Service | Where-Object {`$_.Name -like 'Alert*'}" -ForegroundColor Gray
Write-Host ""
Write-Host "View logs:" -ForegroundColor Yellow
Write-Host "  Get-Content '$PROJECT_PATH\storage\logs\portal-service.log' -Tail 50 -Wait" -ForegroundColor Gray
Write-Host "  Get-Content '$PROJECT_PATH\storage\logs\initial-sync-service.log' -Tail 50 -Wait" -ForegroundColor Gray
Write-Host "  Get-Content '$PROJECT_PATH\storage\logs\update-sync-service.log' -Tail 50 -Wait" -ForegroundColor Gray
Write-Host ""
Write-Host "Manage services:" -ForegroundColor Yellow
Write-Host "  services.msc" -ForegroundColor Gray
Write-Host ""
Write-Host "Test portal:" -ForegroundColor Yellow
Write-Host "  Open browser: http://192.168.100.21:9000" -ForegroundColor Gray
Write-Host ""

Read-Host "Press Enter to exit"
