# Sites Module Implementation

## Overview
Added a new "Sites" module to the application with 4 submenus: RMS, DVR, Cloud, and GPS. Each submenu has its own permission and page.

## Features Implemented

### 1. Permissions Created
All permissions have been added to the database via `PermissionSeeder`:

| Permission | Display Name | Module | Description |
|------------|-------------|--------|-------------|
| `sites.view` | View Sites | sites | Parent permission to view Sites menu |
| `sites.rms` | Access RMS | sites | Access RMS sites page |
| `sites.dvr` | Access DVR | sites | Access DVR sites page |
| `sites.cloud` | Access Cloud | sites | Access Cloud sites page |
| `sites.gps` | Access GPS | sites | Access GPS sites page |

### 2. Superadmin Permissions
Superadmin role automatically gets all Sites permissions via `RolePermissionSeeder`:
- sites.view
- sites.rms
- sites.dvr
- sites.cloud
- sites.gps

### 3. Navigation Menu
Added "Sites" parent menu in sidebar with 4 children:
- **RMS** - Remote Monitoring System
- **DVR** - Digital Video Recorder
- **Cloud** - Cloud-based sites
- **GPS** - GPS tracking sites

Each submenu:
- Has its own icon
- Requires specific permission
- Highlights when active
- Supports permission-based visibility

### 4. Routes
Added protected routes in `App.jsx`:

| Route | Component | Permission Required |
|-------|-----------|-------------------|
| `/sites/rms` | SitesRMSPage | sites.rms |
| `/sites/dvr` | SitesDVRPage | sites.dvr |
| `/sites/cloud` | SitesCloudPage | sites.cloud |
| `/sites/gps` | SitesGPSPage | sites.gps |

### 5. Page Components
Created 4 placeholder pages with consistent structure:
- `resources/js/pages/SitesRMSPage.jsx`
- `resources/js/pages/SitesDVRPage.jsx`
- `resources/js/pages/SitesCloudPage.jsx`
- `resources/js/pages/SitesGPSPage.jsx`

Each page includes:
- DashboardLayout wrapper
- Page title and description
- Placeholder content with relevant icon
- "Content coming soon..." message

## Files Modified

### Backend
1. **database/seeders/PermissionSeeder.php**
   - Added 5 new Sites permissions

2. **database/seeders/RolePermissionSeeder.php**
   - Assigned all Sites permissions to superadmin role

### Frontend
3. **resources/js/components/App.jsx**
   - Imported 4 Sites page components
   - Added 4 protected routes for Sites pages

4. **resources/js/components/DashboardLayout.jsx**
   - Added 'sites' to openMenus state
   - Added Sites parent menu with 4 children
   - Updated getCurrentPage() to recognize Sites routes
   - Added 5 new icon components (SitesIcon, RMSIcon, DVRIcon, CloudIcon, GPSIcon)

5. **resources/js/pages/SitesRMSPage.jsx** (new)
   - RMS sites page component

6. **resources/js/pages/SitesDVRPage.jsx** (new)
   - DVR sites page component

7. **resources/js/pages/SitesCloudPage.jsx** (new)
   - Cloud sites page component

8. **resources/js/pages/SitesGPSPage.jsx** (new)
   - GPS sites page component

## Database Changes

### Permissions Table
5 new records added:
```sql
INSERT INTO permissions (name, display_name, module) VALUES
('sites.view', 'View Sites', 'sites'),
('sites.rms', 'Access RMS', 'sites'),
('sites.dvr', 'Access DVR', 'sites'),
('sites.cloud', 'Access Cloud', 'sites'),
('sites.gps', 'Access GPS', 'sites');
```

### Role Permissions
Superadmin role gets all 5 Sites permissions automatically.

## Testing

### 1. Verify Permissions
```bash
php artisan tinker
>>> \App\Models\Permission::where('module', 'sites')->get(['name', 'display_name']);
```

Should show 5 Sites permissions.

### 2. Verify Superadmin Permissions
```bash
php artisan tinker
>>> $superadmin = \App\Models\Role::where('name', 'superadmin')->first();
>>> $superadmin->permissions()->where('module', 'sites')->pluck('name');
```

Should show all 5 Sites permissions.

### 3. Test Navigation
1. Login as superadmin
2. Check sidebar - should see "Sites" menu
3. Expand Sites menu - should see 4 submenus (RMS, DVR, Cloud, GPS)
4. Click each submenu - should navigate to respective page

### 4. Test Permissions
1. Create a test user with only `sites.rms` permission
2. Login as that user
3. Should see Sites menu but only RMS submenu
4. Other submenus (DVR, Cloud, GPS) should be hidden

## Icon Descriptions

### Sites Parent Icon
Building icon representing sites/locations

### RMS Icon
Grid/monitor icon representing remote monitoring system

### DVR Icon
Video camera icon representing digital video recorder

### Cloud Icon
Cloud icon representing cloud-based services

### GPS Icon
Location pin icon representing GPS tracking

## Next Steps

To add actual functionality to these pages:

1. **Create API endpoints** for each site type
2. **Create controllers** (e.g., RMSSitesController, DVRSitesController)
3. **Create services** for data fetching
4. **Update page components** with actual data tables/forms
5. **Add CRUD operations** (Create, Read, Update, Delete)
6. **Add filtering and search** functionality
7. **Add export features** (CSV, Excel)

## Permission Assignment

To assign Sites permissions to other roles:

```php
// In RolePermissionSeeder.php
'admin' => [
    // ... existing permissions
    'sites.view',
    'sites.rms',
    'sites.dvr',
    'sites.cloud',
    'sites.gps',
],

'manager' => [
    // ... existing permissions
    'sites.view',
    'sites.rms', // Only RMS for managers
],
```

Then run:
```bash
php artisan db:seed --class=RolePermissionSeeder
```

## URLs

- RMS Sites: http://192.168.100.21:9000/sites/rms
- DVR Sites: http://192.168.100.21:9000/sites/dvr
- Cloud Sites: http://192.168.100.21:9000/sites/cloud
- GPS Sites: http://192.168.100.21:9000/sites/gps

## Summary

The Sites module is now fully integrated with:
- ✅ 5 permissions created and seeded
- ✅ Superadmin has all Sites permissions
- ✅ Sidebar navigation with parent/child structure
- ✅ 4 protected routes with permission checks
- ✅ 4 placeholder page components
- ✅ Custom icons for each submenu
- ✅ Permission-based visibility
- ✅ Active state highlighting

The module is ready for content implementation!
