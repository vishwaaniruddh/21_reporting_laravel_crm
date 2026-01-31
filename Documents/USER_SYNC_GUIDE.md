# User Sync Between Servers Guide

## Overview
This guide explains how to synchronize users between two servers:
- **Server .21**: 192.168.100.21:9000 (16 users)
- **Server .23**: 192.168.100.23:9000 (12 users)

## Available Sync Scripts

### 1. One-Way Sync (.21 → .23)
**Script**: `sync_users_between_servers.php`  
**PowerShell**: `codes/sync-users-from-21-to-23.ps1`

Syncs all users from Server .21 to Server .23. This is the safest option when .21 is your primary server.

**Features**:
- Inserts missing users on .23
- Updates existing users on .23 with data from .21
- Syncs user roles
- Dry-run mode for preview

**Usage**:
```bash
# Preview changes (dry run)
php sync_users_between_servers.php

# Execute sync
php sync_users_between_servers.php --execute

# Or use PowerShell wrapper
.\codes\sync-users-from-21-to-23.ps1
```

### 2. Bidirectional Smart Sync
**Script**: `sync_users_bidirectional.php`  
**PowerShell**: `codes/sync-users-bidirectional.ps1`

Intelligently syncs users in both directions using the most recently updated record as the source of truth.

**Features**:
- Compares timestamps (updated_at/created_at)
- Syncs newer records to older ones
- Works in both directions
- Preserves most recent changes

**Usage**:
```bash
# Preview changes
php sync_users_bidirectional.php

# Execute sync
php sync_users_bidirectional.php --execute

# Or use PowerShell wrapper
.\codes\sync-users-bidirectional.ps1
```

### 3. Force Sync from .21 to .23
**Script**: `sync_users_bidirectional.php --force-from=21`  
**PowerShell**: `codes/force-sync-from-21.ps1`

Forces all users from .21 to .23, ignoring timestamps. Use when .21 is definitely the correct source.

**Usage**:
```bash
# Preview
php sync_users_bidirectional.php --force-from=21

# Execute (requires double confirmation)
php sync_users_bidirectional.php --force-from=21 --execute

# Or use PowerShell wrapper
.\codes\force-sync-from-21.ps1
```

### 4. Force Sync from .23 to .21
**Script**: `sync_users_bidirectional.php --force-from=23`  
**PowerShell**: `codes/force-sync-from-23.ps1`

Forces all users from .23 to .21, ignoring timestamps. Use when .23 is definitely the correct source.

**Usage**:
```bash
# Preview
php sync_users_bidirectional.php --force-from=23

# Execute (requires double confirmation)
php sync_users_bidirectional.php --force-from=23 --execute

# Or use PowerShell wrapper
.\codes\force-sync-from-23.ps1
```

## What Gets Synced

### User Table Fields
- `id` - User ID (preserved)
- `name` - Full name
- `email` - Email address
- `password` - Hashed password
- `is_active` - Active status
- `contact` - Phone number
- `profile_image` - Profile image path
- `bio` - Biography
- `dob` - Date of birth
- `gender` - Gender
- `created_at` - Creation timestamp
- `updated_at` - Last update timestamp
- `created_by` - Creator user ID

### User Roles
- `user_roles` pivot table
- Syncs role assignments for all synced users
- Preserves `assigned_by` and timestamps

## Recommended Workflow

### Initial Sync (First Time)
If Server .21 has 16 users and Server .23 has 12 users, and .21 is your primary:

1. **Preview the changes**:
   ```bash
   php sync_users_between_servers.php
   ```

2. **Review the output** - Check which users will be inserted/updated

3. **Execute the sync**:
   ```bash
   php sync_users_between_servers.php --execute
   ```

4. **Verify** - Check both servers have identical users

### Ongoing Sync (Regular Maintenance)
For keeping servers in sync after initial setup:

1. **Use bidirectional sync**:
   ```bash
   .\codes\sync-users-bidirectional.ps1
   ```

2. **Run weekly or after major user changes**

3. **Review the preview before executing**

### Emergency Sync (Data Recovery)
If one server has corrupted data:

1. **Identify the good server** (e.g., .21)

2. **Force sync from good to bad**:
   ```bash
   .\codes\force-sync-from-21.ps1
   ```

3. **Requires double confirmation** for safety

## Safety Features

### Dry Run Mode
All scripts default to dry-run mode:
- Shows what WOULD change
- No actual modifications
- Safe to run anytime

### Transaction Safety
- All changes wrapped in database transactions
- Automatic rollback on errors
- Data integrity preserved

### Confirmation Prompts
- PowerShell scripts ask for confirmation
- Force sync requires typing "FORCE SYNC"
- Prevents accidental overwrites

### Detailed Logging
- Shows every change being made
- Lists inserted/updated users
- Displays field-level changes

## Troubleshooting

### Connection Errors
**Error**: "Connection refused"
**Solution**: 
- Check PostgreSQL is running on both servers
- Verify firewall allows port 5432
- Test with: `psql -h 192.168.100.21 -U postgres -d sarmysdb`

### Authentication Errors
**Error**: "password authentication failed"
**Solution**:
- Verify password in script matches PostgreSQL
- Check `pg_hba.conf` allows connections from your IP
- Ensure user has proper permissions

