# VM Alerts Status Filter

## Feature Added
Added a **Status** dropdown filter to the VM Alerts page that allows filtering by:
- **All Status** (default) - Shows both Open and Closed alerts
- **Open** - Shows only Open (O) alerts
- **Closed** - Shows only Closed (C) alerts

## Location
**URL**: http://192.168.100.21:9000/vm-alerts

## How It Works

### Frontend (VMAlertDashboard.jsx)
1. Added `status` field to filters state (default: empty string = All)
2. Added Status dropdown in the filter form (between ATM ID and From Date)
3. Dropdown options:
   - "All Status" (value: "")
   - "Open" (value: "O")
   - "Closed" (value: "C")
4. Status filter is included in API requests to backend

### Backend (VMAlertController.php)
1. Added `status` validation rule: `'status' => 'nullable|string|in:O,C'`
2. Modified filter logic in `getAlertsViaRouter()`:
   - If status is selected: `$filters['vm_status'] = [$validated['status']]` (single status)
   - If no status selected: `$filters['vm_status'] = ['O', 'C']` (both statuses)
3. Always maintains `sendtoclient = 'S'` filter (VM-specific requirement)

## Usage

### Filter by Open Alerts Only
1. Go to VM Alerts page
2. Select **Status**: "Open"
3. Select a date
4. Click "Filter"
5. Results show only alerts with status = 'O'

### Filter by Closed Alerts Only
1. Go to VM Alerts page
2. Select **Status**: "Closed"
3. Select a date
4. Click "Filter"
5. Results show only alerts with status = 'C'

### Show All Alerts (Default)
1. Go to VM Alerts page
2. Leave **Status**: "All Status" (or clear filters)
3. Select a date
4. Click "Filter"
5. Results show both Open and Closed alerts

## Technical Details

### Query Behavior
The status filter modifies the partition query:

**All Status (default)**:
```sql
WHERE status IN ('O', 'C') AND sendtoclient = 'S'
```

**Open Only**:
```sql
WHERE status IN ('O') AND sendtoclient = 'S'
```

**Closed Only**:
```sql
WHERE status IN ('C') AND sendtoclient = 'S'
```

### Partition Tables
Queries both partition types:
- `alerts_YYYY_MM_DD`
- `backalerts_YYYY_MM_DD`

### Performance
- Filter is applied at the database level (efficient)
- Uses indexed status column
- No performance impact on large datasets

## Files Modified

1. **resources/js/components/VMAlertDashboard.jsx**
   - Added `status: ''` to filters state
   - Added Status dropdown in filter form
   - Updated `handleClearFilters()` to reset status

2. **app/Http/Controllers/VMAlertController.php**
   - Added `status` validation rule
   - Modified filter logic to handle status selection
   - Maintains VM-specific filters (sendtoclient='S')

## Testing

### Test Cases
1. ✅ Select "Open" → Should show only Open alerts
2. ✅ Select "Closed" → Should show only Closed alerts
3. ✅ Select "All Status" → Should show both Open and Closed
4. ✅ Clear filters → Should reset to "All Status"
5. ✅ Combine with other filters (Panel ID, Customer, etc.)

### Expected Results
- Filter dropdown appears between ATM ID and From Date
- Selecting a status updates the results immediately
- Pagination works correctly with status filter
- CSV export respects the status filter (if implemented)

## Notes

- Status filter only applies to the table view (pagination)
- CSV downloads currently export ALL VM alerts for the date (not filtered by status)
- If you want CSV downloads to respect the status filter, additional backend changes are needed

## Date Added
January 31, 2026
