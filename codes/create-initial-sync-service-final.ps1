# ============================================
# Create AlertInitialSync Service (Final Version)
# ============================================
# Run this script as Administrator

Write-Host ""
Write-Host "=== Creating AlertInitialSync Service ===" -ForegroundColor Cyan
Write-Host ""

$SERVICE_NAME = "AlertInitialSync"
$PHP_PATH = "C:\wamp64\bin\php\php8.4.11\php.exe"
$PROJECT_PATH = "C:\wamp64\www\comfort_reporting_crm\dual-database-app"

# Remove if exists
$existing = Get-Service -Name $SERVICE_NAME -ErrorAction SilentlyContinue
if ($existing) {
    Write-Host "Removing existing service..." -ForegroundColor Yellow
    nssm remove $SERVICE_NAME confirm
    Start-Sleep -Seconds 3
}

Write-Host "Creating service with NSSM..." -ForegroundColor Green

# Install service
nssm install $SERVICE_NAME "$PHP_PATH"

# Set parameters - using the wrapper script
nssm set $SERVICE_NAME AppParameters "initial_sync_worker.php"
nssm set $SERVICE_NAME AppDirectory "$PROJECT_PATH"

# Set restart behavior
nssm set $SERVICE_NAME AppExit Default Restart
nssm set $SERVICE_NAME AppRestartDelay 5000

# Set logging
nssm set $SERVICE_NAME AppStdout "$PROJECT_PATH\storage\logs\initial-sync-service.log"
nssm set $SERVICE_NAME AppStderr "$PROJECT_PATH\storage\logs\initial-sync-service-error.log"
nssm set $SERVICE_NAME AppRotateFiles 1
nssm set $SERVICE_NAME AppRotateOnline 1
nssm set $SERVICE_NAME AppRotateBytes 10485760
nssm set $SERVICE_NAME AppRotateSeconds 86400

# Set service details
nssm set $SERVICE_NAME Description "Continuously syncs new alerts from MySQL to PostgreSQL partitioned tables"
nssm set $SERVICE_NAME DisplayName "Alert Initial Sync Worker"
nssm set $SERVICE_NAME ObjectName LocalSystem
nssm set $SERVICE_NAME Start SERVICE_AUTO_START
nssm set $SERVICE_NAME Type SERVICE_WIN32_OWN_PROCESS

Write-Host "Service created successfully!" -ForegroundColor Green
Write-Host ""

# Start the service
Write-Host "Starting service..." -ForegroundColor Green
Start-Service -Name $SERVICE_NAME

Start-Sleep -Seconds 3

# Check status
$service = Get-Service -Name $SERVICE_NAME
Write-Host ""
Write-Host "=== Service Status ===" -ForegroundColor Cyan
Write-Host "Name: $($service.Name)" -ForegroundColor White
Write-Host "Status: $($service.Status)" -ForegroundColor $(if ($service.Status -eq 'Running') { 'Green' } else { 'Red' })
Write-Host "Start Type: $($service.StartType)" -ForegroundColor White
Write-Host ""

if ($service.Status -eq 'Running') {
    Write-Host "✅ Service is running!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Monitor logs:" -ForegroundColor Yellow
    Write-Host "  Get-Content '$PROJECT_PATH\storage\logs\initial-sync-service.log' -Tail 20 -Wait" -ForegroundColor Gray
} else {
    Write-Host "❌ Service failed to start" -ForegroundColor Red
    Write-Host ""
    Write-Host "Check error log:" -ForegroundColor Yellow
    Write-Host "  Get-Content '$PROJECT_PATH\storage\logs\initial-sync-service-error.log' -Tail 20" -ForegroundColor Gray
}

Write-Host ""
Read-Host "Press Enter to exit"