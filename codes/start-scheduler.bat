@echo off
echo Starting Laravel Task Scheduler...
echo This will run the scheduled tasks every minute.
echo Press Ctrl+C to stop.
echo.

:loop
php artisan schedule:run
timeout /t 60 /nobreak >nul
goto loop
