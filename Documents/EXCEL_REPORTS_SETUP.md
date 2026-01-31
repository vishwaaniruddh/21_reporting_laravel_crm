# Excel Reports Setup Guide

## Overview
Automated Excel report generation for past dates. Reports are pre-generated at midnight and cached for download.

## Features
- ✅ Automatic generation for past dates only (not current date)
- ✅ Caching to avoid regeneration
- ✅ Download API endpoint
- ✅ Manual generation command
- ✅ Scheduled daily generation at midnight

## Installation Steps

### 1. Install PhpSpreadsheet Package

**IMPORTANT**: There may be Windows file locking issues during installation. Try these steps:

```powershell
# Method 1: Standard installation
composer require phpoffice/phpspreadsheet

# Method 2: If file locking occurs, close all PHP processes and retry
# Stop all services first
Get-Service | Where-Object {$_.Name -like "Alert*"} | Stop-Service
composer require phpoffice/phpspreadsheet
# Restart services after installation
Get-Service | Where-Object {$_.Name -like "Alert*"} | Start-Service

# Method 3: If still failing, try with --prefer-source
composer require phpoffice/phpspreadsheet --prefer-source

# Verify installation
composer show phpoffice/phpspreadsheet
```

### 2. Create Storage Directory

```powershell
# Create directory for Excel reports
New-Item -ItemType Directory -Path "storage\app\public\reports\excel" -Force

# Create symbolic link (if not exists)
php artisan storage:link
```

### 3. Test Manual Generation

```powershell
# Generate report for a specific past date
php artisan reports:generate-excel --date=2026-01-08

# Generate missing reports for last 7 days
php artisan reports:generate-excel

# Generate missing reports for last 30 days
php artisan reports:generate-excel --days=30
```

## API Endpoints

### Check if Excel Report Exists

```http
GET /api/alerts-reports/excel-check?date=2026-01-08&panel_type=comfort&customer=ABC
```

**Response:**
```json
{
  "success": true,
  "exists": true,
  "url": "http://192.168.100.21:9000/storage/reports/excel/alerts_report_2026-01-08_comfort_abc.xlsx",
  "date": "2026-01-08"
}
```

### Manually Generate Excel Report

```http
POST /api/alerts-reports/excel-generate
Content-Type: application/json

{
  "date": "2026-01-08",
  "panel_type": "comfort",
  "customer": "ABC Bank"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Excel report generated successfully",
  "url": "http://192.168.100.21:9000/storage/reports/excel/alerts_report_2026-01-08_comfort_abc_bank.xlsx",
  "date": "2026-01-08"
}
```

## Scheduled Generation

Reports are automatically generated daily at **12:05 AM** (5 minutes after midnight).

The scheduler checks for missing reports from the last 7 days and generates them.

### Check Scheduler Status

```powershell
# View scheduled tasks
php artisan schedule:list

# Test scheduler (runs all due tasks)
php artisan schedule:run

# Run scheduler continuously (for testing)
php artisan schedule:work
```

### Scheduler Configuration

Location: `routes/console.php`

```php
Schedule::call(function () {
    Artisan::call('reports:generate-excel', ['--days' => 7]);
})->dailyAt('00:05')
  ->name('reports:excel-daily')
  ->withoutOverlapping(60)
  ->onOneServer();
```

## Windows Service for Scheduler

To run the scheduler 24/7, you need a Windows Service:

### Option 1: Use Task Scheduler (Recommended)

```powershell
# Create a scheduled task that runs every minute
$action = New-ScheduledTaskAction -Execute "C:\wamp64\bin\php\php8.4.11\php.exe" -Argument "C:\wamp64\www\comfort_reporting_crm\dual-database-app\artisan schedule:run" -WorkingDirectory "C:\wamp64\www\comfort_reporting_crm\dual-database-app"

$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration ([TimeSpan]::MaxValue)

$principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest

Register-ScheduledTask -TaskName "AlertPortalScheduler" -Action $action -Trigger $trigger -Principal $principal -Description "Laravel Task Scheduler for Alert Portal"
```

### Option 2: Use NSSM Service (Alternative)

```powershell
# Create scheduler service
nssm install AlertScheduler "C:\wamp64\bin\php\php8.4.11\php.exe" "artisan schedule:work"
nssm set AlertScheduler AppDirectory "C:\wamp64\www\comfort_reporting_crm\dual-database-app"
nssm set AlertScheduler DisplayName "Alert Portal - Task Scheduler"
nssm set AlertScheduler Description "Runs Laravel task scheduler for automated Excel reports"
nssm set AlertScheduler Start SERVICE_AUTO_START
nssm set AlertScheduler AppStdout "C:\wamp64\www\comfort_reporting_crm\dual-database-app\storage\logs\scheduler.log"
nssm set AlertScheduler AppStderr "C:\wamp64\www\comfort_reporting_crm\dual-database-app\storage\logs\scheduler-error.log"
nssm start AlertScheduler
```

## Frontend Integration

### React Component Example

