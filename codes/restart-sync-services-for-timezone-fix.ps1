# Restart NSSM Sync Services After Timezone Fix
# 
# This script restarts all sync services to apply the timezone configuration
# and timestamp validation changes.

Write-Host "=== Restarting Sync Services for Timezone Fix ===" -ForegroundColor Cyan
Write-Host ""

# Define services to restart
$services = @(
    "AlertInitialSyncNew",
    "AlertUpdateSync",
    "AlertBackupSync"
)

# Function to check if service exists
function Test-ServiceExists {
    param([string]$ServiceName)
    
    $service = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    return $null -ne $service
}

# Function to restart service safely
function Restart-ServiceSafely {
    param([string]$ServiceName)
    
    Write-Host "Processing: $ServiceName" -ForegroundColor Yellow
    
    if (-not (Test-ServiceExists $ServiceName)) {
        Write-Host "  ⚠️  Service not found: $ServiceName" -ForegroundColor Red
        return $false
    }
    
    try {
        # Get current status
        $service = Get-Service -Name $ServiceName
        $initialStatus = $service.Status
        
        Write-Host "  Current status: $initialStatus" -ForegroundColor Gray
        
        # Stop service if running
        if ($initialStatus -eq 'Running') {
            Write-Host "  Stopping service..." -ForegroundColor Gray
            Stop-Service -Name $ServiceName -Force -ErrorAction Stop
            
            # Wait for service to stop (max 30 seconds)
            $timeout = 30
            $elapsed = 0
            while ((Get-Service -Name $ServiceName).Status -ne 'Stopped' -and $elapsed -lt $timeout) {
                Start-Sleep -Seconds 1
                $elapsed++
            }
            
            if ((Get-Service -Name $ServiceName).Status -ne 'Stopped') {
                Write-Host "  ⚠️  Service did not stop within timeout" -ForegroundColor Red
                return $false
            }
            
            Write-Host "  ✓ Service stopped" -ForegroundColor Green
        }
        
        # Start service
        Write-Host "  Starting service..." -ForegroundColor Gray
        Start-Service -Name $ServiceName -ErrorAction Stop
        
        # Wait for service to start (max 30 seconds)
        $timeout = 30
        $elapsed = 0
        while ((Get-Service -Name $ServiceName).Status -ne 'Running' -and $elapsed -lt $timeout) {
            Start-Sleep -Seconds 1
            $elapsed++
        }
        
        if ((Get-Service -Name $ServiceName).Status -ne 'Running') {
            Write-Host "  ⚠️  Service did not start within timeout" -ForegroundColor Red
            return $false
        }
        
        Write-Host "  ✓ Service started successfully" -ForegroundColor Green
        Write-Host ""
        return $true
        
    } catch {
        Write-Host "  ❌ Error: $($_.Exception.Message)" -ForegroundColor Red
        Write-Host ""
        return $false
    }
}

# Check if running as administrator
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isAdmin) {
    Write-Host "❌ This script must be run as Administrator" -ForegroundColor Red
    Write-Host ""
    Write-Host "Right-click PowerShell and select 'Run as Administrator', then run this script again." -ForegroundColor Yellow
    exit 1
}

# Restart each service
$successCount = 0
$failCount = 0

foreach ($serviceName in $services) {
    $result = Restart-ServiceSafely $serviceName
    if ($result) {
        $successCount++
    } else {
        $failCount++
    }
}

# Summary
Write-Host "=== Summary ===" -ForegroundColor Cyan
Write-Host "Services restarted successfully: $successCount" -ForegroundColor Green
Write-Host "Services failed to restart: $failCount" -ForegroundColor $(if ($failCount -gt 0) { "Red" } else { "Green" })
Write-Host ""

if ($failCount -eq 0) {
    Write-Host "✅ All services restarted successfully!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Next steps:" -ForegroundColor Yellow
    Write-Host "1. Monitor logs for timestamp validation:" -ForegroundColor White
    Write-Host "   Get-Content storage\logs\laravel.log -Tail 100 | Select-String 'Timestamp'" -ForegroundColor Gray
    Write-Host ""
    Write-Host "2. Verify sync is working correctly:" -ForegroundColor White
    Write-Host "   php test_timezone_fix.php" -ForegroundColor Gray
    Write-Host ""
} else {
    Write-Host "⚠️  Some services failed to restart. Check the errors above." -ForegroundColor Red
    Write-Host ""
    Write-Host "You can manually restart failed services using:" -ForegroundColor Yellow
    Write-Host "  Restart-Service -Name <ServiceName>" -ForegroundColor Gray
    Write-Host ""
}

# Show current service status
Write-Host "=== Current Service Status ===" -ForegroundColor Cyan
foreach ($serviceName in $services) {
    if (Test-ServiceExists $serviceName) {
        $service = Get-Service -Name $serviceName
        $status = $service.Status
        $color = if ($status -eq 'Running') { "Green" } else { "Red" }
        Write-Host "$serviceName : $status" -ForegroundColor $color
    } else {
        Write-Host "$serviceName : Not Found" -ForegroundColor Red
    }
}
Write-Host ""
