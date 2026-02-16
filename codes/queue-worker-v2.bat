@echo off
REM Queue Worker V2 - Redis-based (Testing)
REM This worker processes exports from the Redis queue
REM Separate from V1 database queue worker

cd /d C:\wamp64\www\comfort_reporting_crm\dual-database-app
C:\wamp64\bin\php\php8.4.11\php.exe artisan queue:work redis --queue=exports-v2 --sleep=3 --tries=3 --max-time=3600
