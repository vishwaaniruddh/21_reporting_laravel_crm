<?php

require_once 'vendor/autoload.php';

// Load Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Testing BackAlerts Trigger ===\n";

try {
    // Check current triggers
    $triggers = DB::connection('mysql')->select("SHOW TRIGGERS LIKE 'backalerts'");
    echo "Current backalerts triggers:\n";
    foreach ($triggers as $trigger) {
        echo "- {$trigger->Trigger} ({$trigger->Event})\n";
    }
    
    echo "\n=== Current Update Log Count ===\n";
    $logCount = DB::connection('mysql')->table('backalert_pg_update_log')->count();
    echo "Records in backalert_pg_update_log: {$logCount}\n";
    
    echo "\n=== Testing Trigger (Update a backalert record) ===\n";
    
    // Get a sample backalert record
    $sampleRecord = DB::connection('mysql')->table('backalerts')->first();
    
    if ($sampleRecord) {
        echo "Found sample record ID: {$sampleRecord->id}\n";
        echo "Current comment: " . ($sampleRecord->comment ?? 'NULL') . "\n";
        
        // Update the record to trigger the UPDATE trigger
        $testComment = 'Test trigger - ' . date('Y-m-d H:i:s');
        DB::connection('mysql')->table('backalerts')
            ->where('id', $sampleRecord->id)
            ->update(['comment' => $testComment]);
        
        echo "Updated comment to: {$testComment}\n";
        
        // Check if trigger created a log entry
        $newLogCount = DB::connection('mysql')->table('backalert_pg_update_log')->count();
        echo "New log count: {$newLogCount}\n";
        
        if ($newLogCount > $logCount) {
            echo "✓ Trigger working! New log entry created.\n";
            
            // Show the latest log entry
            $latestLog = DB::connection('mysql')->table('backalert_pg_update_log')
                ->orderBy('id', 'desc')
                ->first();
            
            echo "Latest log entry:\n";
            echo "  ID: {$latestLog->id}\n";
            echo "  BackAlert ID: {$latestLog->backalert_id}\n";
            echo "  Status: {$latestLog->status}\n";
            echo "  Created: {$latestLog->created_at}\n";
        } else {
            echo "✗ Trigger not working - no new log entry created.\n";
        }
        
        // Restore original comment
        DB::connection('mysql')->table('backalerts')
            ->where('id', $sampleRecord->id)
            ->update(['comment' => $sampleRecord->comment]);
        
        echo "Restored original comment.\n";
        
    } else {
        echo "No backalerts records found to test with.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}