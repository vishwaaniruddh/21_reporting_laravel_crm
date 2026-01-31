# Laravel Logging Levels Reference

## Log Levels (from most to least severe)

| Level | Value | Description | When to Use |
|-------|-------|-------------|-------------|
| **EMERGENCY** | 600 | System is unusable | System-wide failures |
| **ALERT** | 550 | Action must be taken immediately | Critical alerts requiring immediate attention |
| **CRITICAL** | 500 | Critical conditions | Critical component failures |
| **ERROR** | 400 | Runtime errors | Errors that don't require immediate action |
| **WARNING** | 300 | Exceptional occurrences that are not errors | Deprecated APIs, poor use of APIs |
| **NOTICE** | 250 | Normal but significant events | - |
| **INFO** | 200 | Interesting events | User logs in, SQL logs |
| **DEBUG** | 100 | Detailed debug information | Variable dumps, step-by-step traces |

## Current Configuration

```env
LOG_LEVEL=error
```

### What Gets Logged

✅ **EMERGENCY** (600)
✅ **ALERT** (550)
✅ **CRITICAL** (500)
✅ **ERROR** (400)
❌ **WARNING** (300) - Suppressed
❌ **NOTICE** (250) - Suppressed
❌ **INFO** (200) - Suppressed
❌ **DEBUG** (100) - Suppressed

## Common Scenarios

### Production (Current)
```env
LOG_LEVEL=error
```
- Minimal logging
- Only errors and critical issues
- Smallest log files
- Best performance

### Staging
```env
LOG_LEVEL=warning
```
- Moderate logging
- Errors and warnings
- Good for catching issues before production

### Development
```env
LOG_LEVEL=debug
```
- Maximum logging
- All messages including debug traces
- Useful for troubleshooting

### Troubleshooting Production Issues
```env
LOG_LEVEL=info
```
- Temporarily enable for debugging
- See successful operations
- Remember to change back to `error` after troubleshooting

## Quick Commands

### Check Current Log Level
```powershell
Get-Content .env | Select-String "LOG_LEVEL"
```

### View Recent Errors Only
```powershell
Get-Content storage/logs/laravel.log -Tail 100 | Select-String "ERROR"
```

### Count Log Entries by Level
```powershell
Get-Content storage/logs/laravel.log | Select-String "local\.(ERROR|WARNING|INFO|DEBUG)" | Group-Object | Select-Object Count, Name
```

### Check Log File Size
```powershell
Get-Item storage/logs/laravel.log | Select-Object Name, @{Name="Size(MB)";Expression={[math]::Round($_.Length/1MB, 2)}}
```

## Changing Log Level

1. Edit `.env` file:
   ```env
   LOG_LEVEL=error
   ```

2. Restart all services:
   ```powershell
   Restart-Service AlertInitialSync
   Restart-Service AlertUpdateSync
   Restart-Service AlertCleanup
   Restart-Service AlertMysqlBackup
   Restart-Service AlertPortal
   Restart-Service AlertViteDev
   ```

3. Or use Service Management UI:
   http://192.168.100.21:9000/services

## Log File Locations

- **Main Log**: `storage/logs/laravel.log`
- **Service Logs**: `storage/logs/*-service.log`
- **Error Logs**: `storage/logs/*-error.log`

## Recommendations

| Environment | Recommended Level | Reason |
|-------------|------------------|---------|
| Production | `error` | Minimal logging, best performance |
| Staging | `warning` | Catch potential issues |
| Development | `debug` | Full visibility for debugging |
| Troubleshooting | `info` | Temporary detailed logging |

## Notes

- Lower log levels include all higher severity levels
- `LOG_LEVEL=error` logs: ERROR, CRITICAL, ALERT, EMERGENCY
- `LOG_LEVEL=warning` logs: WARNING, ERROR, CRITICAL, ALERT, EMERGENCY
- `LOG_LEVEL=info` logs: INFO, WARNING, ERROR, CRITICAL, ALERT, EMERGENCY
- `LOG_LEVEL=debug` logs: Everything

## Current Setup Summary

With `LOG_LEVEL=error`:
- ✅ Only errors and critical failures are logged
- ❌ No warnings, info, or debug messages
- 📉 Minimal log file growth
- ⚡ Best performance
- 🎯 Only critical issues visible
