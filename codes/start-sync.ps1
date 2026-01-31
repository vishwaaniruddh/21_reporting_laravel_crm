# ============================================
# Start Alert Sync Process
# ============================================

Write-Host "=== Alert Sync Starter ===" -ForegroundColor Cyan
Write-Host ""

# Check MySQL connection
Write-Host "Checking MySQL connection..." -ForegroundColor Yellow
$mysqlTest = php artisan tinker --execute="try { DB::connection('mysql')->getPdo(); echo 'Connected'; } catch (Exception `$e) { echo 'Failed: ' . `$e->getMessage(); }" 2>&1

if ($mysqlTest -like "*Connected*") {
    Write-Host "OK MySQL is connected" -ForegroundColor Green
} else {
    Write-Host "ERROR MySQL is not connected" -ForegroundColor Red
    Write-Host ""
    Write-Host "Please start MySQL/WAMP services first:" -ForegroundColor Yellow
    Write-Host "  1. Open WAMP/XAMPP control panel" -ForegroundColor Gray
    Write-Host "  2. Start MySQL service" -ForegroundColor Gray
    Write-Host "  3. Run this script again" -ForegroundColor Gray
    Write-Host ""
    Write-Host "Or start MySQL service manually:" -ForegroundColor Yellow
    Write-Host "  net start wampmysqld64" -ForegroundColor Gray
    Write-Host "  or: net start mysql" -ForegroundColor Gray
    Write-Host ""
    Read-Host "Press Enter to exit"
    exit 1
}

# Check PostgreSQL connection
Write-Host "Checking PostgreSQL connection..." -ForegroundColor Yellow
$pgsqlTest = php artisan tinker --execute="try { DB::connection('pgsql')->getPdo(); echo 'Connected'; } catch (Exception `$e) { echo 'Failed: ' . `$e->getMessage(); }" 2>&1

if ($pgsqlTest -like "*Connected*") {
    Write-Host "OK PostgreSQL is connected" -ForegroundColor Green
} else {
    Write-Host "ERROR PostgreSQL is not connected" -ForegroundColor Red
    Write-Host ""
    Write-Host "Please start PostgreSQL service first" -ForegroundColor Yellow
    exit 1
}

Write-Host ""
Write-Host "=== Sync Status ===" -ForegroundColor Cyan
Write-Host ""

# Show current status
php artisan sync:partitioned --status

Write-Host ""
Write-Host "=== Start Sync Options ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "1. Run single batch - 10000 records" -ForegroundColor White
Write-Host "2. Run 10 batches - 100000 records" -ForegroundColor White
Write-Host "3. Run continuous sync - all records" -ForegroundColor White
Write-Host "4. Check status only" -ForegroundColor White
Write-Host "5. Exit" -ForegroundColor White
Write-Host ""

$choice = Read-Host "Select option (1-5)"

switch ($choice) {
    "1" {
        Write-Host ""
        Write-Host "Starting single batch sync..." -ForegroundColor Yellow
        php artisan sync:partitioned
    }
    "2" {
        Write-Host ""
        Write-Host "Starting 10-batch sync..." -ForegroundColor Yellow
        php artisan sync:partitioned --max-batches=10
    }
    "3" {
        Write-Host ""
        Write-Host "Starting continuous sync..." -ForegroundColor Yellow
        Write-Host "This will run until all records are synced." -ForegroundColor Gray
        Write-Host "Press Ctrl+C to stop at any time." -ForegroundColor Gray
        Write-Host ""
        php artisan sync:partitioned --continuous
    }
    "4" {
        Write-Host ""
        Write-Host "Status already shown above" -ForegroundColor Green
    }
    "5" {
        Write-Host ""
        Write-Host "Exiting..." -ForegroundColor Gray
        exit 0
    }
    default {
        Write-Host ""
        Write-Host "Invalid option" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "=== Done ===" -ForegroundColor Green
Write-Host ""
Read-Host "Press Enter to exit"
