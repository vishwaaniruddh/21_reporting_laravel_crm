# Install PhpSpreadsheet Package
# This script helps install PhpSpreadsheet by stopping services first

Write-Host "=== PhpSpreadsheet Installation Script ===" -ForegroundColor Cyan
Write-Host ""

# Check if running as administrator
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isAdmin) {
    Write-Host "WARNING: Not running as administrator. Service stop may fail." -ForegroundColor Yellow
    Write-Host "Consider running PowerShell as Administrator." -ForegroundColor Yellow
    Write-Host ""
}

# Step 1: Stop all Alert services
Write-Host "Step 1: Stopping Alert services..." -ForegroundColor Yellow
$services = Get-Service | Where-Object {$_.Name -like "Alert*"}

if ($services) {
    foreach ($service in $services) {
        Write-Host "  Stopping $($service.Name)..." -ForegroundColor Gray
        try {
            Stop-Service -Name $service.Name -Force -ErrorAction Stop
            Write-Host "  ✓ $($service.Name) stopped" -ForegroundColor Green
        } catch {
            Write-Host "  ✗ Failed to stop $($service.Name): $($_.Exception.Message)" -ForegroundColor Red
        }
    }
} else {
    Write-Host "  No Alert services found" -ForegroundColor Gray
}

Write-Host ""

# Step 2: Clear composer cache
Write-Host "Step 2: Clearing composer cache..." -ForegroundColor Yellow
try {
    composer clear-cache 2>&1 | Out-Null
    Write-Host "  ✓ Composer cache cleared" -ForegroundColor Green
} catch {
    Write-Host "  ✗ Failed to clear cache: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host ""

# Step 3: Install PhpSpreadsheet
Write-Host "Step 3: Installing PhpSpreadsheet..." -ForegroundColor Yellow
Write-Host "  This may take a few minutes..." -ForegroundColor Gray
Write-Host ""

try {
    # Try standard installation first
    $output = composer require phpoffice/phpspreadsheet --no-interaction 2>&1
    
    if ($LASTEXITCODE -eq 0) {
        Write-Host "  ✓ PhpSpreadsheet installed successfully!" -ForegroundColor Green
    } else {
        Write-Host "  ✗ Installation failed with standard method" -ForegroundColor Red
        Write-Host "  Trying with --prefer-source..." -ForegroundColor Yellow
        
        # Try with prefer-source
        $output = composer require phpoffice/phpspreadsheet --prefer-source --no-interaction 2>&1
        
        if ($LASTEXITCODE -eq 0) {
            Write-Host "  ✓ PhpSpreadsheet installed successfully with --prefer-source!" -ForegroundColor Green
        } else {
            Write-Host "  ✗ Installation failed" -ForegroundColor Red
            Write-Host ""
            Write-Host "Manual steps required:" -ForegroundColor Yellow
            Write-Host "1. Close all PHP processes and file explorers" -ForegroundColor Gray
            Write-Host "2. Run: composer require phpoffice/phpspreadsheet" -ForegroundColor Gray
            Write-Host "3. If still failing, delete vendor folder and run: composer install" -ForegroundColor Gray
        }
    }
} catch {
    Write-Host "  ✗ Installation error: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host ""

# Step 4: Verify installation
Write-Host "Step 4: Verifying installation..." -ForegroundColor Yellow
try {
    $verifyOutput = composer show phpoffice/phpspreadsheet 2>&1
    
    if ($LASTEXITCODE -eq 0) {
        Write-Host "  ✓ PhpSpreadsheet is installed" -ForegroundColor Green
        Write-Host ""
        Write-Host $verifyOutput
    } else {
        Write-Host "  ✗ PhpSpreadsheet not found" -ForegroundColor Red
    }
} catch {
    Write-Host "  ✗ Verification failed: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host ""

# Step 5: Create storage directory
Write-Host "Step 5: Creating storage directory..." -ForegroundColor Yellow
$storageDir = "storage\app\public\reports\excel"

if (Test-Path $storageDir) {
    Write-Host "  ✓ Directory already exists: $storageDir" -ForegroundColor Green
} else {
    try {
        New-Item -ItemType Directory -Path $storageDir -Force | Out-Null
        Write-Host "  ✓ Directory created: $storageDir" -ForegroundColor Green
    } catch {
        Write-Host "  ✗ Failed to create directory: $($_.Exception.Message)" -ForegroundColor Red
    }
}

Write-Host ""

# Step 6: Create storage link
Write-Host "Step 6: Creating storage link..." -ForegroundColor Yellow
try {
    $linkOutput = php artisan storage:link 2>&1
    Write-Host "  ✓ Storage link created" -ForegroundColor Green
} catch {
    Write-Host "  ⚠ Storage link may already exist or failed: $($_.Exception.Message)" -ForegroundColor Yellow
}

Write-Host ""

# Step 7: Restart services
Write-Host "Step 7: Restarting Alert services..." -ForegroundColor Yellow
$services = Get-Service | Where-Object {$_.Name -like "Alert*"}

if ($services) {
    foreach ($service in $services) {
        Write-Host "  Starting $($service.Name)..." -ForegroundColor Gray
        try {
            Start-Service -Name $service.Name -ErrorAction Stop
            Write-Host "  ✓ $($service.Name) started" -ForegroundColor Green
        } catch {
            Write-Host "  ✗ Failed to start $($service.Name): $($_.Exception.Message)" -ForegroundColor Red
        }
    }
} else {
    Write-Host "  No Alert services found" -ForegroundColor Gray
}

Write-Host ""

# Step 8: Test Excel generation
Write-Host "Step 8: Testing Excel generation..." -ForegroundColor Yellow
Write-Host "  Run this command to test:" -ForegroundColor Gray
Write-Host "  php artisan reports:generate-excel --date=2026-01-08" -ForegroundColor Cyan
Write-Host ""

# Summary
Write-Host "=== Installation Complete ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Next steps:" -ForegroundColor Yellow
Write-Host "1. Test manual generation: php artisan reports:generate-excel --date=2026-01-08" -ForegroundColor Gray
Write-Host "2. Setup scheduler: See EXCEL_REPORTS_SETUP.md" -ForegroundColor Gray
Write-Host "3. Integrate frontend: See EXCEL_REPORTS_SETUP.md" -ForegroundColor Gray
Write-Host ""
Write-Host "Documentation:" -ForegroundColor Yellow
Write-Host "- EXCEL_REPORTS_SETUP.md - Complete setup guide" -ForegroundColor Gray
Write-Host "- TASK_16_EXCEL_REPORTS_SUMMARY.md - Implementation summary" -ForegroundColor Gray
Write-Host ""

# Pause
Write-Host "Press Enter to exit..." -ForegroundColor Gray
Read-Host