```jsx
import { useState, useEffect } from 'react';

function ExcelReportButton({ date, panelType, customer }) {
  const [reportUrl, setReportUrl] = useState(null);
  const [loading, setLoading] = useState(true);
  
  useEffect(() => {
    checkExcelReport();
  }, [date, panelType, customer]);
  
  const checkExcelReport = async () => {
    try {
      const params = new URLSearchParams({
        date,
        ...(panelType && { panel_type: panelType }),
        ...(customer && { customer })
      });
      
      const response = await fetch(`/api/alerts-reports/excel-check?${params}`);
      const data = await response.json();
      
      if (data.success && data.exists) {
        setReportUrl(data.url);
      }
    } catch (error) {
      console.error('Failed to check Excel report:', error);
    } finally {
      setLoading(false);
    }
  };
  
  const generateReport = async () => {
    setLoading(true);
    try {
      const response = await fetch('/api/alerts-reports/excel-generate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          date,
          ...(panelType && { panel_type: panelType }),
          ...(customer && { customer })
        })
      });
      
      const data = await response.json();
      
      if (data.success) {
        setReportUrl(data.url);
        alert('Excel report generated successfully!');
      }
    } catch (error) {
      console.error('Failed to generate Excel report:', error);
      alert('Failed to generate Excel report');
    } finally {
      setLoading(false);
    }
  };
  
  // Don't show button for current or future dates
  const isPastDate = new Date(date) < new Date().setHours(0, 0, 0, 0);
  
  if (!isPastDate) {
    return null;
  }
  
  if (loading) {
    return <button disabled>Checking...</button>;
  }
  
  if (reportUrl) {
    return (
      <a href={reportUrl} download className="btn btn-success">
        📥 Download Excel Report
      </a>
    );
  }
  
  return (
    <button onClick={generateReport} className="btn btn-primary">
      📊 Generate Excel Report
    </button>
  );
}

export default ExcelReportButton;
```

## File Structure

```
storage/
└── app/
    └── public/
        └── reports/
            └── excel/
                ├── alerts_report_2026-01-08.xlsx
                ├── alerts_report_2026-01-08_comfort.xlsx
                ├── alerts_report_2026-01-08_comfort_abc_bank.xlsx
                └── ...
```

## Filename Convention

- Base: `alerts_report_YYYY-MM-DD.xlsx`
- With panel type: `alerts_report_YYYY-MM-DD_paneltype.xlsx`
- With customer: `alerts_report_YYYY-MM-DD_paneltype_customer.xlsx`

## Excel Report Format

### Columns (22 total):
1. S.No (Serial Number)
2. Panel ID
3. Customer
4. Bank
5. ATMID
6. ATM Short Name
7. Site Address
8. DVR IP
9. Panel Make
10. Zone Name
11. City
12. State
13. Zone
14. Alarm
15. Alert Type
16. Create Time
17. Received Time
18. Closed Time
19. Closed By
20. Comment
21. Send IP
22. Aging (Hours)

### Features:
- ✅ Header row with blue background
- ✅ Auto-sized columns
- ✅ Borders on all cells
- ✅ Serial numbers starting from 1
- ✅ Enriched with sites data
- ✅ Calculated aging in hours

## Memory Optimization

The service uses chunking to handle large datasets:
- Processes 500 records at a time
- Maximum 50,000 records per report
- Memory limit: 512MB
- Frees memory after each chunk

## Troubleshooting

### Issue: "Class Spreadsheet not found"

**Solution**: PhpSpreadsheet not installed properly

```powershell
# Stop all services
Get-Service | Where-Object {$_.Name -like "Alert*"} | Stop-Service

# Clear composer cache
composer clear-cache

# Remove vendor folder and reinstall
Remove-Item -Recurse -Force vendor
composer install

# Restart services
Get-Service | Where-Object {$_.Name -like "Alert*"} | Start-Service
```

### Issue: "Memory exhausted"

**Solution**: Increase PHP memory limit

Edit `php.ini`:
```ini
memory_limit = 512M
```

Or set in command:
```powershell
php -d memory_limit=512M artisan reports:generate-excel --date=2026-01-08
```

### Issue: "Permission denied" on storage directory

**Solution**: Fix directory permissions

```powershell
# Grant full control to storage directory
icacls "storage" /grant "Everyone:(OI)(CI)F" /T
```

### Issue: Reports not generating automatically

**Solution**: Check scheduler service

```powershell
# Check if scheduler task exists
Get-ScheduledTask -TaskName "AlertPortalScheduler"

# Check task history
Get-ScheduledTask -TaskName "AlertPortalScheduler" | Get-ScheduledTaskInfo

# Manually run scheduler
php artisan schedule:run

# Check logs
Get-Content storage\logs\laravel.log -Tail 50
```

## Logs

All Excel generation activities are logged:

```powershell
# View recent logs
Get-Content storage\logs\laravel.log -Tail 100 | Select-String "Excel"

# View scheduler logs (if using NSSM)
Get-Content storage\logs\scheduler.log -Tail 50
```

## Testing Checklist

- [ ] PhpSpreadsheet package installed
- [ ] Storage directory created and writable
- [ ] Manual generation works: `php artisan reports:generate-excel --date=2026-01-08`
- [ ] API endpoint returns report URL
- [ ] Download link works in browser
- [ ] Scheduler task created
- [ ] Scheduler runs at midnight
- [ ] Reports cached (not regenerated)
- [ ] Frontend shows download button for past dates
- [ ] Frontend hides button for current/future dates

## Support

For issues or questions:
1. Check logs: `storage/logs/laravel.log`
2. Verify services: `Get-Service | Where-Object {$_.Name -like "Alert*"}`
3. Test manually: `php artisan reports:generate-excel --date=2026-01-08`
4. Check scheduler: `php artisan schedule:list`
