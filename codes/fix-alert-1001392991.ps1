# ============================================
# Fix Alert 1001392991 - Restart Services and Re-sync
# ============================================

Write-Host "=== Fix Alert 1001392991 ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "This script will:" -ForegroundColor Yellow
Write-Host "1. Check current data in MySQL and PostgreSQL" -ForegroundColor White
Write-Host "2. Restart sync services to apply the fix" -ForegroundColor White
Write-Host "3. Force re-sync of the alert" -ForegroundColor White
Write-Host "4. Verify the fix worked" -ForegroundColor White
Write-Host ""

# Step 1: Check current data
Write-Host "=== Step 1: Current Data ===" -ForegroundColor Cyan
Write-Host ""

Write-Host "Running timezone check..." -ForegroundColor Yellow
php check_timezone_issue.php

Write-Host ""
$continue = Read-Host "Continue with service restart? (Y/N)"
if ($continue -ne 'Y' -and $continue -ne 'y') {
    Write-Host "Cancelled." -ForegroundColor Yellow
    exit 0
}

# Step 2: Restart services
Write-Host ""
Write-Host "=== Step 2: Restarting Services ===" -ForegroundColor Cyan
Write-Host ""

# Get Alert services
$services = Get-Service | Where-Object {$_.Name -like 'Alert*'}

if ($services.Count -eq 0) {
    Write-Host "No Alert services found!" -ForegroundColor Red
    exit 1
}

Write-Host "Stopping services..." -ForegroundColor Yellow
foreach ($service in $services) {
    if ($service.Status -eq 'Running') {
        Write-Host "  Stopping $($service.Name)..." -ForegroundColor Gray
        Stop-Service -Name $service.Name -Force
    }
}

Write-Host "Waiting 5 seconds..." -ForegroundColor Gray
Start-Sleep -Seconds 5

Write-Host "Starting services..." -ForegroundColor Yellow
foreach ($service in $services) {
    Write-Host "  Starting $($service.Name)..." -ForegroundColor Gray
    Start-Service -Name $service.Name
}

Write-Host "Waiting 3 seconds for initialization..." -ForegroundColor Gray
Start-Sleep -Seconds 3

# Verify services are running
Write-Host ""
Write-Host "Service Status:" -ForegroundColor Cyan
$services = Get-Service Alert*
foreach ($service in $services) {
    $color = if ($service.Status -eq 'Running') { 'Green' } else { 'Red' }
    Write-Host "  $($service.Name): " -NoNewline
    Write-Host $service.Status -ForegroundColor $color
}

Write-Host ""
$allRunning = ($services | Where-Object {$_.Status -ne 'Running'}).Count -eq 0

if (-not $allRunning) {
    Write-Host "⚠️  Some services failed to start!" -ForegroundColor Yellow
    Write-Host "Check logs: Get-Content storage\logs\*-service.log -Tail 50" -ForegroundColor Gray
    Write-Host ""
    $continue = Read-Host "Continue anyway? (Y/N)"
    if ($continue -ne 'Y' -and $continue -ne 'y') {
        exit 1
    }
}

# Step 3: Force re-sync
Write-Host ""
Write-Host "=== Step 3: Force Re-sync ===" -ForegroundColor Cyan
Write-Host ""

Write-Host "Triggering re-sync for alert 1001392991..." -ForegroundColor Yellow

$resyncScript = @"
UPDATE alerts 
SET comment = CONCAT(comment, ' ') 
WHERE id = 1001392991;

SELECT 'Alert updated - sync will process shortly' as status;

SELECT COUNT(*) as pending_count 
FROM alert_pg_update_log 
WHERE alert_id = 1001392991 AND status = 1;
"@

# Save to temp file
$resyncScript | Out-File -FilePath "temp_resync.sql" -Encoding UTF8

# Execute
Write-Host "Executing MySQL update..." -ForegroundColor Gray
mysql -h 127.0.0.1 -u root esurv < temp_resync.sql

Remove-Item "temp_resync.sql" -ErrorAction SilentlyContinue

Write-Host "✓ Re-sync triggered" -ForegroundColor Green
Write-Host ""
Write-Host "Waiting 10 seconds for sync to process..." -ForegroundColor Yellow
Start-Sleep -Seconds 10

# Step 4: Verify
Write-Host ""
Write-Host "=== Step 4: Verification ===" -ForegroundColor Cyan
Write-Host ""

Write-Host "Checking if fix worked..." -ForegroundColor Yellow
Write-Host ""

php check_timezone_issue.php

Write-Host ""
Write-Host "=== Summary ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "If you see:" -ForegroundColor Yellow
Write-Host "  ✓ Timestamps match between MySQL and PostgreSQL" -ForegroundColor White
Write-Host "  ✓ closedtime has a value in PostgreSQL" -ForegroundColor White
Write-Host "  ✓ No timezone conversion detected" -ForegroundColor White
Write-Host ""
Write-Host "Then the fix is working!" -ForegroundColor Green
Write-Host ""
Write-Host "If issues persist:" -ForegroundColor Yellow
Write-Host "  1. Check service logs: Get-Content storage\logs\update-sync-service.log -Tail 50" -ForegroundColor White
Write-Host "  2. Check Laravel logs: Get-Content storage\logs\laravel.log -Tail 50" -ForegroundColor White
Write-Host "  3. Verify services are running: Get-Service Alert*" -ForegroundColor White
Write-Host ""

Read-Host "Press Enter to exit"
