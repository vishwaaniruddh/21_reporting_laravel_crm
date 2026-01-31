# Install PhpSpreadsheet Package - Quick Guide

## The Problem
Your Windows Services are running in the background and holding file locks on the vendor folder, preventing composer from installing new packages.

## The Solution
Use the provided script to safely stop services, install the package, and restart services.

---

## Step-by-Step Instructions

### 1. Open PowerShell as Administrator

**Right-click** on PowerShell and select **"Run as Administrator"**

### 2. Navigate to Project Directory

```powershell
cd C:\wamp64\www\comfort_reporting_crm\dual-database-app
```

### 3. Run the Installation Script

```powershell
.\install-package-safely.ps1
```

**What the script does:**
1. ✅ Checks if running as Administrator
2. ✅ Lists all Alert services
3. ✅ Stops all Alert services (releases file locks)
4. ✅ Waits 5 seconds for file handles to release
5. ✅ Clears composer cache
6. ✅ Installs phpoffice/phpspreadsheet
7. ✅ Verifies installation
8. ✅ Regenerates autoload files
9. ✅ Creates storage directory
10. ✅ Restarts all Alert services
11. ✅ Tests Excel generation

### 4. Verify Installation

The script will show a summary at the end:

```
=== Installation Complete ===

Summary:
  Package Installed: ✓ Yes
  Services Running: 4

Next steps:
1. Test Excel generation:
   php artisan reports:generate-excel --date=2026-01-08

2. Setup scheduler (see EXCEL_REPORTS_SETUP.md)

3. Access portal: http://192.168.100.21:9000
```

---

## Manual Method (If Script Fails)

If the script doesn't work, follow these manual steps:

### 1. Stop All Services

```powershell
# Stop all Alert services
Get-Service | Where-Object {$_.Name -like "Alert*"} | Stop-Service -Force

# Wait for file handles to release
Start-Sleep -Seconds 5
```

### 2. Install Package

```powershell
# Clear cache
composer clear-cache

# Install package
composer require phpoffice/phpspreadsheet
```

### 3. Restart Services

```powershell
# Start all Alert services
Get-Service | Where-Object {$_.Name -like "Alert*"} | Start-Service

# Verify they're running
Get-Service | Where-Object {$_.Name -like "Alert*"}
```

### 4. Test Installation

```powershell
# Verify package
composer show phpoffice/phpspreadsheet

# Test Excel generation
php artisan reports:generate-excel --date=2026-01-08
```

---

## Troubleshooting

### Issue: "Access Denied" or "Permission Denied"

**Solution**: Make sure you're running PowerShell as Administrator

```powershell
# Check if running as admin
([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

# Should return: True
```

### Issue: Services Won't Stop

**Solution**: Force stop using NSSM

```powershell
# Stop using NSSM
nssm stop AlertPortal
nssm stop AlertViteDev
nssm stop AlertInitialSync
nssm stop AlertUpdateSync

# Wait
Start-Sleep -Seconds 5

# Try installation again
composer require phpoffice/phpspreadsheet
```

### Issue: "File is locked" or "Cannot delete"

**Solution**: Close all file explorers and editors, then retry

```powershell
# Kill any PHP processes
Get-Process php -ErrorAction SilentlyContinue | Stop-Process -Force

# Kill any Node processes
Get-Process node -ErrorAction SilentlyContinue | Stop-Process -Force

# Wait
Start-Sleep -Seconds 5

# Try again
composer require phpoffice/phpspreadsheet
```

### Issue: Package Installs but "Class not found"

**Solution**: Regenerate autoload

```powershell
composer dump-autoload

# Test again
php artisan reports:generate-excel --date=2026-01-08
```

---

## Verification Checklist

After installation, verify everything is working:

- [ ] Package installed: `composer show phpoffice/phpspreadsheet`
- [ ] Services running: `Get-Service | Where-Object {$_.Name -like "Alert*"}`
- [ ] Portal accessible: http://192.168.100.21:9000
- [ ] Excel generation works: `php artisan reports:generate-excel --date=2026-01-08`
- [ ] Test file created: `Test-Path "storage\app\public\reports\excel\alerts_report_2026-01-08.xlsx"`

---

## Quick Commands Reference

```powershell
# Check services status
Get-Service | Where-Object {$_.Name -like "Alert*"}

# Stop all services
Get-Service | Where-Object {$_.Name -like "Alert*"} | Stop-Service -Force

# Start all services
Get-Service | Where-Object {$_.Name -like "Alert*"} | Start-Service

# Verify package
composer show phpoffice/phpspreadsheet

# Test Excel generation
php artisan reports:generate-excel --date=2026-01-08

# List generated reports
Get-ChildItem storage\app\public\reports\excel\
```

---

## After Installation

Once the package is installed successfully:

1. **Test Excel Generation**
   ```powershell
   php artisan reports:generate-excel --date=2026-01-08
   ```

2. **Setup Task Scheduler** (for automatic daily generation)
   - See `EXCEL_REPORTS_SETUP.md` for detailed instructions
   - Or run the quick command from `CURRENT_STATUS_AND_NEXT_STEPS.md`

3. **Integrate Frontend** (optional)
   - Add Excel download button to alerts-report page
   - See React component example in `EXCEL_REPORTS_SETUP.md`

---

## Support

If you encounter any issues:

1. Check logs: `Get-Content storage\logs\laravel.log -Tail 50`
2. Verify services: `Get-Service | Where-Object {$_.Name -like "Alert*"}`
3. Check file locks: Close all file explorers and editors
4. Restart computer if all else fails (services will auto-start)

---

**Ready to install?**

```powershell
# Run this command as Administrator:
.\install-package-safely.ps1
```
