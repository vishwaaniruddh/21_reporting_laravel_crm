# Sites Module - Quick Start

## What Was Added

A new **Sites** module with 4 submenus in the sidebar navigation:

1. **RMS** - Remote Monitoring System
2. **DVR** - Digital Video Recorder  
3. **Cloud** - Cloud-based sites
4. **GPS** - GPS tracking sites

## Access URLs

- RMS: `http://192.168.100.21:9000/sites/rms`
- DVR: `http://192.168.100.21:9000/sites/dvr`
- Cloud: `http://192.168.100.21:9000/sites/cloud`
- GPS: `http://192.168.100.21:9000/sites/gps`

## Permissions

### Created Permissions
- `sites.view` - View Sites menu (parent)
- `sites.rms` - Access RMS page
- `sites.dvr` - Access DVR page
- `sites.cloud` - Access Cloud page
- `sites.gps` - Access GPS page

### Superadmin Access
✅ Superadmin automatically has all Sites permissions

## How to Test

1. **Login as superadmin**
2. **Check sidebar** - You should see "Sites" menu item
3. **Expand Sites** - Click to see 4 submenus
4. **Click any submenu** - Navigate to the page
5. **Verify permissions** - Each page requires its specific permission

## Current Status

✅ Permissions created and seeded  
✅ Superadmin has all permissions  
✅ Sidebar navigation working  
✅ Routes protected with permissions  
✅ 4 placeholder pages created  
✅ Icons added for each menu  

⏳ **Next**: Add actual content/functionality to each page

## Assign Permissions to Other Roles

To give other roles access to Sites:

```bash
# Edit database/seeders/RolePermissionSeeder.php
# Add Sites permissions to desired roles
# Then run:
php artisan db:seed --class=RolePermissionSeeder
```

Example for Manager role:
```php
'manager' => [
    'dashboard.view',
    'reports.view',
    'sites.view',  // Add this
    'sites.rms',   // Add this
],
```

## Files Created

- `resources/js/pages/SitesRMSPage.jsx`
- `resources/js/pages/SitesDVRPage.jsx`
- `resources/js/pages/SitesCloudPage.jsx`
- `resources/js/pages/SitesGPSPage.jsx`

## Files Modified

- `database/seeders/PermissionSeeder.php` - Added Sites permissions
- `database/seeders/RolePermissionSeeder.php` - Assigned to superadmin
- `resources/js/components/App.jsx` - Added routes
- `resources/js/components/DashboardLayout.jsx` - Added navigation & icons

## Verification Commands

```bash
# Check permissions exist
php artisan tinker --execute="echo \App\Models\Permission::where('module', 'sites')->pluck('name');"

# Check superadmin has permissions
php artisan tinker --execute="echo \App\Models\Role::where('name', 'superadmin')->first()->permissions()->where('module', 'sites')->pluck('name');"
```

Both commands should show all 5 Sites permissions.
