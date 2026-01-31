# Safe Package Installation Script
# Stops all Alert services, installs package, then restarts services

Write-Host "=== Safe Package Installation ===" -ForegroundColor Cyan
Write-Host ""

# Check if running as administrator
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isAdmin) {
    Write-Host "ERROR: This script must be run as Administrator!" -ForegroundColor Red
    Write-Host "Right-click PowerShell and select 'Run as Administrator'" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Press Enter to exit..."
    Read-Host
    exit 1
}

Write-Host "Running as Administrator" -ForegroundColor Green
Write-Host ""

# Step 1: List current services
Write-Host "Step 1: Checking Alert services..." -ForegroundColor Yellow
$services = Get-Service | Where-Object {$_.Name -like "Alert*"}

if ($services) {
    Write-Host "  Found services:" -ForegroundColor Gray
    foreach ($service in $services) {
        $status = $service.Status
        if ($status -eq "Running") {
            Write-Host "    - $($service.Name): $status" -ForegroundColor Green
        } else {
            Write-Host "    - $($service.Name): $status" -ForegroundColor Yellow
        }
    }
} else {
    Write-Host "  No Alert services found" -ForegroundColor Gray
}

Write-Host ""

# Step 2: Stop all Alert services
Write-Host "Step 2: Stopping all Alert services..." -ForegroundColor Yellow

if ($services) {
    foreach ($service in $services) {
        if ($service.Status -eq "Running") {
            Write-Host "  Stopping $($service.Name)..." -ForegroundColor Gray
            try {
                Stop-Service -Name $service.Name -Force -ErrorAction Stop
                Start-Sleep -Seconds 2
                Write-Host "    Stopped" -ForegroundColor Green
            } catch {
                Write-Host "    Failed: $($_.Exception.Message)" -ForegroundColor Red
            }
        } else {
            Write-Host "  $($service.Name) already stopped" -ForegroundColor Gray
        }
    }
    
    # Wait a bit for file handles to release
    Write-Host "  Waiting for file handles to release..." -ForegroundColor Gray
    Start-Sleep -Seconds 5
    Write-Host "    Ready" -ForegroundColor Green
} else {
    Write-Host "  No services to stop" -ForegroundColor Gray
}

Write-Host ""

# Step 3: Clear composer cache
Write-Host "Step 3: Clearing composer cache..." -ForegroundColor Yellow
try {
    composer clear-cache 2>&1 | Out-Null
    Write-Host "  Cache cleared" -ForegroundColor Green
} catch {
    Write-Host "  Warning: $($_.Exception.Message)" -ForegroundColor Yellow
}

Write-Host ""

# Step 4: Install PhpSpreadsheet
Write-Host "Step 4: Installing phpoffice/phpspreadsheet..." -ForegroundColor Yellow
Write-Host "  This may take a few minutes, please wait..." -ForegroundColor Gray
Write-Host ""

$installSuccess = $false

