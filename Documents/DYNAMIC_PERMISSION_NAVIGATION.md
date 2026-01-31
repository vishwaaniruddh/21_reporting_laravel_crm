# Dynamic Permission-Based Navigation

## Overview
The navigation system now dynamically reflects whatever permissions the superadmin assigns to any role. Menu items automatically show/hide based on the user's actual permissions.

## How It Works

### Single Permission (AND Logic)
For menu items that require ONE specific permission:
```javascript
{
    name: 'Dashboard',
    href: '/dashboard',
    icon: DashboardIcon,
    permission: 'dashboard.view', // User MUST have this permission
    key: 'dashboard'
}
```

### Multiple Permissions (OR Logic)
For parent menus that should show if user has ANY of the listed permissions:
```javascript
{
    name: 'Table Management',
    icon: TableManagementIcon,
    key: 'table-management',
    isParent: true,
    anyPermissions: ['table-sync.view', 'partitions.view'], // User needs ANY of these
    children: [
        { name: 'Table Sync', permission: 'table-sync.view', ... },
        { name: 'Partitions', permission: 'partitions.view', ... },
    ]
}
```

## Current Navigation Configuration

### Dashboard
- **Permission**: `dashboard.view`
- **Shows for**: All authenticated users with dashboard.view

### Users Management (Parent Menu)
- **Permission**: `users.read`
- **Shows for**: Superadmin, Admin
- **Children**:
  - Users: `users.read`
  - Roles: `roles.read`
  - Permissions: `permissions.read`

### Table Management (Parent Menu) ⭐ DYNAMIC
- **Any Permissions**: `table-sync.view` OR `partitions.view`
- **Shows for**: Anyone with either permission
- **Children**:
  - Table Sync: `table-sync.view` (only shows if user has this)
  - Partitions: `partitions.view` (only shows if user has this)

### Reports (Parent Menu)
- **Permission**: `reports.view`
- **Shows for**: All users with reports.view
- **Children**:
  - Alerts Reports: `reports.view`

## Example Scenarios

### Scenario 1: Manager with Only Reports
**Permissions**: `dashboard.view`, `reports.view`

**Sees**:
- ✅ Dashboard
- ❌ Users Management (no users.read)
- ❌ Table Management (no table-sync.view or partitions.view)
- ✅ Reports
  - ✅ Alerts Reports

### Scenario 2: Manager with Partitions Access
**Permissions**: `dashboard.view`, `reports.view`, `partitions.view`

**Sees**:
- ✅ Dashboard
- ❌ Users Management (no users.read)
- ✅ Table Management (has partitions.view)
  - ❌ Table Sync (no table-sync.view)
  - ✅ Partitions (has partitions.view)
- ✅ Reports
  - ✅ Alerts Reports

### Scenario 3: Manager with Table Sync Access
**Permissions**: `dashboard.view`, `reports.view`, `table-sync.view`

**Sees**:
- ✅ Dashboard
- ❌ Users Management (no users.read)
- ✅ Table Management (has table-sync.view)
  - ✅ Table Sync (has table-sync.view)
  - ❌ Partitions (no partitions.view)
- ✅ Reports
  - ✅ Alerts Reports

### Scenario 4: Manager with Both Table Sync and Partitions
**Permissions**: `dashboard.view`, `reports.view`, `table-sync.view`, `partitions.view`

**Sees**:
- ✅ Dashboard
- ❌ Users Management (no users.read)
- ✅ Table Management (has both permissions)
  - ✅ Table Sync (has table-sync.view)
  - ✅ Partitions (has partitions.view)
- ✅ Reports
  - ✅ Alerts Reports

### Scenario 5: Superadmin/Admin
**Permissions**: All 13 permissions

**Sees**:
- ✅ Dashboard
- ✅ Users Management
  - ✅ Users
  - ✅ Roles
  - ✅ Permissions
- ✅ Table Management
  - ✅ Table Sync
  - ✅ Partitions
- ✅ Reports
  - ✅ Alerts Reports

## How to Assign Permissions Dynamically

### Method 1: Using Roles Page (Recommended)
1. Login as Superadmin
2. Navigate to **Roles** page
3. Click **Edit** on any role (Manager, Admin, etc.)
4. Check/uncheck the permissions you want
5. Click **Save**
6. User logs out and logs back in
7. Navigation automatically updates!

### Method 2: Using Database Seeder
Edit `database/seeders/RolePermissionSeeder.php`:
```php
'manager' => [
    'dashboard.view',
    'reports.view',
    'partitions.view', // Add this
],
```

Run seeder:
```bash
php artisan db:seed --class=RolePermissionSeeder
```

## Benefits of Dynamic Navigation

### 1. Automatic Updates
- No code changes needed to adjust access
- Superadmin controls everything from UI
- Changes reflect immediately after re-login

### 2. Flexible Access Control
- Give managers access to specific features
- Create custom roles with specific permissions
- Fine-grained control over what users see

### 3. Clean User Experience
- Users only see what they can access
- No confusing disabled menu items
- No 403 errors from clicking visible items

### 4. Scalable
- Easy to add new permissions
- Easy to create new roles
- Easy to adjust access levels

## Technical Implementation

### RoleGuard Component
The `RoleGuard` component handles permission checking:

```javascript
<RoleGuard 
    requiredPermission="single.permission"  // Single permission (AND)
    anyPermissions={['perm1', 'perm2']}     // Multiple permissions (OR)
>
    {/* Content only shows if user has permission */}
</RoleGuard>
```

### Navigation Items
Each navigation item specifies its permission requirements:

```javascript
const navigationItems = [
    {
        name: 'Menu Name',
        permission: 'single.permission',      // For single permission
        anyPermissions: ['perm1', 'perm2'],   // For OR logic
        children: [...]                        // Each child has own permission
    }
];
```

### Permission Check Flow
1. User logs in → Auth context loads user permissions
2. Navigation renders → RoleGuard checks each item
3. If user has required permission(s) → Show menu item
4. If user lacks permission(s) → Hide menu item
5. Children are independently checked → Only show accessible items

## Current Manager Permissions

After the update, manager has:
- `dashboard.view` ✅
- `reports.view` ✅
- `partitions.view` ✅

Manager will see:
- Dashboard ✅
- Table Management (because has partitions.view) ✅
  - Partitions only ✅
- Reports ✅

## Testing

### Test Dynamic Navigation
1. Login as Superadmin
2. Go to Roles page
3. Edit Manager role
4. Add/remove permissions
5. Save changes
6. Login as Manager user
7. Verify navigation shows/hides correctly

### Verify API Protection
Even if navigation shows, API still validates permissions:
- Manager with `partitions.view` can access `/api/sync/partitions` ✅
- Manager without `table-sync.view` gets 403 on `/api/table-sync/*` ✅

## Status
✅ **COMPLETE** - Navigation dynamically reflects assigned permissions with OR logic support
