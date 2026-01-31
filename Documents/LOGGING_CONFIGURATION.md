# Logging Configuration Guide

## Overview

The Laravel application has been configured to reduce excessive logging and only write to logs when tasks fail or errors occur.

## Changes Made

### 1. Log Level Configuration

**File:** `.env`

Changed the log level from `debug` to `error`:

```env
LOG_LEVEL=error
```

This means only the following will be logged:
- **ERROR** - Errors that occurred
- **CRITICAL** - Critical failures
- **ALERT** - Alerts requiring immediate attention
- **EMERGENCY** - System is unusable

The following will NOT be logged:
- **WARNING** - Warnings about potential issues
- **INFO** - Informational messages (like successful operations)
- **DEBUG** - Detailed debug information

### 2. SyncLogger Service Updates

**File:** `app/Services/SyncLogger.php`

Modified logging behavior:

#### Cycle Start Logging
- Changed from `Log::info()` to `Log::debug()`
- Only logs if there are pending items
- Will not appear in logs with `LOG_LEVEL=error`

#### Cycle Complete Logging
- Only logs if there were **failures**
- Changed to `Log::warning()` when failures occur
- Will not appear in logs with `LOG_LEVEL=error` (warnings are suppressed)
- Silent when all operations succeed

#### Alert Sync Logging
- Only logs **failures**, not successes
- Uses `Log::error()` for failed syncs
- Successful syncs are not logged

#### Info Messages
- Changed from `Log::info()` to `Log::debug()`
- Will not appear in logs with `LOG_LEVEL=error`

## Log File Management

### Current Log File Size

The `storage/logs/laravel.log` file was approximately **2.6 GB** due to excessive INFO and DEBUG logging.

### Cleanup Script

Use the cleanup script to backup and clear the log file:

```powershell
.\codes\cleanup-laravel-log.ps1
```

This script will:
1. Show the current log file size
2. Create a timestamped backup
3. Clear the log file to start fresh

### Automatic Log Rotation

Consider using Laravel's daily log rotation:

**File:** `.env`

```env
LOG_CHANNEL=daily
LOG_DAILY_DAYS=7
```

This will:
- Create a new log file each day
- Keep logs for 7 days
- Automatically delete old logs

## What Gets Logged Now

### ✅ Will Be Logged

1. **Sync Failures**
   - Alert sync errors with alert ID and error message
   - Database errors
   - Transaction rollbacks

2. **Database Errors**
   - Connection failures
   - Query errors
   - Transaction rollbacks

3. **Service Errors**
   - Partition creation failures
   - Lock acquisition failures
   - Configuration errors

4. **Critical Issues**
   - System failures
   - Emergency situations

### ❌ Will NOT Be Logged

1. **Successful Operations**
   - Individual alert syncs that succeed
   - Cycle completions (even with failures)
   - Partition table creations
   - Configuration updates

2. **Warnings**
   - Retry attempts
   - Threshold warnings
   - Resource warnings

3. **Info Messages**
   - "Sync started" messages
   - "Processing batch" messages
   - "Operation completed" messages

4. **Debug Information**
   - Detailed operation traces
   - Variable dumps
   - Step-by-step progress

## Monitoring

### Check for Errors

To see recent errors:

```powershell
Get-Content storage/logs/laravel.log -Tail 50
```

### Check for Warnings

```powershell
Get-Content storage/logs/laravel.log | Select-String "WARNING"
```

### Check for Specific Service Errors

```powershell
Get-Content storage/logs/laravel.log | Select-String "Alert sync failed"
```

## Service-Specific Logs

Each service still has its own log file with detailed information:

- `storage/logs/initial-sync-service.log` - Initial sync operations
- `storage/logs/update-sync-service.log` - Update sync operations
- `storage/logs/cleanup-service.log` - Cleanup operations
- `storage/logs/mysql-backup-service.log` - Backup operations
- `storage/logs/portal-service.log` - Portal server logs
- `storage/logs/vite-service.log` - Vite dev server logs

These service-specific logs are managed by the services themselves and are not affected by the Laravel log level.

## Reverting Changes

If you need more detailed logging for debugging:

**File:** `.env`

```env
LOG_LEVEL=debug
```

Then restart all services:

```powershell
.\codes\recreate-initial-sync-service.ps1
```

## Best Practices

1. **Production**: Use `LOG_LEVEL=error` (only errors and critical issues)
2. **Development**: Use `LOG_LEVEL=debug` for detailed information
3. **Staging**: Use `LOG_LEVEL=warning` for moderate logging
4. **Troubleshooting**: Temporarily use `LOG_LEVEL=info` or `LOG_LEVEL=debug`

## Log File Locations

- **Laravel Log**: `storage/logs/laravel.log`
- **Service Logs**: `storage/logs/*-service.log`
- **Error Logs**: `storage/logs/*-error.log`

## Backup Logs

Backups created by the cleanup script are stored in:

```
storage/logs/laravel.log.backup.YYYY-MM-DD_HH-mm-ss
```

These can be deleted after verification that the system is working correctly.

## Summary

The logging configuration has been optimized to:
- ✅ Reduce log file size dramatically
- ✅ Only log errors and critical failures
- ✅ Suppress warnings, info, and debug messages
- ✅ Maintain service-specific detailed logs
- ✅ Keep the system performant
- ✅ Make troubleshooting easier by reducing noise

With these changes, the `laravel.log` file should remain very small and only contain critical error information.
