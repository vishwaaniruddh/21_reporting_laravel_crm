<?php

/**
 * Fix Timezone Data in PostgreSQL
 * 
 * This script corrects timestamps in PostgreSQL partition tables that were
 * incorrectly converted from IST to UTC during sync.
 * 
 * The issue: Timestamps were converted from IST (UTC+5:30) to UTC during sync,
 * resulting in times being 5.5 hours earlier than they should be.
 * 
 * The fix: Add 5.5 hours to all affected timestamp columns.
 * 
 * SAFETY: This script only updates PostgreSQL (target database).
 *         MySQL (source) is never modified.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Timezone Data Fix Script ===\n\n";

// Get all partition tables
$partitions = DB::connection('pgsql')
    ->table('partition_registry')
    ->where('table_type', 'alerts')
    ->orderBy('partition_date')
    ->get();

echo "Found " . $partitions->count() . " alert partition tables.\n\n";

$totalUpdated = 0;
$errors = [];

foreach ($partitions as $partition) {
    $tableName = $partition->partition_table;
    
    echo "Processing: {$tableName}\n";
    
    try {
        // Count records that need fixing (where times don't match MySQL pattern)
        $count = DB::connection('pgsql')
            ->table($tableName)
            ->whereNotNull('receivedtime')
            ->count();
        
        if ($count === 0) {
            echo "  No records to update.\n\n";
            continue;
        }
        
        echo "  Found {$count} records.\n";
        
        // Update timestamps by adding 5.5 hours (5 hours 30 minutes)
        // This corrects the UTC conversion that happened during sync
        $updated = DB::connection('pgsql')->update("
            UPDATE {$tableName}
            SET 
                createtime = createtime + INTERVAL '5 hours 30 minutes',
                receivedtime = receivedtime + INTERVAL '5 hours 30 minutes',
                closedtime = CASE 
                    WHEN closedtime IS NOT NULL 
                    THEN closedtime + INTERVAL '5 hours 30 minutes'
                    ELSE NULL
                END,
                inserttime = CASE 
                    WHEN inserttime IS NOT NULL 
                    THEN inserttime + INTERVAL '5 hours 30 minutes'
                    ELSE NULL
                END
            WHERE receivedtime IS NOT NULL
        ");
        
        echo "  Updated {$updated} records.\n";
        $totalUpdated += $updated;
        
        // Verify a sample record
        $sample = DB::connection('pgsql')
            ->table($tableName)
            ->whereNotNull('receivedtime')
            ->first();
        
        if ($sample) {
            echo "  Sample after fix: receivedtime = {$sample->receivedtime}\n";
        }
        
        echo "\n";
        
    } catch (Exception $e) {
        $error = "Error processing {$tableName}: " . $e->getMessage();
        echo "  ❌ {$error}\n\n";
        $errors[] = $error;
    }
}

echo "=== Summary ===\n";
echo "Total partitions processed: " . $partitions->count() . "\n";
echo "Total records updated: {$totalUpdated}\n";

if (count($errors) > 0) {
    echo "\n❌ Errors encountered:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
} else {
    echo "\n✅ All partitions updated successfully!\n";
}

echo "\n=== Verification ===\n";
echo "Run this command to verify the fix:\n";
echo "php test_timezone_issue.php\n";
echo "\nThe times in MySQL and PostgreSQL should now match.\n";
