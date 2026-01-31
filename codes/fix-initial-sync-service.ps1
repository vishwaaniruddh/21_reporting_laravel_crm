# ============================================
# Fix AlertInitialSync Service
# ============================================
# Run as Administrator

Write-Host "=== Fixing AlertInitialSync Service ===" -ForegroundColor Cyan
Write-Host ""

# Check if running as Administrator
$currentPrincipal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "ERROR: This script must be run as Administrator!" -ForegroundColor Red
    Write-Host "Right-click PowerShell and select 'Run as Administrator'" -ForegroundColor Yellow
    Read-Host "Press Enter to exit"
    exit 1
}

Write-Host "Checking service error log..." -ForegroundColor Yellow
Write-Host ""

# Check error log
if (Test-Path "storage\logs\initial-sync-service-error.log") {
    Write-Host "Last 20 lines of error log:" -ForegroundColor Yellow
    Get-Content "storage\logs\initial-sync-service-error.log" -Tail 20
    Write-Host ""
}

# Check if the old script exists
$oldScript = "codes\continuous-initial-sync.php"
if (-not (Test-Path $oldScript)) {
    Write-Host "ERROR: Old script not found: $oldScript" -ForegroundColor Red
    Write-Host "The service is configured to use this old script which doesn't exist." -ForegroundColor Yellow
    Write-Host ""
}

Write-Host "Current service configuration:" -ForegroundColor Yellow
nssm dump AlertInitialSync
Write-Host ""

Write-Host "=== Solution Options ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Option 1: Update service to use Laravel scheduler (RECOMMENDED)" -ForegroundColor Green
Write-Host "Option 2: Remove the service and use background scheduler instead" -ForegroundColor Yellow
Write-Host "Option 3: Keep service but fix the command" -ForegroundColor Yellow
Write-Host ""

$choice = Read-Host "Select option (1-3)"

switch ($choice) {
    "1" {
        Write-Host ""
        Write-Host "Updating service to use Laravel scheduler..." -ForegroundColor Yellow
        
        # Update the service command
        nssm set AlertInitialSync Application "C:\wamp64\bin\php\php8.4.11\php.exe"
        nssm set AlertInitialSync AppParameters "artisan schedule:work"
        nssm set AlertInitialSync AppDirectory (Get-Location).Path
        
        Write-Host "Service updated!" -ForegroundColor Green
        Write-Host ""
        Write-Host "Starting service..." -ForegroundColor Yellow
        nssm start AlertInitialSync
        
        Start-Sleep -Seconds 3
        
        $status = nssm status AlertInitialSync
        if ($status -eq "SERVICE_RUNNING") {
            Write-Host "OK Service started successfully!" -ForegroundColor Green
        } else {
            Write-Host "WARNING: Service status: $status" -ForegroundColor Yellow
            Write-Host "Check logs: Get-Content storage\logs\initial-sync-service.log -Tail 20" -ForegroundColor Gray
        }
    }
    "2" {
        Write-Host ""
        Write-Host "Removing NSSM service..." -ForegroundColor Yellow
        
        nssm stop AlertInitialSync
        Start-Sleep -Seconds 2
        nssm remove AlertInitialSync confirm
        
        Write-Host "Service removed!" -ForegroundColor Green
        Write-Host ""
        Write-Host "Now start the background scheduler instead:" -ForegroundColor Yellow
        Write-Host "  .\codes\start-scheduler-service.ps1" -ForegroundColor Gray
    }
    "3" {
        Write-Host ""
        Write-Host "Checking if continuous-initial-sync.php exists..." -ForegroundColor Yellow
        
        if (Test-Path "codes\continuous-initial-sync.php") {
            Write-Host "File exists. Updating service..." -ForegroundColor Green
            
            nssm set AlertInitialSync Application "C:\wamp64\bin\php\php8.4.11\php.exe"
            nssm set AlertInitialSync AppParameters "codes\continuous-initial-sync.php"
            nssm set AlertInitialSync AppDirectory (Get-Location).Path
            
            Write-Host "Starting service..." -ForegroundColor Yellow
            nssm start AlertInitialSync
            
            Start-Sleep -Seconds 3
            
            $status = nssm status AlertInitialSync
            Write-Host "Service status: $status" -ForegroundColor $(if ($status -eq "SERVICE_RUNNING") { 'Green' } else { 'Yellow' })
        } else {
            Write-Host "ERROR: File not found!" -ForegroundColor Red
            Write-Host "Choose option 1 or 2 instead." -ForegroundColor Yellow
        }
    }
    default {
        Write-Host "Invalid option" -ForegroundColor Red
    }
}

Write-Host ""
Read-Host "Press Enter to exit"

