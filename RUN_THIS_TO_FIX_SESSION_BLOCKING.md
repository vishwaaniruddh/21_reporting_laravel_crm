# 🚨 FIX SESSION BLOCKING ISSUE

## Problem
Downloads block the entire portal. Other pages don't load until download completes.

## Solution
Run this command:

```powershell
.\codes\fix-session-blocking.ps1
```

## What It Does
- Changes session driver from `database` to `file`
- Clears configuration cache
- Creates sessions directory
- Sets proper permissions

## After Running
- All users will be logged out (one-time)
- Users need to log in again
- Downloads will NO LONGER block the portal
- Portal will remain responsive during downloads

## Test It
1. Start a download
2. Navigate to another page
3. Page should load immediately (not wait for download)

---

**Time to fix**: < 1 minute
**Impact**: Portal works normally during downloads
**Side effect**: Users need to log in again (one-time)
