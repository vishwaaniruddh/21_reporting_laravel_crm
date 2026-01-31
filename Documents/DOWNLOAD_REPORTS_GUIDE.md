# How to Download Alerts Reports

## Quick Steps

1. **Open Alerts Reports page** in your browser
2. **Select a date** using the "From Date" field
3. **Click "Download All"** button (green button with download icon)
4. **Wait** for the CSV file to download (may take 1-2 minutes for large datasets)
5. **Open the CSV file** in Excel

## Important Notes

### ✅ What Gets Downloaded
- **ALL alerts** for the selected date
- **No filters applied** - you get complete data
- **All 27 columns** with full information
- **Enriched with sites data** (Client, ATM ID, City, etc.)

### 📊 File Format
- **Format**: CSV (Comma-Separated Values)
- **Opens in**: Excel, Google Sheets, or any spreadsheet software
- **Encoding**: UTF-8 (supports all characters)
- **Special characters**: Handled automatically (commas, quotes, etc.)

### ⚡ Performance
- **Small datasets** (< 10k records): Downloads in seconds
- **Medium datasets** (10k-100k records): Downloads in 30-60 seconds
- **Large datasets** (100k-500k records): Downloads in 1-3 minutes
- **Very large datasets** (500k+ records): May take 3-5 minutes

### 🎯 Filters
- **Viewing filters** (Panel ID, Customer, etc.): Only affect what you see on screen
- **Download**: Always downloads ALL data for the selected date
- **No filters applied** to the download

## Example

**Scenario**: You want all alerts for January 8, 2026

1. Set "From Date" to `2026-01-08`
2. Click "Download All (2026-01-08)"
3. File `alerts_report_2026-01-08.csv` downloads
4. Open in Excel
5. You see all 360,000+ records with complete data

## Troubleshooting

### Download takes too long
- **Normal**: Large datasets (300k+ records) take 2-5 minutes
- **Solution**: Be patient, the download will complete

### CSV opens with garbled characters
- **Cause**: Excel not detecting UTF-8 encoding
- **Solution**: 
  1. Open Excel first
  2. Go to Data > From Text/CSV
  3. Select the downloaded file
  4. Choose UTF-8 encoding
  5. Click Load

### Some columns are blank
- **This should not happen anymore** - all columns should have data
- **If it does**: Contact support with the date you're trying to download

### File is too large to open in Excel
- **Excel limit**: ~1 million rows
- **Solution**: 
  1. Use Google Sheets (handles larger files)
  2. Or split the download by using filters in the UI first

## Technical Details

- **Endpoint**: `/api/alerts-reports/export/csv`
- **Method**: GET
- **Parameters**: `from_date` (required)
- **Response**: Streaming CSV file
- **Memory**: Efficient streaming (no memory issues)
- **Timeout**: 10 minutes maximum
