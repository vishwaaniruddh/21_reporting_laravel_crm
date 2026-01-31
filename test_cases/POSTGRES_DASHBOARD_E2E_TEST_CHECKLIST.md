# PostgreSQL Dashboard - End-to-End Test Checklist

## Test Date: 2026-01-12
## Tester: [Your Name]
## Environment: Development

---

## Prerequisites

- [ ] Laravel application is running (php artisan serve or WAMP/XAMPP)
- [ ] React frontend is built (npm run build) or dev server running (npm run dev)
- [ ] PostgreSQL database is accessible with partitioned alert tables
- [ ] MySQL database is accessible with reference tables (alertscount, loginusers, sites)
- [ ] User has valid authentication credentials
- [ ] User has 'dashboard.view' permission

---

## Test 1: Service Layer Verification

**Status:** ✅ PASSED (Automated test completed)

**Results:**
- All 17 service layer tests passed
- Shift calculation working correctly for all time ranges
- Partition table selection working for all shifts
- Grand total calculation accurate
- Username enrichment working
- Alert details retrieval working

**Evidence:** See `test_postgres_dashboard_service.php` output

---

## Test 2: API Endpoint Verification

**Status:** ✅ PASSED (Automated test completed)

**Results:**
- Data endpoint returns 200 with valid authentication
- Data endpoint accepts shift parameter (1, 2, 3)
- Data endpoint rejects invalid shift values
- Details endpoint returns alert details
- Details endpoint validates required parameters

**Evidence:** See `test_postgres_dashboard_api.php` output

---

## Test 3: Frontend Page Load

### 3.1 Access Dashboard Page

**Steps:**
1. Open browser and navigate to application URL
2. Log in with valid credentials (user with dashboard.view permission)
3. Click on "PostgreSQL Dashboard" in the navigation menu
4. Verify page loads without errors

**Expected Results:**
- [ ] Page loads successfully
- [ ] URL is `/dashboard/postgres`
- [ ] Page title displays "Server Alert Count Distribution (PostgreSQL)"
- [ ] No console errors in browser developer tools
- [ ] Loading indicator appears briefly while fetching data

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL
- Notes: _______________________________________________

---

## Test 4: Data Display Verification

### 4.1 Table Structure

**Steps:**
1. Verify the table has the correct columns
2. Check that data is displayed in each column

**Expected Results:**
- [ ] Table has 8 columns: Terminal, User, Open, Close, Total, Critical Open, Critical Close, Total Critical
- [ ] Table has Bootstrap styling (striped, hover effects)
- [ ] Header row is clearly visible
- [ ] Data rows are populated with alert counts

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL
- Terminal count displayed: _______________
- Notes: _______________________________________________

### 4.2 Data Accuracy

**Steps:**
1. Note the shift number displayed
2. Note the time range displayed
3. Verify grand totals in footer row
4. Compare with database query results if possible

**Expected Results:**
- [ ] Current shift is displayed (1, 2, or 3)
- [ ] Shift time range is displayed correctly
- [ ] Grand totals row shows sum of all columns
- [ ] Grand totals match sum of individual terminal rows

**Actual Results:**
- Current shift: _______________
- Time range: _______________ to _______________
- Grand total alerts: _______________
- Status: [ ] PASS / [ ] FAIL
- Notes: _______________________________________________

### 4.3 Username Display

**Steps:**
1. Check if usernames are displayed for terminals
2. Verify username capitalization (first letter of each word capitalized)

**Expected Results:**
- [ ] Usernames are displayed in the "User" column
- [ ] Usernames are properly capitalized (e.g., "John Doe" not "john doe")
- [ ] Terminals without users show empty username field

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL
- Sample usernames: _______________________________________________
- Notes: _______________________________________________

---

## Test 5: Auto-Refresh Functionality

### 5.1 Verify Auto-Refresh

**Steps:**
1. Note the current alert counts for a specific terminal
2. Wait 5 seconds
3. Observe if the table updates automatically
4. Check browser network tab for API calls

**Expected Results:**
- [ ] Table data refreshes every 5 seconds
- [ ] No full page reload occurs
- [ ] Network tab shows GET request to `/api/dashboard/postgres/data` every 5 seconds
- [ ] Loading indicator does NOT appear during refresh (seamless update)

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL
- Refresh interval observed: _______________ seconds
- Notes: _______________________________________________

### 5.2 New Terminal Addition

**Steps:**
1. If possible, add a new alert to a different terminal in the database
2. Wait for auto-refresh (5 seconds)
3. Verify new terminal appears in the table

**Expected Results:**
- [ ] New terminal row appears after refresh
- [ ] Existing terminal rows remain visible
- [ ] Table updates without losing scroll position

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL / [ ] SKIPPED (no new data available)
- Notes: _______________________________________________

---

## Test 6: Interactive Modal - Open Alerts

### 6.1 Click on Open Alert Count

**Steps:**
1. Identify a terminal with open alerts (Open column > 0)
2. Hover over the "Open" count cell
3. Verify cursor changes to pointer
4. Click on the "Open" count cell

