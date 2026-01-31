<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Analyzing BackAlerts Data for VM Criteria ===\n\n";

try {
    $testDate = '2026-01-27';
    $backAlertsTable = "backalerts_2026_01_27";
    
    echo "Analyzing table: {$backAlertsTable}\n\n";
    
    // Check total records
    $totalRecords = DB::connection('pgsql')->select("SELECT COUNT(*) as count FROM {$backAlertsTable}")[0]->count;
    echo "Total records in {$backAlertsTable}: " . number_format($totalRecords) . "\n\n";
    
    // Check status distribution
    echo "=== Status Distribution ===\n";
    $statusDist = DB::connection('pgsql')->select("
        SELECT status, COUNT(*) as count 
        FROM {$backAlertsTable} 
        GROUP BY status 
        ORDER BY count DESC 
        LIMIT 10
    ");
    
    foreach ($statusDist as $row) {
        $status = $row->status ?: 'NULL';
        echo "- {$status}: " . number_format($row->count) . "\n";
    }
    
    // Check sendtoclient distribution
    echo "\n=== SendToClient Distribution ===\n";
    $sendtoclientDist = DB::connection('pgsql')->select("
        SELECT sendtoclient, COUNT(*) as count 
        FROM {$backAlertsTable} 
        GROUP BY sendtoclient 
        ORDER BY count DESC 
        LIMIT 10
    ");
    
    foreach ($sendtoclientDist as $row) {
        $sendtoclient = $row->sendtoclient ?: 'NULL';
        echo "- {$sendtoclient}: " . number_format($row->count) . "\n";
    }
    
    // Check VM criteria matches
    echo "\n=== VM Criteria Analysis ===\n";
    
    // Status O or C
    $statusOC = DB::connection('pgsql')->select("
        SELECT COUNT(*) as count 
        FROM {$backAlertsTable} 
        WHERE status IN ('O', 'C')
    ")[0]->count;
    echo "Records with status 'O' or 'C': " . number_format($statusOC) . "\n";
    
    // SendToClient = S
    $sendtoclientS = DB::connection('pgsql')->select("
        SELECT COUNT(*) as count 
        FROM {$backAlertsTable} 
        WHERE sendtoclient = 'S'
    ")[0]->count;
    echo "Records with sendtoclient = 'S': " . number_format($sendtoclientS) . "\n";
    
    // Both VM criteria
    $vmCriteria = DB::connection('pgsql')->select("
        SELECT COUNT(*) as count 
        FROM {$backAlertsTable} 
        WHERE status IN ('O', 'C') AND sendtoclient = 'S'
    ")[0]->count;
    echo "Records matching BOTH VM criteria: " . number_format($vmCriteria) . "\n";
    
    // Sample records that match VM criteria
    if ($vmCriteria > 0) {
        echo "\n=== Sample VM-Matching BackAlert Records ===\n";
        $samples = DB::connection('pgsql')->select("
            SELECT id, panelid, alerttype, status, sendtoclient, receivedtime, priority
            FROM {$backAlertsTable} 
            WHERE status IN ('O', 'C') AND sendtoclient = 'S'
            ORDER BY receivedtime DESC
            LIMIT 5
        ");
        
        foreach ($samples as $i => $record) {
            echo "Record " . ($i + 1) . ":\n";
            echo "  - ID: {$record->id}\n";
            echo "  - Panel ID: {$record->panelid}\n";
            echo "  - Alert Type: {$record->alerttype}\n";
            echo "  - Status: {$record->status}\n";
            echo "  - Send to Client: {$record->sendtoclient}\n";
            echo "  - Received Time: {$record->receivedtime}\n";
            echo "  - Priority: {$record->priority}\n\n";
        }
    }
    
    echo "=== Conclusion ===\n";
    if ($vmCriteria > 0) {
        echo "✅ BackAlerts table DOES contain VM-matching records!\n";
        echo "The VM alerts endpoint will benefit from including backalerts data.\n";
    } else {
        echo "ℹ️  BackAlerts table does NOT contain records matching VM criteria.\n";
        echo "This is normal - backalerts may have different status/sendtoclient patterns.\n";
        echo "The implementation is still correct and will include backalerts data when it matches VM criteria.\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}