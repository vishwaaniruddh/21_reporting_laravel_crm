# Export Buttons Update - All Alerts & VM Alerts Pages

## Summary
Added both **"Export CSV"** and **"Go to Downloads Page"** buttons to the All Alerts and VM Alerts report pages, giving users two export options.

## Changes Made

### 1. All Alerts Page (`AlertsReportDashboard.jsx`)
**Location:** Top right of the results table

**Buttons Added:**
1. **Export CSV** (Blue button)
   - Direct export from the current page
   - Downloads immediately with the new filename format
   - Filename: `21 Server Alert Report – DD-MM-YYYY.csv`
   - Shows "Exporting..." spinner during download

2. **Go to Downloads Page** (Green button)
   - Navigates to `/reports/downloads`
   - For queue-based exports
   - Better for large datasets

### 2. VM Alerts Page (`VMAlertDashboard.jsx`)
**Location:** Top right of the results table

**Buttons Added:**
1. **Export CSV** (Blue button)
   - Direct export from the current page
   - Downloads immediately with the new filename format
   - Filename: `21 Server VM Alerts Report – DD-MM-YYYY.csv`
   - Shows "Exporting..." spinner during download

2. **Go to Downloads Page** (Green button)
   - Navigates to `/reports/downloads`
   - For queue-based exports
   - Better for large datasets

## Button Layout

```
┌─────────────────────────────────────────────────────────────┐
│ Reports                                    [Show: 25 ▼]     │
│                                            [Export CSV]      │
│                                            [Go to Downloads] │
└─────────────────────────────────────────────────────────────┘
```

## User Experience

### Export CSV Button
- **When to use:** Quick exports for the selected date
- **Behavior:** 
  - Triggers immediate download
  - Shows spinner while generating
  - Downloads file with new naming convention
  - Exports ALL data for the selected date (no filters applied)

### Go to Downloads Page Button
- **When to use:** 
  - Large datasets
  - Multiple date ranges
  - Queue-based processing
- **Behavior:**
  - Redirects to `/reports/downloads`
  - Allows selecting multiple dates
  - Queue-based processing (V2 with Redis)
  - Better for concurrent users

## Technical Details

### Export Functionality
Both pages use their respective export services:
- **All Alerts:** `exportCsv()` from `alertsReportService`
- **VM Alerts:** `exportVMCsv()` from `vmAlertService`

### API Endpoints
- **All Alerts:** `GET /api/alerts-reports/export/csv`
- **VM Alerts:** `GET /api/vm-alerts/export/csv`

### Filename Format
- **All Alerts:** `21 Server Alert Report – 31-01-2026.csv`
- **VM Alerts:** `21 Server VM Alerts Report – 31-01-2026.csv`

## Button Styling

### Export CSV (Blue)
```css
bg-blue-600 hover:bg-blue-700
text-white
rounded text-xs
```

### Go to Downloads Page (Green)
```css
bg-green-600 hover:bg-green-700
text-white
rounded text-xs
```

## States

### Export CSV Button States
1. **Normal:** "Export CSV" with download icon
2. **Exporting:** "Exporting..." with spinner
3. **Disabled:** When no date is selected (opacity-50)

### Go to Downloads Page Button
- Always enabled
- Simple navigation link

## Testing

### Test Export CSV
1. Navigate to All Alerts or VM Alerts page
2. Select a date
3. Click "Export CSV"
4. Verify:
   - Spinner shows during export
   - File downloads automatically
   - Filename format: `21 Server [Type] Report – DD-MM-YYYY.csv`

### Test Go to Downloads Page
1. Navigate to All Alerts or VM Alerts page
2. Click "Go to Downloads Page"
3. Verify:
   - Redirects to `/reports/downloads`
   - Downloads page loads correctly

## Benefits

1. **Flexibility:** Users can choose between quick export or queue-based
2. **Consistency:** Same buttons on both All Alerts and VM Alerts pages
3. **Clear Labels:** Button text clearly indicates the action
4. **Visual Distinction:** Different colors (blue vs green) help users understand the difference
5. **Better UX:** Both options available without navigation

## Notes
- Export CSV downloads ALL data for the selected date (filters are not applied to export)
- Go to Downloads Page is recommended for large datasets or multiple dates
- Both buttons respect the selected date from the filter
- Export CSV button is disabled when no date is selected
