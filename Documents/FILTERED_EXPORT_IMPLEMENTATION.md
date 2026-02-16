# Filtered Export Implementation

## Summary
Updated the export functionality to respect filters applied on the All Alerts and VM Alerts pages. When users apply filters and click "Export CSV", only the filtered records are exported.

## Changes Made

### 1. Frontend Updates

#### AlertsReportDashboard.jsx
**Changed:** `handleExport()` function
- **Before:** Only sent `from_date` parameter
- **After:** Sends all active filters (panelid, dvrip, customer, panel_type, atmid)
- Removes empty filters before sending

#### VMAlertDashboard.jsx
**Changed:** `handleExport()` function
- **Before:** Only sent `from_date` parameter
- **After:** Sends all active filters (panelid, dvrip, customer, panel_type, atmid, status)
- Removes empty filters before sending

### 2. Backend Updates

#### AlertsReportController.php

**1. Updated Validation:**
Added filter parameters to validation:
```php
'panelid' => 'nullable|string|max:255',
'dvrip' => 'nullable|string|max:255',
'customer' => 'nullable|string|max:255',
'panel_type' => 'nullable|string|max:255',
'atmid' => 'nullable|string|max:255',
```

**2. Added New Method:** `exportViaRouterWithFilters()`
- Accepts validated filters
- Builds filter array for partition router
- Handles sites-based filters (dvrip, customer, panel_type, atmid)
- Converts sites filters to panel IDs
- Applies filters during export

**3. Updated Export Flow:**
- Passes `$validated` array to callback
- Calls `exportViaRouterWithFilters()` instead of `exportViaRouterNoFilters()`

#### VMAlertController.php

**1. Updated Validation:**
Added filter parameters to validation:
```php
'panelid' => 'nullable|string|max:255',
'dvrip' => 'nullable|string|max:255',
'customer' => 'nullable|string|max:255',
'panel_type' => 'nullable|string|max:255',
'atmid' => 'nullable|string|max:255',
'status' => 'nullable|string|in:O,C',
```

**2. Added New Method:** `exportViaRouterWithFilters()`
- Accepts validated filters
- Builds filter array with VM-specific base filters
- Applies status filter (O, C, or both)
- Always includes `vm_sendtoclient = 'S'`
- Handles sites-based filters
- Applies filters during export

**3. Updated Export Flow:**
- Passes `$validated` array to callback
- Calls `exportViaRouterWithFilters()` instead of `exportViaRouterNoFilters()`

## Filter Types

### Direct Filters
- **Panel ID:** Filters by exact panel ID match

### Sites-Based Filters
These filters query the `sites` table first to get matching panel IDs:
- **DVR IP:** Partial match on DVRIP column
- **Customer:** Exact match on Customer column
- **Panel Type:** Exact match on Panel_Make column
- **ATM ID:** Partial match on ATMID column

### VM-Specific Filters
- **Status:** O (Open), C (Closed), or both
- **Send to Client:** Always 'S' for VM alerts

## How It Works

### 1. User Applies Filters
```
Panel ID: P1DCMU66
Customer: Deboold
From Date: 01/31/2026
```

### 2. Frontend Sends Request
```javascript
{
  from_date: '2026-01-31',
  panelid: 'P1DCMU66',
  customer: 'Deboold',
  per_page: 25
}
```

### 3. Backend Processes Filters
```php
// Build filter array
$filters = [];

// Direct panel ID filter
if (!empty($validated['panelid'])) {
    $filters['panel_id'] = 'P1DCMU66';
}

// Sites-based filters
$panelIds = getPanelIdsFromSitesFilters([
    'customer' => 'Deboold'
]);
// Returns: ['P1DCMU66', 'P2DCMU77', ...]

$filters['panel_ids'] = $panelIds;
```

### 4. Export with Filters
```php
$results = $partitionRouter->queryDateRange(
    $startDate,
    $endDate,
    $filters, // Applied here
    $options,
    ['alerts', 'backalerts']
);
```

## Example Scenarios

### Scenario 1: Filter by Customer
**User Action:**
- Select Customer: "Deboold"
- Select Date: 01/31/2026
- Click "Export CSV"

**Result:**
- Exports only alerts for "Deboold" customer
- Filename: `21 Server Alert Report – 31-01-2026.csv`

### Scenario 2: Filter by Panel ID and Status (VM Alerts)
**User Action:**
- Enter Panel ID: "P1DCMU66"
- Select Status: "Open"
- Select Date: 01/31/2026
- Click "Export CSV"

**Result:**
- Exports only open VM alerts for panel "P1DCMU66"
- Filename: `21 Server VM Alerts Report – 31-01-2026.csv`

### Scenario 3: Multiple Filters
**User Action:**
- Select Customer: "Deboold"
- Select Panel Type: "RISCO"
- Enter ATM ID: "MH"
- Select Date: 01/31/2026
- Click "Export CSV"

**Result:**
- Exports alerts matching ALL filters:
  - Customer = "Deboold"
  - Panel Type = "RISCO"
  - ATM ID contains "MH"
  - Date = 01/31/2026

## Benefits

1. **Accurate Exports:** Users get exactly what they see on screen
2. **Reduced File Size:** Filtered exports are smaller and faster
3. **Better Performance:** Less data to process and transfer
4. **Consistent UX:** Export matches the filtered view
5. **Flexible Filtering:** Supports all filter combinations

## Technical Details

### Filter Processing Order
1. Validate all filter parameters
2. Build direct filters (panel_id)
3. Process sites-based filters → get panel IDs
4. Combine filters
5. Apply to partition router query
6. Export filtered results

### Memory Management
- Chunk size: 1000 records (All Alerts), 500 records (VM Alerts)
- Garbage collection every 5000 records
- Memory limit: 1GB (All Alerts), 2GB (VM Alerts)

### Logging
All exports log:
- Applied filters
- Record counts
- Processing time
- Any errors

## Testing

### Test Filtered Export
1. Navigate to All Alerts or VM Alerts page
2. Apply one or more filters
3. Click "Export CSV"
4. Verify:
   - Export starts immediately
   - File downloads with correct filename
   - File contains only filtered records
   - Record count matches filtered view

### Test No Filters
1. Navigate to All Alerts or VM Alerts page
2. Select only a date (no other filters)
3. Click "Export CSV"
4. Verify:
   - Exports all records for that date
   - Same behavior as before

## Notes
- Empty filters are automatically removed before sending
- Sites-based filters are converted to panel IDs on the backend
- VM alerts always include base filters (status O/C, sendtoclient S)
- Export respects the same filters as the paginated view
- Filename format remains: `21 Server [Type] Report – DD-MM-YYYY.csv`
