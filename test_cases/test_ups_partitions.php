<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Testing UPS Report Data in Partitions ===\n\n";

// Test 1: Check what partition tables exist
echo "1. Checking partition tables:\n";
$partitions = DB::connection('pgsql')->select("
    SELECT tablename 
    FROM pg_tables 
    WHERE schemaname = 'public' 
        AND tablename LIKE 'alerts_%'
    ORDER BY tablename DESC
    LIMIT 10
");
foreach ($partitions as $partition) {
    echo "Table: {$partition->tablename}\n";
}
echo "\n";

// Test 2: Check the most recent partition
if (!empty($partitions)) {
    $recentPartition = $partitions[0]->tablename;
    echo "2. Checking data in most recent partition: {$recentPartition}\n";
    
    $count = DB::connection('pgsql')->select("
        SELECT COUNT(*) as count FROM {$recentPartition}
    ");
    echo "Total records: " . $count[0]->count . "\n\n";
    
    // Check zones in this partition
    echo "3. Checking zones in {$recentPartition}:\n";
    $zones = DB::connection('pgsql')->select("
        SELECT DISTINCT zone, COUNT(*) as count
        FROM {$recentPartition}
        GROUP BY zone
        ORDER BY count DESC
        LIMIT 20
    ");
    foreach ($zones as $zone) {
        echo "Zone: {$zone->zone} - Count: {$zone->count}\n";
    }
    echo "\n";
    
    // Check alarms in this partition
    echo "4. Checking alarms in {$recentPartition}:\n";
    $alarms = DB::connection('pgsql')->select("
        SELECT DISTINCT alarm, COUNT(*) as count
        FROM {$recentPartition}
        GROUP BY alarm
        ORDER BY count DESC
        LIMIT 20
    ");
    foreach ($alarms as $alarm) {
        echo "Alarm: {$alarm->alarm} - Count: {$alarm->count}\n";
    }
    echo "\n";
    
    // Test 5: Check for UPS-related alerts in this partition
    echo "5. Checking UPS-related alerts in {$recentPartition}:\n";
    
    // RASS
    $rass = DB::connection('pgsql')->select("
        SELECT COUNT(*) as count
        FROM sites s
        INNER JOIN {$recentPartition} a ON (s.\"OldPanelID\" = a.panelid OR s.\"NewPanelID\" = a.panelid)
        WHERE s.\"Panel_Make\" IN ('RASS','rass_boi','rass_pnb','rass_sbi')
            AND a.zone IN ('029','030')
            AND a.alarm IN ('AT', 'AR')
    ");
    echo "RASS alerts: " . $rass[0]->count . "\n";
    
    // SMART-I
    $smarti = DB::connection('pgsql')->select("
        SELECT COUNT(*) as count
        FROM sites s
        INNER JOIN {$recentPartition} a ON (s.\"OldPanelID\" = a.panelid OR s.\"NewPanelID\" = a.panelid)
        WHERE s.\"Panel_Make\" IN ('SMART -I','SMART -IN','smarti_boi','smarti_pnb')
            AND a.zone IN ('001','002')
            AND a.alarm IN ('BA', 'BR')
    ");
    echo "SMART-I alerts: " . $smarti[0]->count . "\n";
    
    // Securico
    $securico = DB::connection('pgsql')->select("
        SELECT COUNT(*) as count
        FROM sites s
        INNER JOIN {$recentPartition} a ON (s.\"OldPanelID\" = a.panelid OR s.\"NewPanelID\" = a.panelid)
        WHERE s.\"Panel_Make\" IN ('securico_gx4816','sec_sbi')
            AND a.zone IN ('551','552')
            AND a.alarm IN ('BA', 'BR')
    ");
    echo "Securico alerts: " . $securico[0]->count . "\n";
    
    // SEC
    $sec = DB::connection('pgsql')->select("
        SELECT COUNT(*) as count
        FROM sites s
        INNER JOIN {$recentPartition} a ON (s.\"OldPanelID\" = a.panelid OR s.\"NewPanelID\" = a.panelid)
        WHERE s.\"Panel_Make\" IN ('SEC')
            AND a.zone IN ('027','028')
            AND a.alarm IN ('BA', 'BR')
    ");
    echo "SEC alerts: " . $sec[0]->count . "\n\n";
    
    // Test 6: Sample some UPS alerts if they exist
    echo "6. Sample UPS alerts (first 5):\n";
    $samples = DB::connection('pgsql')->select("
        SELECT 
            s.\"Customer\",
            s.\"ATMID\",
            s.\"Panel_Make\",
            a.zone,
            a.alarm,
            a.createtime
        FROM sites s
        INNER JOIN {$recentPartition} a ON (s.\"OldPanelID\" = a.panelid OR s.\"NewPanelID\" = a.panelid)
        WHERE (
            (s.\"Panel_Make\" IN ('RASS','rass_boi','rass_pnb','rass_sbi') AND a.zone IN ('029','030') AND a.alarm IN ('AT','AR'))
            OR (s.\"Panel_Make\" IN ('SMART -I','SMART -IN','smarti_boi','smarti_pnb') AND a.zone IN ('001','002') AND a.alarm IN ('BA','BR'))
            OR (s.\"Panel_Make\" IN ('securico_gx4816','sec_sbi') AND a.zone IN ('551','552') AND a.alarm IN ('BA','BR'))
            OR (s.\"Panel_Make\" IN ('SEC') AND a.zone IN ('027','028') AND a.alarm IN ('BA','BR'))
        )
        ORDER BY a.createtime DESC
        LIMIT 5
    ");
    
    if (empty($samples)) {
        echo "No UPS alerts found in this partition.\n";
    } else {
        foreach ($samples as $sample) {
            echo "Customer: {$sample->Customer}, ATMID: {$sample->ATMID}, Panel: {$sample->Panel_Make}, Zone: {$sample->zone}, Alarm: {$sample->alarm}, Time: {$sample->createtime}\n";
        }
    }
}

echo "\n=== Test Complete ===\n";
