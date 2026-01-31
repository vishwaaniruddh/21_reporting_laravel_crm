@echo off
cd /d C:\wamp64\www\comfort_reporting_crm\dual-database-app
C:\wamp64\bin\php\php8.2.13\php.exe artisan queue:work --sleep=3 --tries=3 --max-time=3600
