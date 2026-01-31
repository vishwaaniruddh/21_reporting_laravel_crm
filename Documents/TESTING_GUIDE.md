# Testing Guide: Date-Partitioned Alerts Sync System

## Quick Start

### 1. Servers Are Running ✅

Both servers are now running:
- **Backend (Laravel):** http://127.0.0.1:8000
- **Frontend (Vite):** http://127.0.0.1:5173

### 2. Access the Application

**Open your browser and go to:**
```
http://127.0.0.1:8000
```

This will redirect you to the login page.

### 3. Login

You need to login with a user account. If you haven't created one yet, you can:

**Option A: Use the superadmin seeder**
```bash
php artisan db:seed --class=SuperadminSeeder
```

This creates a default superadmin user:
- **Email:** `admin@example.com`
- **Password:** `password`

**Option B: Create a user via tinker**
```bash
php artisan tinker
```
Then run:
```php
$user = new \App\Models\User();
$user->name = 'Test User';
$user->email = 'test@example.com';
$user->password = bcrypt('password');
$user->is_active = true;
$user->save();
```

### 4. Navigate to Partition Management

After logging in, you can access the partition management dashboard at:
```
http://127.0.0.1:8000/partitions
```

Or click on "Partitions" in the navigation menu.

## Testing the Partition System

### Test 1: View Existing Partitions

1. Go to http://127.0.0.1:8000/partitions
2. You should see a list of all partition tables
3. Each partition shows:
   - Table name (e.g., `alerts_2026_01_08`)
   - Partition date
   - Record count
   - Last synced timestamp

### Test 2: Trigger Manual Sync

1. On the partition dashboard, click "Trigger Sync" button
2. Watch the sync progress
3. Partition list should update with new records

### Test 3: View Partition Details

1. Click on a specific partition in the list
2. View detailed information:
   - Record count
   - Date range
   - Sync history

### Test 4: Query Across Partitions

1. Use the date range picker to select a date range
2. Click "Query" to see alerts across multiple partitions
3. Results should combine data from all partitions in the range

## API Testing (Alternative)

If you prefer to test via API directly:

### 1. Get Authentication Token

```bash
curl -X POST http://127.0.0.1:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}'
```

Save the token from the response.

### 2. List Partitions

```bash
curl -X GET http://127.0.0.1:8000/api/sync/partitions \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

### 3. Trigger Sync

```bash
curl -X POST http://127.0.0.1:8000/api/sync/partitioned/trigger \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Content-Type: application/json" \
  -d '{"batch_size":100}'
```

### 4. Query Partitions

```bash
curl -X GET "http://127.0.0.1:8000/api/reports/partitioned/query?start_date=2026-01-01&end_date=2026-01-31" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

## Command Line Testing

### Run Partition Sync

```bash
php artisan sync:partitioned --batch-size=100
```

### View Partition Registry

```bash
php artisan tinker
```
Then:
```php
\App\Models\PartitionRegistry::all()->each(function($p) {
    echo "{$p->table_name} - {$p->partition_date} - {$p->record_count} records\n";
});
```

### Check Partition Tables in PostgreSQL

```bash
php artisan tinker
```
Then:
```php
DB::connection('pgsql')
    ->table('information_schema.tables')
    ->where('table_schema', 'public')
    ->where('table_name', 'like', 'alerts_%')
    ->pluck('table_name');
```

## Troubleshooting

### Issue: "http://localhost:9000/dashboard is not loading"

**Solution:** The application runs on port 8000, not 9000. Use:
```
http://127.0.0.1:8000/dashboard
```

### Issue: "Redirected to login page"

**Solution:** You need to login first. The dashboard is protected.

1. Go to http://127.0.0.1:8000/login
2. Login with your credentials
3. Then navigate to /dashboard or /partitions

### Issue: "No partitions showing"

**Solution:** Run a sync first to create partitions:
```bash
php artisan sync:partitioned --batch-size=50
```

### Issue: "401 Unauthorized"

**Solution:** Your session expired. Login again.

### Issue: "Connection refused"

**Solution:** Make sure both servers are running:
```bash
# Check if processes are running
# Backend should be on port 8000
# Frontend should be on port 5173
```

## Stopping the Servers

When you're done testing, you can stop the servers:

1. Press `Ctrl+C` in the terminal where they're running
2. Or use the Kiro process management to stop them

## Next Steps

After testing the UI:

1. **Test the sync pipeline:**
   - Run `php artisan sync:partitioned`
   - Watch partitions being created
   - Verify data in PostgreSQL

2. **Test cross-partition queries:**
   - Use the partition dashboard
   - Select a date range spanning multiple partitions
   - Verify results are complete

3. **Test error handling:**
   - Try querying non-existent date ranges
   - Verify graceful handling

4. **Monitor performance:**
   - Check sync speed
   - Monitor query response times
   - Review logs for any issues

## Useful Commands

```bash
# View Laravel logs
tail -f storage/logs/laravel.log

# Clear cache
php artisan cache:clear
php artisan config:clear

# Run tests
php artisan test --filter=FinalSystemIntegrationTest

# Check database connections
php artisan tinker
DB::connection('mysql')->getPdo();
DB::connection('pgsql')->getPdo();
```

---

**Happy Testing! 🚀**

For issues or questions, check the implementation summaries:
- TASK_13_IMPLEMENTATION_SUMMARY.md (UI)
- TASK_15_IMPLEMENTATION_SUMMARY.md (Integration tests)
- docs/PARTITION_SYNC_GUIDE.md (Detailed guide)
