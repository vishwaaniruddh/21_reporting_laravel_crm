# =====================================================
# Sites Update Sync Setup Script
# =====================================================
# This script sets up the complete sites update sync system
# =====================================================

Write-Host "=== Sites Update Sync Setup ===" -ForegroundColor Cyan
Write-Host ""

# Step 1: Create update log table
Write-Host "Step 1: Creating sites_pg_update_log table..." -ForegroundColor Yellow
mysql -u root -p -e "source codes/create-sites-update-log-table.sql"
if ($LASTEXITCODE -eq 0) {
    Write-Host "✓ Table created successfully" -ForegroundColor Green
} else {
    Write-Host "✗ Failed to create table" -ForegroundColor Red
    exit 1
}
Write-Host ""

# Step 2: Create triggers
Write-Host "Step 2: Creating MySQL triggers..." -ForegroundColor Yellow
mysql -u root -p -e "source codes/create-sites-triggers.sql"
if ($LASTEXITCODE -eq 0) {
    Write-Host "✓ Triggers created successfully" -ForegroundColor Green
} else {
    Write-Host "✗ Failed to create triggers" -ForegroundColor Red
    exit 1
}
Write-Host ""

# Step 3: Verify setup
Write-Host "Step 3: Verifying setup..." -ForegroundColor Yellow
Write-Host ""

Write-Host "Checking table structure:" -ForegroundColor Cyan
mysql -u root -p -e "DESCRIBE sites_pg_update_log;"
Write-Host ""

Write-Host "Checking triggers:" -ForegroundColor Cyan
mysql -u root -p -e "SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE IN ('sites', 'dvrsite', 'dvronline') ORDER BY EVENT_OBJECT_TABLE;"
Write-Host ""

# Step 4: Test trigger
Write-Host "Step 4: Testing trigger (updating a test record)..." -ForegroundColor Yellow
mysql -u root -p -e "UPDATE sites SET editby = 'test_trigger' WHERE SN = (SELECT SN FROM sites LIMIT 1);"
Write-Host ""

Write-Host "Checking if log entry was created:" -ForegroundColor Cyan
mysql -u root -p -e "SELECT * FROM sites_pg_update_log ORDER BY id DESC LIMIT 5;"
Write-Host ""

Write-Host "=== Setup Complete ===" -ForegroundColor Green
Write-Host ""
Write-Host "Next steps:" -ForegroundColor Cyan
Write-Host "1. Start the update sync worker:"
Write-Host "   php artisan sites:update-worker" -ForegroundColor White
Write-Host ""
Write-Host "2. Check worker status:"
Write-Host "   php codes/check-sites-sync-status.php" -ForegroundColor White
Write-Host ""
Write-Host "3. Monitor logs:"
Write-Host "   tail -f storage/logs/laravel.log" -ForegroundColor White
