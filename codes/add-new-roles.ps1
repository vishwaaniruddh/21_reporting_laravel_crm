# Add New Roles: Team Leader and Surveillance Team
# This script seeds the new roles into the database

Write-Host "============================================" -ForegroundColor Cyan
Write-Host "Adding New Roles" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Roles to be added:" -ForegroundColor Yellow
Write-Host "  1. Team Leader - Access to Dashboards and Reports" -ForegroundColor White
Write-Host "  2. Surveillance Team - Access to Dashboards and Reports" -ForegroundColor White
Write-Host ""

# Run the seeder script
Write-Host "Running seeder..." -ForegroundColor Yellow
php codes/seed-new-roles.php

if ($LASTEXITCODE -eq 0) {
    Write-Host ""
    Write-Host "============================================" -ForegroundColor Green
    Write-Host "Success!" -ForegroundColor Green
    Write-Host "============================================" -ForegroundColor Green
    Write-Host ""
    Write-Host "New roles have been added to the database." -ForegroundColor Green
    Write-Host ""
    Write-Host "You can now assign these roles to users:" -ForegroundColor Cyan
    Write-Host "  - Team Leader" -ForegroundColor White
    Write-Host "  - Surveillance Team" -ForegroundColor White
    Write-Host ""
    Write-Host "Both roles have access to:" -ForegroundColor Cyan
    Write-Host "  - Dashboard" -ForegroundColor White
    Write-Host "  - Reports" -ForegroundColor White
} else {
    Write-Host ""
    Write-Host "============================================" -ForegroundColor Red
    Write-Host "Error!" -ForegroundColor Red
    Write-Host "============================================" -ForegroundColor Red
    Write-Host ""
    Write-Host "Failed to add roles. Check the error messages above." -ForegroundColor Red
}

Write-Host ""
Write-Host "Press any key to exit..."
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
