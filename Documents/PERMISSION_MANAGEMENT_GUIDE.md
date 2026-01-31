# Permission Management Guide

## Quick Reference for Managing Permissions

### View All Permissions
```bash
php artisan tinker --execute="echo json_encode(\App\Models\Permission::all(['name', 'display_name', 'module'])->toArray(), JSON_PRETTY_PRINT);"
```

### View Permissions by Module
```bash
# Table Sync permissions
php artisan tinker --execute="echo json_encode(\App\Models\Permission::where('module', 'table-sync')->get(['name', 'display_name'])->toArray(), JSON_PRETTY_PRINT);"

# Partitions permissions
php artisan tinker --execute="echo json_encode(\App\Models\Permission::where('module', 'partitions')->get(['name', 'display_name'])->toArray(), JSON_PRETTY_PRINT);"
```

### View Role Permissions
```bash
# Superadmin
php artisan tinker --execute="echo implode(', ', \App\Models\Role::where('name', 'superadmin')->first()->permissions->pluck('name')->toArray());"

# Admin
php artisan tinker --execute="echo implode(', ', \App\Models\Role::where('name', 'admin')->first()->permissions->pluck('name')->toArray());"

# Manager
php artisan tinker --execute="echo implode(', ', \App\Models\Role::where('name', 'manager')->first()->permissions->pluck('name')->toArray());"
```

---

## Adding New Permissions

### Step 1: Add to PermissionSeeder
Edit `database/seeders/PermissionSeeder.php`:

```php
[
    'name' => 'module.action',
    'display_name' => 'Action Description',
    'module' => 'module-name',
],
```

### Step 2: Run Seeder
```bash
php artisan db:seed --class=PermissionSeeder
```

### Step 3: Assign to Roles
Edit `database/seeders/RolePermissionSeeder.php`:

```php
'superadmin' => [
    // ... existing permissions
    'module.action',
],
```

### Step 4: Run Role Seeder
```bash
php artisan db:seed --class=RolePermissionSeeder
```

### Step 5: Add Middleware to Routes
Edit `routes/api.php`:

```php
Route::get('/endpoint', [Controller::class, 'method'])
    ->middleware(['auth:sanctum', 'permission:module.action']);
```

### Step 6: Update Navigation (if needed)
Edit `resources/js/components/DashboardLayout.jsx`:

```javascript
{
    name: 'Menu Item',
    href: '/path',
    icon: Icon,
    permission: 'module.action',
    key: 'menu-key'
}
```

### Step 7: Rebuild Frontend
```bash
npm run build
```

### Step 8: Clear Cache
```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

---

## Modifying Existing Permissions

### Change Role Permissions via UI
1. Login as Superadmin
2. Navigate to Roles page
3. Click "Edit" on the role
4. Check/uncheck permissions
5. Click "Save"

### Change Role Permissions via Seeder
1. Edit `database/seeders/RolePermissionSeeder.php`
2. Add or remove permission names from role array
3. Run: `php artisan db:seed --class=RolePermissionSeeder`

### Change Role Permissions via Tinker
```bash
php artisan tinker
```

```php
$role = \App\Models\Role::where('name', 'manager')->first();
$permissions = \App\Models\Permission::whereIn('name', ['dashboard.view', 'reports.view'])->pluck('id');
$role->permissions()->sync($permissions);
```

---

## Permission Naming Convention

### Format: `module.action`

**Modules:**
- `users` - User management
- `roles` - Role management
- `permissions` - Permission management
- `dashboard` - Dashboard access
- `reports` - Reports access
- `table-sync` - Table synchronization
- `partitions` - Partition management

**Actions:**
- `view` - Read-only access
- `create` - Create new records
- `read` - View existing records
- `update` - Modify existing records
- `delete` - Remove records
- `manage` - Full CRUD operations
- `assign` - Assign relationships

**Examples:**
- `users.read` - View users
- `users.create` - Create new users
- `table-sync.view` - View table sync configurations
- `table-sync.manage` - Full table sync management
- `permissions.assign` - Assign permissions to roles

---

## Testing Permissions

### Test User Access
```php
// In controller or middleware
if (!$user->hasPermission('table-sync.view')) {
    return response()->json(['error' => 'Unauthorized'], 403);
}
```

### Test Role Access
```php
// Check if role has permission
$role = \App\Models\Role::find(1);
$hasPermission = $role->permissions()->where('name', 'table-sync.view')->exists();
```

### Test API Endpoint
```bash
# With valid token and permission
curl -H "Authorization: Bearer {token}" http://localhost/api/table-sync/overview

# Expected: 200 OK with data

# Without permission
# Expected: 403 Forbidden
```

---

## Troubleshooting

### Permission Not Working
1. Clear cache: `php artisan cache:clear`
2. Clear config: `php artisan config:clear`
3. Clear routes: `php artisan route:clear`
4. Verify permission exists in database
5. Verify role has permission assigned
6. Check middleware is applied to route

### Navigation Not Hiding
1. Rebuild frontend: `npm run build`
2. Clear browser cache
3. Check permission name matches exactly
4. Verify RoleGuard component is used

### API Returns 403
1. Check user is authenticated
2. Verify user's role has required permission
3. Check middleware is correctly applied
4. Verify permission name in middleware matches database

---

## Current Permission Structure

```
Dashboard (1 permission)
├── dashboard.view

Users Management (7 permissions)
├── users.create
├── users.read
├── users.update
├── users.delete
├── roles.read
├── permissions.read
└── permissions.assign

Table Management (4 permissions)
├── table-sync.view
├── table-sync.manage
├── partitions.view
└── partitions.manage

Reports (1 permission)
└── reports.view
```

**Total: 13 permissions**

---

## Role Access Summary

| Role | Permissions | Access Level |
|------|-------------|--------------|
| Superadmin | 13 | Full access to everything |
| Admin | 13 | Full access to everything |
| Manager | 2 | Dashboard + Reports only |

---

## Best Practices

1. **Always use permission middleware** on protected routes
2. **Use view/manage separation** for granular control
3. **Test with different roles** before deploying
4. **Document new permissions** when adding them
5. **Clear cache** after permission changes
6. **Rebuild frontend** after navigation changes
7. **Use descriptive permission names** following convention
8. **Group related permissions** by module
9. **Assign minimum required permissions** to each role
10. **Regularly audit** role permissions for security

---

## Quick Commands Reference

```bash
# Seed permissions
php artisan db:seed --class=PermissionSeeder

# Seed role permissions
php artisan db:seed --class=RolePermissionSeeder

# Clear all cache
php artisan config:clear; php artisan cache:clear; php artisan route:clear

# Rebuild frontend
npm run build

# View routes with middleware
php artisan route:list --path=table-sync

# Test permission in tinker
php artisan tinker
>>> $user = \App\Models\User::find(1);
>>> $user->hasPermission('table-sync.view');
```
