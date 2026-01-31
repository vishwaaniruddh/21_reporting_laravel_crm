# PostgreSQL Dashboard - Quick Test Guide

## Quick Start Testing (5 Minutes)

This guide provides a quick way to verify the PostgreSQL Dashboard is working correctly.

---

## Prerequisites Check

✅ **Backend Tests Passed:**
- Service layer: 17/17 tests passed ✓
- API endpoints: Working correctly ✓

✅ **What You Need:**
1. Laravel application running (WAMP/XAMPP or `php artisan serve`)
2. React frontend built (`npm run build`) or dev server (`npm run dev`)
3. Valid user credentials with 'dashboard.view' permission

---

## Quick Test Steps

### Step 1: Access the Dashboard (30 seconds)

1. Open your browser
2. Navigate to your application URL (e.g., `http://localhost:8000`)
3. Log in with your credentials
4. Click on **"PostgreSQL Dashboard"** in the navigation menu

**✓ Expected:** Page loads showing a table with alert counts

---

### Step 2: Verify Data Display (1 minute)

**Check the table has these columns:**
- Terminal
- User
- Open
- Close
- Total
- Critical Open
- Critical Close
- Total Critical

**Check the page shows:**
- Current shift number (1, 2, or 3)
- Shift time range
- Grand totals in footer row

**✓ Expected:** All columns visible, data populated, grand totals calculated

---

### Step 3: Test Auto-Refresh (30 seconds)

1. Note the current alert counts
2. Wait 5 seconds
3. Watch for the table to update automatically

**✓ Expected:** Table updates every 5 seconds without page reload

---

### Step 4: Test Modal Interaction (1 minute)

1. Find a terminal with open alerts (Open column > 0)
2. Hover over the number - cursor should change to pointer
3. Click on the number
4. Modal should open showing detailed alerts

**✓ Expected:** Modal opens with alert details table

**Check modal shows:**
- Sr no
- ATMID
- Panel ID
- Zone
- City
- Received At
- Alert Type
- Comment
- Close By
- Closed At

5. Click "Close" button to close modal

**✓ Expected:** Modal closes smoothly

---

### Step 5: Test Different Alert Types (1 minute)

Click on different count cells to verify modals work for:
- **Open** alerts
- **Close** alerts
- **Total** alerts
- **Critical Open** alerts (if any)
- **Critical Close** alerts (if any)
- **Total Critical** alerts (if any)

**✓ Expected:** Each modal shows the correct filtered alerts

---

### Step 6: Verify Site Data (30 seconds)

1. Open any modal
2. Check if ATMID, Zone, and City columns have values

**✓ Expected:** Site information displayed where available

---

## Quick Verification Checklist

Use this checklist for rapid verification:

- [ ] Dashboard page loads without errors
- [ ] Table displays with 8 columns
- [ ] Current shift and time range shown
- [ ] Terminal data populated
- [ ] Grand totals calculated correctly
- [ ] Auto-refresh works (5-second interval)
- [ ] Clicking alert counts opens modal
- [ ] Modal displays alert details
- [ ] Modal close button works
- [ ] Site data (ATMID, Zone, City) displayed
- [ ] No console errors in browser dev tools

---

## Common Issues and Solutions

### Issue: Page shows "Loading..." forever

**Solution:**
1. Check browser console for errors
2. Verify API endpoint is accessible: `GET /api/dashboard/postgres/data`
3. Check authentication token is valid
4. Verify user has 'dashboard.view' permission

### Issue: Table is empty

**Solution:**
1. Check if partition tables exist for current date
2. Verify alerts exist in current shift time range
3. Check PostgreSQL connection is working
4. Run: `php test_cases/test_postgres_dashboard_service.php`

### Issue: Modal doesn't open

**Solution:**
1. Check browser console for JavaScript errors
2. Verify API endpoint: `GET /api/dashboard/postgres/details`
3. Check network tab for failed requests

### Issue: Auto-refresh not working

**Solution:**
1. Check browser console for errors
2. Verify interval is set (should see API calls every 5 seconds in Network tab)
3. Check if component is properly mounted

---

## Testing Different Shifts

### Current Shift Detection

The dashboard automatically detects the current shift based on server time:

- **Shift 1:** 07:00 - 14:59
- **Shift 2:** 15:00 - 22:59
- **Shift 3:** 23:00 - 06:59 (spans midnight)

### Manual Shift Testing

To test a specific shift, you can:

1. **Wait for the shift time** (easiest)
2. **Use browser dev tools:**
   - Open Network tab
   - Find the API call to `/api/dashboard/postgres/data`
   - Right-click → Copy as cURL
   - Modify to add `?shift=1` (or 2, or 3)
   - Execute in terminal

3. **Temporarily change server time** (advanced)

---

## Performance Expectations

- **Initial page load:** < 2 seconds
- **Data fetch:** < 1 second
- **Auto-refresh:** Every 5 seconds
- **Modal open:** < 500ms
- **Modal data fetch:** < 1 second

---

## Browser Console Check

Open browser developer tools (F12) and check:

1. **Console tab:** Should have no errors
2. **Network tab:** Should show successful API calls
   - `GET /api/dashboard/postgres/data` → 200 OK
   - `GET /api/dashboard/postgres/details` → 200 OK (when modal opened)

---

## Success Criteria

✅ **Dashboard is working correctly if:**

1. Page loads and displays data
2. Auto-refresh updates every 5 seconds
3. Clicking alert counts opens modals
4. Modals display detailed alert information
5. No errors in browser console
6. Grand totals match sum of individual terminals

---

## Next Steps

If all quick tests pass:
- ✅ Dashboard is ready for production use
- ✅ Mark task 22 as complete
- ✅ Proceed with user acceptance testing

If any tests fail:
- 🔍 Review the detailed checklist: `POSTGRES_DASHBOARD_E2E_TEST_CHECKLIST.md`
- 🐛 Check browser console and Laravel logs for errors
- 📝 Document issues and report to development team

---

## Support

For detailed testing, see: `POSTGRES_DASHBOARD_E2E_TEST_CHECKLIST.md`

For API testing, run:
```bash
php test_cases/test_postgres_dashboard_api.php
php test_cases/test_postgres_dashboard_service.php
```

For curl testing, see: `test_postgres_dashboard_curl.ps1`