### Duplicate Key Errors
**Error**: "duplicate key value violates unique constraint"
**Solution**:
- User IDs conflict between servers
- Use force sync to overwrite
- Or manually resolve conflicts

### Role Sync Errors
**Error**: "foreign key violation"
**Solution**:
- Ensure roles exist on target server
- Run role seeder first: `php artisan db:seed --class=RoleSeeder`
- Then retry user sync

## Database Configuration

The scripts use these connection details:

```php
// Server .21
$server21 = [
    'host' => '192.168.100.21',
    'port' => '5432',
    'database' => 'sarmysdb',
    'username' => 'postgres',
    'password' => 'Hitesh@123',
];

// Server .23
$server23 = [
    'host' => '192.168.100.23',
    'port' => '5432',
    'database' => 'sarmysdb',
    'username' => 'postgres',
    'password' => 'Hitesh@123',
];
```

To change these, edit the scripts directly.

## Scheduling Automatic Sync

### Windows Task Scheduler
Create a scheduled task to run bidirectional sync daily:

1. Open Task Scheduler
2. Create Basic Task
3. Name: "User Sync Between Servers"
4. Trigger: Daily at 2:00 AM
5. Action: Start a program
6. Program: `powershell.exe`
7. Arguments: `-File "C:\path\to\codes\sync-users-bidirectional.ps1" -NonInteractive`

### Manual Cron (if using Linux)
```bash
# Add to crontab
0 2 * * * cd /path/to/project && php sync_users_bidirectional.php --execute >> /var/log/user-sync.log 2>&1
```

## Verification

After syncing, verify both servers have identical users:

```bash
# On Server .21
psql -h 192.168.100.21 -U postgres -d sarmysdb -c "SELECT COUNT(*) FROM users;"

# On Server .23
psql -h 192.168.100.23 -U postgres -d sarmysdb -c "SELECT COUNT(*) FROM users;"
```

Both should return the same count.

## Best Practices

1. **Always preview first** - Run dry-run before executing
2. **Backup before sync** - Take database backups
3. **Test on staging** - Test scripts on non-production first
4. **Monitor logs** - Check Laravel logs for errors
5. **Verify after sync** - Confirm user counts match
6. **Document changes** - Keep track of when syncs run
7. **Use bidirectional** - For ongoing maintenance
8. **Use force sync** - Only when absolutely necessary

## Example Output

### Dry Run Output
```
===========================================
User Sync Script
===========================================
Source: 192.168.100.21 (sarmysdb)
Target: 192.168.100.23 (sarmysdb)
Mode: DRY RUN (preview only)
===========================================

Testing connections...
✓ Source server connected
✓ Target server connected

Fetching users from source server...
Found 16 users on source server

Fetching users from target server...
Found 12 users on target server

===========================================
SYNC SUMMARY
===========================================
Users to INSERT: 4
Users to UPDATE: 2
Users UNCHANGED: 10
===========================================

USERS TO INSERT:
-------------------------------------------
ID 13: John Doe (john@example.com)
ID 14: Jane Smith (jane@example.com)
ID 15: Bob Wilson (bob@example.com)
ID 16: Alice Brown (alice@example.com)

USERS TO UPDATE:
-------------------------------------------
ID 5: Mike Johnson (mike@example.com)
  - contact: '555-1234' → '555-5678'
  - profile_image: [changed]
ID 8: Sarah Davis (sarah@example.com)
  - name: 'Sara Davis' → 'Sarah Davis'
  - is_active: false → true

===========================================
DRY RUN COMPLETE
===========================================
No changes were made to the target server.
Run with --execute flag to apply changes:
  php sync_users_between_servers.php --execute
===========================================
```

## Support

If you encounter issues:
1. Check the troubleshooting section above
2. Review Laravel logs: `storage/logs/laravel.log`
3. Test database connections manually
4. Verify PostgreSQL is running on both servers
5. Check network connectivity between servers

## Files Created

- `sync_users_between_servers.php` - One-way sync script
- `sync_users_bidirectional.php` - Bidirectional sync script
- `codes/sync-users-from-21-to-23.ps1` - PowerShell wrapper for one-way
- `codes/sync-users-bidirectional.ps1` - PowerShell wrapper for bidirectional
- `codes/force-sync-from-21.ps1` - Force sync from .21 to .23
- `codes/force-sync-from-23.ps1` - Force sync from .23 to .21
- `USER_SYNC_GUIDE.md` - This guide

## Quick Reference

| Task | Command |
|------|---------|
| Preview one-way sync | `php sync_users_between_servers.php` |
| Execute one-way sync | `php sync_users_between_servers.php --execute` |
| Preview bidirectional | `php sync_users_bidirectional.php` |
| Execute bidirectional | `php sync_users_bidirectional.php --execute` |
| Force from .21 to .23 | `php sync_users_bidirectional.php --force-from=21 --execute` |
| Force from .23 to .21 | `php sync_users_bidirectional.php --force-from=23 --execute` |
| PowerShell one-way | `.\codes\sync-users-from-21-to-23.ps1` |
| PowerShell bidirectional | `.\codes\sync-users-bidirectional.ps1` |
| PowerShell force .21→.23 | `.\codes\force-sync-from-21.ps1` |
| PowerShell force .23→.21 | `.\codes\force-sync-from-23.ps1` |
