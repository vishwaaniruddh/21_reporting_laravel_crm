# VM Alerts Report Added

## Summary
Added a new "VM Alert" report to the Reports menu and renamed "Alerts Report" to "All Alerts".

## Changes Made

### 1. Navigation Updated
**Reports Menu now has 2 items:**
- ✅ **All Alerts** (renamed from "Alerts Reports")
- ✅ **VM Alert** (new)

Both require `reports.view` permission.

### 2. New Component Created
**File**: `resources/js/components/VMAlertDashboard.jsx`
- Placeholder page for VM-specific alerts
- Uses same layout as other dashboards
- Shows "Coming Soon" message
- Includes information about future VM alert features

### 3. Route Added
**File**: `resources/js/components/App.jsx`
- Added `/vm-alerts` route
- Protected with `reports.view` permission
- Imports VMAlertDashboard component

### 4. Navigation Icons
- **All Alerts**: Document/report icon (existing)
- **VM Alert**: Monitor/screen icon (new)

### 5. Page Detection
Updated `getCurrentPage()` function to recognize `/vm-alerts` path for active menu highlighting.

## Navigation Structure

```
Reports (Parent Menu)
├── All Alerts (/alerts-reports)
│   └── Shows all alerts with 27 columns
│   └── CSV export functionality
│   └── Pre-generated reports
│
└── VM Alert (/vm-alerts)
    └── VM-specific alert monitoring (placeholder)
    └── Coming soon features
```

## User Access

### All Roles with reports.view Permission
- ✅ Can see Reports menu
- ✅ Can access All Alerts
- ✅ Can access VM Alert

### Current Access:
- **Superadmin**: ✅ Both reports
- **Admin**: ✅ Both reports
- **Manager**: ✅ Both reports (has reports.view)

## Files Modified

1. **resources/js/components/DashboardLayout.jsx**
   - Renamed "Alerts Reports" to "All Alerts"
   - Added "VM Alert" menu item
   - Added VMAlertIcon component
   - Updated getCurrentPage() function

2. **resources/js/components/App.jsx**
   - Added VMAlertDashboard import
   - Added /vm-alerts route with permission check
   - Added permission checks to existing routes

3. **resources/js/components/VMAlertDashboard.jsx** (NEW)
   - Created placeholder VM Alert dashboard
   - Includes coming soon message
   - Lists future features

## Testing

### Test Navigation
1. Login as any user with `reports.view` permission
2. Click on **Reports** menu
3. Should see:
   - ✅ All Alerts
   - ✅ VM Alert

### Test All Alerts Page
1. Click "All Alerts"
2. Should navigate to `/alerts-reports`
3. Shows existing alerts report with filters and CSV export

### Test VM Alert Page
1. Click "VM Alert"
2. Should navigate to `/vm-alerts`
3. Shows placeholder page with "Coming Soon" message

## Future Implementation

The VM Alert page is currently a placeholder. To implement full functionality:

1. **Create API endpoints** for VM-specific alerts
2. **Add filters** for VM monitoring
3. **Implement data fetching** from VM alert sources
4. **Add export functionality** for VM reports
5. **Create visualizations** for VM metrics

## Status
✅ **COMPLETE** - VM Alert report menu item added and All Alerts renamed

## Next Steps
- Implement VM alert data fetching
- Add VM-specific filters
- Create VM alert export functionality
- Add VM monitoring visualizations