**Expected Results:**
- [ ] Cursor changes to pointer on hover
- [ ] Modal window opens
- [ ] Modal title shows terminal IP and "Open Alerts"
- [ ] Loading indicator appears briefly

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL
- Terminal tested: _______________
- Notes: _______________________________________________

### 6.2 Verify Modal Content

**Steps:**
1. Wait for modal data to load
2. Verify table columns in modal
3. Check data accuracy

**Expected Results:**
- [ ] Modal displays table with columns: Sr no, ATMID, Panel ID, Zone, City, Received At, Alert Type, Comment, Close By, Closed At
- [ ] Serial numbers start from 1 and increment
- [ ] Alert details are displayed correctly
- [ ] Number of rows matches the count clicked
- [ ] All alerts shown have status='O' (Open)

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL
- Number of alerts displayed: _______________
- Sample alert data verified: [ ] YES / [ ] NO
- Notes: _______________________________________________

### 6.3 Close Modal

**Steps:**
1. Click the "Close" button in modal
2. Verify modal closes

**Expected Results:**
- [ ] Modal closes when Close button is clicked
- [ ] Dashboard table remains visible
- [ ] No errors in console

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL
- Notes: _______________________________________________

---

## Test 7: Interactive Modal - Close Alerts

### 7.1 Click on Close Alert Count

**Steps:**
1. Identify a terminal with close alerts (Close column > 0)
2. Click on the "Close" count cell

**Expected Results:**
- [ ] Modal opens with "Close Alerts" in title
- [ ] All alerts shown have status='C' (Close)
- [ ] "Closed By" and "Closed At" columns have values

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL
- Terminal tested: _______________
- Notes: _______________________________________________

---

## Test 8: Interactive Modal - Total Alerts

### 8.1 Click on Total Alert Count

**Steps:**
1. Click on the "Total" count cell for any terminal

**Expected Results:**
- [ ] Modal opens with "Total Alerts" in title
- [ ] All alerts shown (both Open and Close)
- [ ] Number of alerts = Open + Close for that terminal

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL
- Terminal tested: _______________
- Open count: _______________, Close count: _______________, Total shown: _______________
- Notes: _______________________________________________

---

## Test 9: Interactive Modal - Critical Alerts

### 9.1 Click on Critical Open Alert Count

**Steps:**
1. Identify a terminal with critical open alerts (Critical Open column > 0)
2. Click on the "Critical Open" count cell

**Expected Results:**
- [ ] Modal opens with "Critical Open Alerts" in title
- [ ] All alerts shown have status='O' AND critical_alerts='y'
- [ ] Number of alerts matches the count clicked

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL / [ ] SKIPPED (no critical alerts)
- Terminal tested: _______________
- Notes: _______________________________________________

### 9.2 Click on Critical Close Alert Count

**Steps:**
1. Identify a terminal with critical close alerts (Critical Close column > 0)
2. Click on the "Critical Close" count cell

**Expected Results:**
- [ ] Modal opens with "Critical Close Alerts" in title
- [ ] All alerts shown have status='C' AND critical_alerts='y'

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL / [ ] SKIPPED (no critical alerts)
- Notes: _______________________________________________

### 9.3 Click on Total Critical Alert Count

**Steps:**
1. Click on the "Total Critical" count cell for any terminal

**Expected Results:**
- [ ] Modal opens with "Total Critical Alerts" in title
- [ ] All alerts shown have critical_alerts='y'
- [ ] Number of alerts = Critical Open + Critical Close

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL / [ ] SKIPPED (no critical alerts)
- Notes: _______________________________________________

---

## Test 10: Site Data Enrichment

### 10.1 Verify Site Information in Modal

**Steps:**
1. Open any modal with alert details
2. Check if ATMID, Zone, and City columns have values

**Expected Results:**
- [ ] ATMID column shows ATM IDs where available
- [ ] Zone column shows zones where available
- [ ] City column shows cities where available
- [ ] Missing site data shows as empty cells (not errors)

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL
- Sample site data found: [ ] YES / [ ] NO
- Notes: _______________________________________________

---

## Test 11: Different Shift Testing

### 11.1 Test Shift 1 (07:00-14:59)

**Steps:**
1. If current time is NOT in Shift 1, manually test by:
   - Using browser dev tools to modify API call
   - OR changing server time temporarily
   - OR waiting until Shift 1 time
2. Verify dashboard shows Shift 1 data

**Expected Results:**
- [ ] Dashboard displays "Shift: 1"
- [ ] Time range shows 07:00:00 to 14:59:59
- [ ] Data is filtered for Shift 1 time range
- [ ] Single partition table queried (alerts_YYYY_MM_DD)

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL / [ ] SKIPPED (not in Shift 1)
- Notes: _______________________________________________

### 11.2 Test Shift 2 (15:00-22:59)

**Steps:**
1. Test during Shift 2 time or manually override