try {
    # Run composer require
    composer require phpoffice/phpspreadsheet --no-interaction
    
    if ($LASTEXITCODE -eq 0) {
        Write-Host "  Package installed successfully!" -ForegroundColor Green
        $installSuccess = $true
    } else {
        Write-Host "  Installation had issues, trying alternative method..." -ForegroundColor Yellow
        
        # Try with prefer-dist
        composer require phpoffice/phpspreadsheet --prefer-dist --no-interaction
        
        if ($LASTEXITCODE -eq 0) {
            Write-Host "  Package installed with --prefer-dist!" -ForegroundColor Green
            $installSuccess = $true
        } else {
            Write-Host "  Installation failed" -ForegroundColor Red
        }
    }
} catch {
    Write-Host "  Error: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host ""

# Step 5: Verify installation
Write-Host "Step 5: Verifying installation..." -ForegroundColor Yellow
try {
    $verifyOutput = composer show phpoffice/phpspreadsheet 2>&1 | Out-String
    
    if ($verifyOutput -like "*phpoffice/phpspreadsheet*") {
        Write-Host "  Package verified!" -ForegroundColor Green
        $installSuccess = $true
        
        # Extract version
        if ($verifyOutput -match "versions\s*:\s*\*\s*(\S+)") {
            Write-Host "  Version: $($matches[1])" -ForegroundColor Gray
        }
    } else {
        Write-Host "  Package not found" -ForegroundColor Red
        $installSuccess = $false
    }
} catch {
    Write-Host "  Verification failed: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host ""

# Step 6: Dump autoload
Write-Host "Step 6: Regenerating autoload files..." -ForegroundColor Yellow
try {
    composer dump-autoload 2>&1 | Out-Null
    Write-Host "  Autoload regenerated" -ForegroundColor Green
} catch {
    Write-Host "  Warning: $($_.Exception.Message)" -ForegroundColor Yellow
}

Write-Host ""

# Step 7: Create storage directory
Write-Host "Step 7: Creating storage directory..." -ForegroundColor Yellow
$storageDir = "storage\app\public\reports\excel"

if (Test-Path $storageDir) {
    Write-Host "  Directory already exists" -ForegroundColor Green
} else {
    try {
        New-Item -ItemType Directory -Path $storageDir -Force | Out-Null
        Write-Host "  Directory created: $storageDir" -ForegroundColor Green
    } catch {
        Write-Host "  Failed: $($_.Exception.Message)" -ForegroundColor Red
    }
}

Write-Host ""

# Step 8: Restart all Alert services
Write-Host "Step 8: Restarting all Alert services..." -ForegroundColor Yellow

if ($services) {
    foreach ($service in $services) {
        Write-Host "  Starting $($service.Name)..." -ForegroundColor Gray
        try {
            Start-Service -Name $service.Name -ErrorAction Stop
            Start-Sleep -Seconds 2
            
            # Verify it started
            $currentStatus = (Get-Service -Name $service.Name).Status
            if ($currentStatus -eq "Running") {
                Write-Host "    Running" -ForegroundColor Green
            } else {
                Write-Host "    Status: $currentStatus" -ForegroundColor Yellow
            }
        } catch {
            Write-Host "    Failed: $($_.Exception.Message)" -ForegroundColor Red
        }
    }
} else {
    Write-Host "  No services to restart" -ForegroundColor Gray
}

Write-Host ""

# Step 9: Verify services are running
Write-Host "Step 9: Verifying all services..." -ForegroundColor Yellow
$services = Get-Service | Where-Object {$_.Name -like "Alert*"}

if ($services) {
    $allRunning = $true
    foreach ($service in $services) {
        $status = $service.Status
        if ($status -eq "Running") {
            Write-Host "  $($service.Name): $status" -ForegroundColor Green
        } else {
            Write-Host "  $($service.Name): $status" -ForegroundColor Red
            $allRunning = $false
        }
    }
    
    Write-Host ""
    if ($allRunning) {
        Write-Host "  All services running!" -ForegroundColor Green
    } else {
        Write-Host "  Some services not running" -ForegroundColor Yellow
    }
} else {
    Write-Host "  No services found" -ForegroundColor Gray
}

Write-Host ""

# Step 10: Test Excel generation
if ($installSuccess) {
    Write-Host "Step 10: Testing Excel generation..." -ForegroundColor Yellow
    Write-Host "  Running test command..." -ForegroundColor Gray
    Write-Host ""

    try {
        php artisan reports:generate-excel --date=2026-01-08
        
        # Check if file exists
        $testFile = "storage\app\public\reports\excel\alerts_report_2026-01-08.xlsx"
        if (Test-Path $testFile) {
            Write-Host ""
            Write-Host "  Test file created: $testFile" -ForegroundColor Green
        }
    } catch {
        Write-Host "  Test failed: $($_.Exception.Message)" -ForegroundColor Red
    }
} else {
    Write-Host "Step 10: Skipping test (package not installed)" -ForegroundColor Yellow
}

Write-Host ""

# Summary
Write-Host "=== Installation Complete ===" -ForegroundColor Cyan
Write-Host ""

# Check final status
$servicesRunning = (Get-Service | Where-Object {$_.Name -like "Alert*" -and $_.Status -eq "Running"}).Count

Write-Host "Summary:" -ForegroundColor Yellow
if ($installSuccess) {
    Write-Host "  Package Installed: Yes" -ForegroundColor Green
} else {
    Write-Host "  Package Installed: No" -ForegroundColor Red
}
Write-Host "  Services Running: $servicesRunning" -ForegroundColor Green
Write-Host ""

if ($installSuccess) {
    Write-Host "Next steps:" -ForegroundColor Yellow
    Write-Host "1. Test Excel generation:" -ForegroundColor Gray
    Write-Host "   php artisan reports:generate-excel --date=2026-01-08" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "2. Setup scheduler (see EXCEL_REPORTS_SETUP.md)" -ForegroundColor Gray
    Write-Host ""
    Write-Host "3. Access portal: http://192.168.100.21:9000" -ForegroundColor Gray
} else {
    Write-Host "Package installation failed!" -ForegroundColor Red
    Write-Host ""
    Write-Host "Manual steps:" -ForegroundColor Yellow
    Write-Host "1. Check if vendor folders are locked" -ForegroundColor Gray
    Write-Host "2. Close any file explorers or editors" -ForegroundColor Gray
    Write-Host "3. Try: composer require phpoffice/phpspreadsheet" -ForegroundColor Gray
}

Write-Host ""
Write-Host "Press Enter to exit..."
Read-Host
