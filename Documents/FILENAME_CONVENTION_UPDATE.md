# File Naming Convention Update

## Summary
Updated all report download filenames to use the format: **"21 Server [Report Type] – DD-MM-YYYY.csv"**

## Changes Made

### 1. Downloads V2 (Queue-based exports)
**File:** `app/Jobs/GenerateCsvExportJobV2.php`

**Old Format:**
- `all-alerts_2026-01-31_uuid.csv`
- `vm-alerts_2026-01-31_uuid.csv`

**New Format:**
- `21 Server Alert Report – 31-01-2026.csv`
- `21 Server VM Alerts Report – 31-01-2026.csv`

### 2. Downloads V1 (Queue-based exports)
**File:** `app/Jobs/GenerateCsvExportJob.php`

**Old Format:**
- `all-alerts_2026-01-31_uuid.csv`
- `vm-alerts_2026-01-31_uuid.csv`

**New Format:**
- `21 Server Alert Report – 31-01-2026.csv`
- `21 Server VM Alerts Report – 31-01-2026.csv`

### 3. All Alerts Direct Export
**File:** `app/Http/Controllers/AlertsReportController.php`

**Old Format:**
- `alerts_report_2026-01-31.csv`

**New Format:**
- `21 Server Alert Report – 31-01-2026.csv`

### 4. VM Alerts Direct Export
**File:** `app/Http/Controllers/VMAlertController.php`

**Old Format:**
- `vm_alerts_report_2026-01-31.csv`

**New Format:**
- `21 Server VM Alerts Report – 31-01-2026.csv`

## Date Format
- **Old:** YYYY-MM-DD (e.g., 2026-01-31)
- **New:** DD-MM-YYYY (e.g., 31-01-2026)

## Report Types
1. **Alert Report** - For all alerts (from `/api/alerts-reports/export/csv` and Downloads page "All Alerts")
2. **VM Alerts Report** - For VM-specific alerts (from `/api/vm-alerts/export/csv` and Downloads page "VM Alerts")

## Example Filenames
- `21 Server Alert Report – 31-01-2026.csv`
- `21 Server VM Alerts Report – 15-12-2025.csv`
- `21 Server Alert Report – 01-01-2026.csv`

## Testing
To test the new naming convention:

1. **Test Downloads V2 (Queue-based):**
   ```powershell
   # Navigate to Downloads page
   # Select a date and report type
   # Click "Request Export"
   # Download the completed file
   # Verify filename format
   ```

2. **Test Direct Export:**
   ```powershell
   # Navigate to All Alerts or VM Alerts page
   # Select a date
   # Click "Export CSV"
   # Verify filename format
   ```

## Notes
- The filename format is consistent across all export methods
- The date format follows DD-MM-YYYY convention as requested
- The "21 Server" prefix identifies the server source
- The en dash (–) is used as a separator for better readability
- No UUID or timestamp is included in the filename for cleaner naming

## Impact
- **User-facing:** Users will see cleaner, more descriptive filenames
- **Backend:** No changes to file storage or processing logic
- **Compatibility:** No breaking changes - only filename format changed
