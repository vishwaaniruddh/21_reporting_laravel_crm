# ============================================
# Recreate AlertInitialSync Service
# ============================================
# Run this script as Administrator

Write-Host ""
Write-Host "=== Recreating AlertInitialSync Service ===" -ForegroundColor Cyan
Write-Host ""

# Configuration
$PROJECT_PATH = "C:\wamp64\www\comfort_reporting_crm\dual-database-app"
$PHP_PATH = "C:\wamp64\bin\php\php8.4.11\php.exe"
$SERVICE_NAME = "AlertInitialSync"

# Check if service already exists
$existingService = Get-Service -Name $SERVICE_NAME -ErrorAction SilentlyContinue

if ($existingService) {
    Write-Host "Service $SERVICE_NAME already exists with status: $($existingService.Status)" -ForegroundColor Yellow
    
    if ($existingService.Status -eq 'Running') {
        Write-Host "Stopping existing service..." -ForegroundColor Yellow
        Stop-Service -Name $SERVICE_NAME -Force
        Start-Sleep -Seconds 3
    }
    
    Write-Host "Removing existing service..." -ForegroundColor Yellow
    nssm remove $SERVICE_NAME confirm
    Start-Sleep -Seconds 2
}

Write-Host "Creating AlertInitialSync service..." -ForegroundColor Green

# Create the service using NSSM
nssm install $SERVICE_NAME "$PHP_PATH"
nssm set $SERVICE_NAME AppParameters "artisan sync:partitioned --poll-interval=20"
nssm set $SERVICE_NAME AppDirectory "$PROJECT_PATH"
nssm set $SERVICE_NAME DisplayName "Alert Initial Sync Worker"
nssm set $SERVICE_NAME Description "Continuously syncs new alerts from MySQL to PostgreSQL partitioned tables"
nssm set $SERVICE_NAME Start SERVICE_AUTO_START

# Set up logging
nssm set $SERVICE_NAME AppStdout "$PROJECT_PATH\storage\logs\initial-sync-service.log"
nssm set $SERVICE_NAME AppStderr "$PROJECT_PATH\storage\logs\initial-sync-service-error.log"
nssm set $SERVICE_NAME AppRotateFiles 1
nssm set $SERVICE_NAME AppRotateOnline 1
nssm set $SERVICE_NAME AppRotateSeconds 86400
nssm set $SERVICE_NAME AppRotateBytes 10485760

# Set service recovery options
nssm set $SERVICE_NAME AppExit Default Restart
nssm set $SERVICE_NAME AppRestartDelay 5000

Write-Host "Service created successfully!" -ForegroundColor Green

# Start the service
Write-Host "Starting AlertInitialSync service..." -ForegroundColor Green
Start-Service -Name $SERVICE_NAME

# Wait a moment and check status
Start-Sleep -Seconds 3
$service = Get-Service -Name $SERVICE_NAME

Write-Host ""
Write-Host "=== Service Status ===" -ForegroundColor Cyan
Write-Host "Name: $($service.Name)" -ForegroundColor White
Write-Host "Status: $($service.Status)" -ForegroundColor $(if ($service.Status -eq 'Running') { 'Green' } else { 'Red' })
Write-Host "Start Type: $($service.StartType)" -ForegroundColor White
Write-Host ""

if ($service.Status -eq 'Running') {
    Write-Host "✅ AlertInitialSync service is now running!" -ForegroundColor Green
    Write-Host ""
    Write-Host "The service will:" -ForegroundColor Yellow
    Write-Host "  • Sync new alerts from MySQL to PostgreSQL partitioned tables" -ForegroundColor Gray
    Write-Host "  • Run every 20 minutes (poll-interval=20)" -ForegroundColor Gray
    Write-Host "  • Log to: storage\logs\initial-sync-service.log" -ForegroundColor Gray
    Write-Host ""
    Write-Host "Monitor logs with:" -ForegroundColor Yellow
    Write-Host "  Get-Content '$PROJECT_PATH\storage\logs\initial-sync-service.log' -Tail 20 -Wait" -ForegroundColor Gray
} else {
    Write-Host "❌ Service failed to start. Check logs:" -ForegroundColor Red
    Write-Host "  Get-Content '$PROJECT_PATH\storage\logs\initial-sync-service-error.log' -Tail 20" -ForegroundColor Gray
}

Write-Host ""
Read-Host "Press Enter to exit"