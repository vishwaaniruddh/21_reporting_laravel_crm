# Manager Permissions Update - Complete

## Overview

Updated the permission system so that managers can only access Dashboard and Reports, while superadmins and admins have full access.

## Permission Changes

### New Permission Added
- **reports.view** - View Reports module

### Role Permissions

#### Superadmin
- ✅ users.create
- ✅ users.read
- ✅ users.update
- ✅ users.delete
- ✅ roles.read
- ✅ permissions.read
- ✅ permissions.assign
- ✅ dashboard.view
- ✅ reports.view

#### Admin
- ✅ users.create
- ✅ users.read
- ✅ users.update
- ✅ users.delete
- ✅ roles.read
- ✅ permissions.read
- ✅ permissions.assign
- ✅ dashboard.view
- ✅ reports.view

#### Manager (Updated)
- ✅ dashboard.view
- ✅ reports.view
- ❌ No access to Users Management
- ❌ No access to Table Management

## Navigation Visibility

### Superadmin/Admin See:
```
Dashboard
Users Management ▼
  ├─ Users
  ├─ Roles
  └─ Permissions
Table Management ▼
  ├─ Table Sync
  └─ Partitions
Reports ▼
  └─ Alerts Reports
```

### Manager Sees:
```
Dashboard
Reports ▼
  └─ Alerts Reports
```

## Files Modified

### Backend
1. **database/seeders/PermissionSeeder.php**
   - Added `reports.view` permission

2. **database/seeders/RolePermissionSeeder.php**
   - Updated manager permissions to only include:
     - dashboard.view
     - reports.view
   - Added reports.view to superadmin and admin

### Frontend
3. **resources/js/components/DashboardLayout.jsx**
   - Added permission checks to parent menus
   - Users Management requires `users.read`
   - Table Management requires `users.read`
   - Reports requires `reports.view`
   - Wrapped parent menus in RoleGuard

## How It Works

### Permission-Based Navigation
- Each parent menu has a `permission` property
- Parent menu is wrapped in `<RoleGuard>`
- If user doesn't have permission, entire menu is hidden
- Child items also have individual permission checks

### Example
```javascript
{
    name: 'Reports',
    icon: ReportsParentIcon,
    key: 'reports',
    isParent: true,
    permission: 'reports.view', // Manager has this
    children: [
        { 
            name: 'Alerts Reports', 
            href: '/alerts-reports', 
            icon: ReportsIcon, 
            permission: 'reports.view', 
            key: 'alerts-reports' 
        },
    ]
}
```

## Testing

### Test as Manager
1. Login as a manager user
2. Should see only:
   - Dashboard
   - Reports menu with Alerts Reports
3. Should NOT see:
   - Users Management
   - Table Management

### Test as Superadmin/Admin
1. Login as superadmin or admin
2. Should see all menus:
   - Dashboard
   - Users Management
   - Table Management
   - Reports

## Database Updates

Run these commands to apply the changes:
```bash
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=RolePermissionSeeder
```

## Verification

Check permissions in database:
```sql
-- Check manager permissions
SELECT p.name, p.display_name 
FROM permissions p
JOIN permission_role pr ON p.id = pr.permission_id
JOIN roles r ON pr.role_id = r.id
WHERE r.name = 'manager';

-- Should return:
-- dashboard.view | View Dashboard
-- reports.view   | View Reports
```

## Benefits

✅ **Restricted Access** - Managers can't access user management
✅ **Clear Separation** - Different roles have different capabilities
✅ **Secure** - Permission checks on both frontend and backend
✅ **Scalable** - Easy to add more permissions for managers
✅ **Flexible** - Can create custom roles with specific permissions

## Future Enhancements

### Easy to Add More Manager Permissions
To give managers access to more features:

1. Add permission to PermissionSeeder
2. Add permission to manager role in RolePermissionSeeder
3. Update navigation item permission
4. Run seeders

### Example: Give Managers Access to Table Sync
```php
// In RolePermissionSeeder.php
'manager' => [
    'dashboard.view',
    'reports.view',
    'table-sync.view', // NEW
],
```

```javascript
// In DashboardLayout.jsx
{
    name: 'Table Sync',
    href: '/table-sync',
    icon: SyncIcon,
    permission: 'table-sync.view', // NEW
    key: 'table-sync'
}
```

## Ready to Use

The permission system is now properly configured. Managers will only see Dashboard and Reports when they log in.