**Expected Results:**
- [ ] Dashboard displays "Shift: 2"
- [ ] Time range shows 15:00:00 to 22:59:59
- [ ] Data is filtered for Shift 2 time range

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL / [ ] SKIPPED (not in Shift 2)
- Notes: _______________________________________________

### 11.3 Test Shift 3 (23:00-06:59)

**Steps:**
1. Test during Shift 3 time or manually override

**Expected Results:**
- [ ] Dashboard displays "Shift: 3"
- [ ] Time range spans two dates (23:00:00 today to 06:59:59 tomorrow)
- [ ] Two partition tables queried (current date and next date)

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL / [ ] SKIPPED (not in Shift 3)
- Notes: _______________________________________________

---

## Test 12: Error Handling

### 12.1 Non-Existent Partition

**Steps:**
1. If possible, test with a date that has no partition table
2. Verify graceful handling

**Expected Results:**
- [ ] No error displayed to user
- [ ] Empty table or "No data available" message
- [ ] No console errors

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL / [ ] SKIPPED (all partitions exist)
- Notes: _______________________________________________

### 12.2 Network Error Simulation

**Steps:**
1. Open browser dev tools
2. Go to Network tab
3. Enable "Offline" mode
4. Wait for auto-refresh to trigger

**Expected Results:**
- [ ] Error message displayed to user
- [ ] Previous data remains visible
- [ ] No application crash

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL / [ ] SKIPPED
- Notes: _______________________________________________

---

## Test 13: Authentication and Authorization

### 13.1 Unauthenticated Access

**Steps:**
1. Log out of the application
2. Try to access `/dashboard/postgres` directly

**Expected Results:**
- [ ] Redirected to login page
- [ ] Cannot access dashboard without authentication

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL
- Notes: _______________________________________________

### 13.2 Unauthorized Access

**Steps:**
1. Log in with a user that does NOT have 'dashboard.view' permission
2. Try to access the dashboard

**Expected Results:**
- [ ] Dashboard link not visible in navigation menu
- [ ] Direct URL access returns 403 Forbidden or redirects
- [ ] Error message displayed

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL / [ ] SKIPPED (no test user available)
- Notes: _______________________________________________

---

## Test 14: Performance

### 14.1 Page Load Time

**Steps:**
1. Clear browser cache
2. Reload dashboard page
3. Measure time from navigation to data display

**Expected Results:**
- [ ] Initial page load completes within 2 seconds
- [ ] Data fetch completes within 1 second
- [ ] No performance warnings in console

**Actual Results:**
- Page load time: _______________ seconds
- Data fetch time: _______________ seconds
- Status: [ ] PASS / [ ] FAIL
- Notes: _______________________________________________

### 14.2 Auto-Refresh Performance

**Steps:**
1. Leave dashboard open for 1 minute
2. Observe memory usage and responsiveness

**Expected Results:**
- [ ] No memory leaks (memory usage stable)
- [ ] UI remains responsive
- [ ] No lag or freezing

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL
- Notes: _______________________________________________

---

## Test 15: Browser Compatibility

### 15.1 Chrome/Edge

**Steps:**
1. Test all functionality in Chrome or Edge

**Expected Results:**
- [ ] All features work correctly

**Actual Results:**
- Browser version: _______________
- Status: [ ] PASS / [ ] FAIL
- Notes: _______________________________________________

### 15.2 Firefox

**Steps:**
1. Test all functionality in Firefox

**Expected Results:**
- [ ] All features work correctly

**Actual Results:**
- Browser version: _______________
- Status: [ ] PASS / [ ] FAIL / [ ] SKIPPED
- Notes: _______________________________________________

---

## Test 16: Responsive Design

### 16.1 Desktop View (1920x1080)

**Steps:**
1. Test at full desktop resolution

**Expected Results:**
- [ ] Table displays all columns clearly
- [ ] No horizontal scrolling needed
- [ ] Modal is centered and readable

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL
- Notes: _______________________________________________

### 16.2 Laptop View (1366x768)

**Steps:**
1. Resize browser to laptop resolution

**Expected Results:**
- [ ] Table remains usable
- [ ] Horizontal scroll available if needed
- [ ] Modal fits within viewport

**Actual Results:**
- Status: [ ] PASS / [ ] FAIL / [ ] SKIPPED
- Notes: _______________________________________________

---

## Overall Test Summary

### Tests Passed: _____ / _____

### Critical Issues Found:
1. _______________________________________________
2. _______________________________________________
3. _______________________________________________

### Minor Issues Found:
1. _______________________________________________
2. _______________________________________________
3. _______________________________________________

### Recommendations:
1. _______________________________________________
2. _______________________________________________
3. _______________________________________________

---

## Sign-Off

**Tester Name:** _______________________________________________

**Date:** _______________________________________________

**Overall Status:** [ ] APPROVED / [ ] APPROVED WITH ISSUES / [ ] REJECTED

**Notes:** _______________________________________________
_______________________________________________
_______________________________________________

