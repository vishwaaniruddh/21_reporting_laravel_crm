# Table Sync and Partitions Permissions Implementation

## Summary
Created dedicated permissions for Table Sync and Partitions modules to properly control access to these features.

## New Permissions Created

### Table Sync Module
1. **table-sync.view** - View Table Sync
   - Allows viewing table sync configurations, logs, and status
   - Required to access Table Sync page
   
2. **table-sync.manage** - Manage Table Sync
   - Allows creating, updating, deleting sync configurations
   - Allows triggering syncs, managing errors, and controlling sync operations

### Partitions Module
1. **partitions.view** - View Partitions
   - Allows viewing partition tables and information
   - Required to access Partitions page
   
2. **partitions.manage** - Manage Partitions
   - Allows triggering partition syncs
   - Allows managing partition operations

## Role Assignments

### Superadmin
- ✅ table-sync.view
- ✅ table-sync.manage
- ✅ partitions.view
- ✅ partitions.manage
- Full access to all table sync and partition features

### Admin
- ✅ table-sync.view
- ✅ table-sync.manage
- ✅ partitions.view
- ✅ partitions.manage
- Full access to all table sync and partition features

### Manager
- ❌ No table sync permissions
- ❌ No partition permissions
- Managers cannot see or access Table Management menu

## Navigation Updates

### Table Management Menu
- Parent menu requires: `table-sync.view`
- Table Sync submenu requires: `table-sync.view`
- Partitions submenu requires: `partitions.view`

**Result**: Only Superadmin and Admin can see Table Management menu

## API Route Protection

### Table Sync Routes
- **View operations** (GET): Require `table-sync.view` permission
  - `/api/table-sync/overview`
  - `/api/table-sync/logs`
  - `/api/table-sync/configurations`
  - `/api/table-sync/status/{id}`
  - `/api/table-sync/errors`

- **Manage operations** (POST/PUT/DELETE): Require `table-sync.manage` permission
  - `/api/table-sync/configurations` (POST)
  - `/api/table-sync/configurations/{id}` (PUT/DELETE)
  - `/api/table-sync/sync/{id}`
  - `/api/table-sync/sync-all`
  - `/api/table-sync/errors/retry-all`

### Partition Routes
- **View operations** (GET): Require `partitions.view` permission
  - `/api/sync/partitions`
  - `/api/sync/partitions/{date}`
  - `/api/reports/partitioned/query`

- **Manage operations** (POST): Require `partitions.manage` permission
  - `/api/sync/partitioned/trigger`

### Alerts Reports Routes
- All routes now require `reports.view` permission
- Ensures only authorized users can access reports

## Files Modified

1. **database/seeders/PermissionSeeder.php**
   - Added 4 new permissions for table-sync and partitions modules

2. **database/seeders/RolePermissionSeeder.php**
   - Assigned new permissions to superadmin and admin roles
   - Manager role remains with only dashboard.view and reports.view

3. **resources/js/components/DashboardLayout.jsx**
   - Updated Table Management parent menu to require `table-sync.view`
   - Updated Table Sync submenu to require `table-sync.view`
   - Updated Partitions submenu to require `partitions.view`

4. **routes/api.php**
   - Added permission middleware to all table-sync routes
   - Added permission middleware to all partition routes
   - Added permission middleware to all alerts-reports routes

## Testing

### Verify Permissions Created
```bash
php artisan tinker --execute="echo json_encode(\App\Models\Permission::whereIn('module', ['table-sync', 'partitions'])->get(['name', 'display_name', 'module'])->toArray(), JSON_PRETTY_PRINT);"
```

### Verify Role Assignments
```bash
# Superadmin permissions
php artisan tinker --execute="echo 'Superadmin: ' . implode(', ', \App\Models\Role::where('name', 'superadmin')->first()->permissions->pluck('name')->toArray());"

# Admin permissions
php artisan tinker --execute="echo 'Admin: ' . implode(', ', \App\Models\Role::where('name', 'admin')->first()->permissions->pluck('name')->toArray());"

# Manager permissions
php artisan tinker --execute="echo 'Manager: ' . implode(', ', \App\Models\Role::where('name', 'manager')->first()->permissions->pluck('name')->toArray());"
```

## Expected Behavior

### Superadmin/Admin Users
- ✅ Can see Dashboard
- ✅ Can see Users Management menu (Users, Roles, Permissions)
- ✅ Can see Table Management menu (Table Sync, Partitions)
- ✅ Can see Reports menu (Alerts Reports)
- ✅ Can perform all operations in Table Sync and Partitions

### Manager Users
- ✅ Can see Dashboard
- ❌ Cannot see Users Management menu
- ❌ Cannot see Table Management menu
- ✅ Can see Reports menu (Alerts Reports)
- ✅ Can view and download reports only

## Status
✅ **COMPLETE** - All permissions created, assigned, and protected with middleware
