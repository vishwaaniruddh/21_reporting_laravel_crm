# Sidebar Menu Behavior Fix

## Issue
All parent menus (Users Management, Table Management, Reports, Sites) were open by default, making the sidebar cluttered.

## Solution
Changed menu behavior to only open menus that contain the active route.

## Implementation

### Before
```javascript
const [openMenus, setOpenMenus] = useState({
    'users-management': true,  // Always open
    'table-management': true,  // Always open
    'reports': true,           // Always open
    'sites': true              // Always open
});
```

### After
```javascript
// Helper function to check if a menu contains the active route
const isMenuActive = (menuKey) => {
    const path = location.pathname;
    
    if (menuKey === 'users-management') {
        return path.startsWith('/users') || path.startsWith('/roles') || path.startsWith('/permissions');
    }
    if (menuKey === 'table-management') {
        return path.startsWith('/table-sync') || path.startsWith('/partitions');
    }
    if (menuKey === 'reports') {
        return path.startsWith('/alerts-reports') || path.startsWith('/vm-alerts');
    }
    if (menuKey === 'sites') {
        return path.startsWith('/sites/');
    }
    return false;
};

// Initialize openMenus based on active route
const getInitialOpenMenus = () => {
    return {
        'users-management': isMenuActive('users-management'),
        'table-management': isMenuActive('table-management'),
        'reports': isMenuActive('reports'),
        'sites': isMenuActive('sites')
    };
};

const [openMenus, setOpenMenus] = useState(getInitialOpenMenus());
```

## Behavior

### Menu States
- **Closed by default**: All parent menus start closed
- **Auto-open on active route**: Menu automatically opens if it contains the current page
- **Manual toggle**: Users can still click to expand/collapse any menu

### Examples

| Current Page | Open Menu |
|--------------|-----------|
| `/dashboard` | None |
| `/users` | Users Management |
| `/roles` | Users Management |
| `/permissions` | Users Management |
| `/table-sync` | Table Management |
| `/partitions` | Table Management |
| `/alerts-reports` | Reports |
| `/vm-alerts` | Reports |
| `/sites/rms` | Sites |
| `/sites/dvr` | Sites |
| `/sites/cloud` | Sites |
| `/sites/gps` | Sites |

## Benefits

1. **Cleaner UI**: Sidebar is less cluttered on initial load
2. **Better UX**: Only relevant menu is expanded
3. **Context awareness**: User immediately sees which section they're in
4. **Still flexible**: Users can manually expand other menus if needed

## Files Modified

- `resources/js/components/DashboardLayout.jsx`
  - Added `isMenuActive()` helper function
  - Added `getInitialOpenMenus()` initialization function
  - Changed `openMenus` state to use dynamic initialization

## Testing

1. **Navigate to Dashboard** → All menus should be closed
2. **Navigate to Users** → Only "Users Management" should be open
3. **Navigate to Table Sync** → Only "Table Management" should be open
4. **Navigate to All Alerts** → Only "Reports" should be open
5. **Navigate to Sites/RMS** → Only "Sites" should be open
6. **Click any closed menu** → Should expand manually
7. **Click any open menu** → Should collapse

## Technical Notes

- Uses `location.pathname` from React Router to detect current route
- State initializes on component mount based on current route
- `toggleMenu()` function still works for manual expand/collapse
- Menu state persists during navigation within the same menu group
