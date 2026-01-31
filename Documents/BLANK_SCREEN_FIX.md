# Blank Screen Fix - CORS Issue Resolved

## Problem
Portal showing blank screen with CORS errors in browser console.

## Root Cause
1. `APP_URL` in `.env` was set to `http://localhost:9000`
2. Vite config was using `127.0.0.1` instead of network IP
3. Browser accessing from `http://192.168.100.21:9000` causing CORS mismatch

## Solution Applied

### 1. Updated `.env` File
```env
APP_URL=http://192.168.100.21:9000
ASSET_URL=http://192.168.100.21:9000
```

### 2. Updated `vite.config.js`
```javascript
server: {
    host: '0.0.0.0', // Listen on all network interfaces
    port: 5173,
    strictPort: true,
    hmr: {
        host: '192.168.100.21', // Use the actual network IP
    },
    cors: true, // Enable CORS
},
```

### 3. Restarted Services
```powershell
Restart-Service AlertPortal
Restart-Service AlertViteDev
```

## Verification

### Check Services
```powershell
Get-Service AlertPortal, AlertViteDev
```

Both should show `Running`.

### Access Portal
Open browser: `http://192.168.100.21:9000`

### Clear Browser Cache
If still showing blank:
1. Press `Ctrl+Shift+Delete`
2. Clear cached images and files
3. Or open in incognito/private window

## Quick Fix Commands

If blank screen appears again:

```powershell
# Restart both services
Restart-Service AlertPortal, AlertViteDev

# Wait a few seconds
Start-Sleep -Seconds 5

# Verify running
Get-Service | Where-Object {$_.Name -like "Alert*"}
```

## Prevention

Always use the network IP (`192.168.100.21`) instead of `localhost` when:
- Setting `APP_URL` in `.env`
- Configuring Vite server
- Accessing the portal from other devices

## Files Modified
- `.env` - Updated APP_URL and added ASSET_URL
- `vite.config.js` - Updated server host and HMR settings

## Status
✅ **FIXED** - Portal should now load correctly at http://192.168.100.21:9000
