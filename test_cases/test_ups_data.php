<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Testing UPS Report Data ===\n\n";

// Test 1: Check if we have any alerts for today
echo "1. Checking alerts for today (2026-02-16):\n";
$todayAlerts = DB::connection('pgsql')->select("
    SELECT COUNT(*) as count 
    FROM alerts 
    WHERE receivedtime::date = '2026-02-16'
");
echo "Total alerts today: " . $todayAlerts[0]->count . "\n\n";

// Test 2: Check what zones exist in alerts
echo "2. Checking what zones exist in alerts:\n";
$zones = DB::connection('pgsql')->select("
    SELECT DISTINCT zone, COUNT(*) as count
    FROM alerts 
    WHERE receivedtime::date = '2026-02-16'
    GROUP BY zone
    ORDER BY count DESC
    LIMIT 20
");
foreach ($zones as $zone) {
    echo "Zone: {$zone->zone} - Count: {$zone->count}\n";
}
echo "\n";

// Test 3: Check what alarms exist
echo "3. Checking what alarms exist:\n";
$alarms = DB::connection('pgsql')->select("
    SELECT DISTINCT alarm, COUNT(*) as count
    FROM alerts 
    WHERE receivedtime::date = '2026-02-16'
    GROUP BY alarm
    ORDER BY count DESC
    LIMIT 20
");
foreach ($alarms as $alarm) {
    echo "Alarm: {$alarm->alarm} - Count: {$alarm->count}\n";
}
echo "\n";

// Test 4: Check panel makes in sites
echo "4. Checking Panel Makes in sites:\n";
$panelMakes = DB::connection('pgsql')->select("
    SELECT DISTINCT \"Panel_Make\", COUNT(*) as count
    FROM sites
    WHERE \"Panel_Make\" IS NOT NULL
    GROUP BY \"Panel_Make\"
    ORDER BY count DESC
    LIMIT 20
");
foreach ($panelMakes as $pm) {
    echo "Panel Make: {$pm->Panel_Make} - Count: {$pm->count}\n";
}
echo "\n";

// Test 5: Check for RASS panels with zones 029/030
echo "5. Checking RASS panels with zones 029/030:\n";
$rass = DB::connection('pgsql')->select("
    SELECT COUNT(*) as count
    FROM sites s
    INNER JOIN alerts a ON (s.\"OldPanelID\" = a.panelid OR s.\"NewPanelID\" = a.panelid)
    WHERE s.\"Panel_Make\" IN ('RASS','rass_boi','rass_pnb','rass_sbi')
        AND a.zone IN ('029','030')
        AND a.alarm IN ('AT', 'AR')
        AND a.receivedtime::date = '2026-02-16'
");
echo "RASS alerts: " . $rass[0]->count . "\n\n";

// Test 6: Check for SMART-I panels with zones 001/002
echo "6. Checking SMART-I panels with zones 001/002:\n";
$smarti = DB::connection('pgsql')->select("
    SELECT COUNT(*) as count
    FROM sites s
    INNER JOIN alerts a ON (s.\"OldPanelID\" = a.panelid OR s.\"NewPanelID\" = a.panelid)
    WHERE s.\"Panel_Make\" IN ('SMART -I','SMART -IN','smarti_boi','smarti_pnb')
        AND a.zone IN ('001','002')
        AND a.alarm IN ('BA', 'BR')
        AND a.receivedtime::date = '2026-02-16'
");
echo "SMART-I alerts: " . $smarti[0]->count . "\n\n";

// Test 7: Check for Securico panels with zones 551/552
echo "7. Checking Securico panels with zones 551/552:\n";
$securico = DB::connection('pgsql')->select("
    SELECT COUNT(*) as count
    FROM sites s
    INNER JOIN alerts a ON (s.\"OldPanelID\" = a.panelid OR s.\"NewPanelID\" = a.panelid)
    WHERE s.\"Panel_Make\" IN ('securico_gx4816','sec_sbi')
        AND a.zone IN ('551','552')
        AND a.alarm IN ('BA', 'BR')
        AND a.receivedtime::date = '2026-02-16'
");
echo "Securico alerts: " . $securico[0]->count . "\n\n";

// Test 8: Check for SEC panels with zones 027/028
echo "8. Checking SEC panels with zones 027/028:\n";
$sec = DB::connection('pgsql')->select("
    SELECT COUNT(*) as count
    FROM sites s
    INNER JOIN alerts a ON (s.\"OldPanelID\" = a.panelid OR s.\"NewPanelID\" = a.panelid)
    WHERE s.\"Panel_Make\" IN ('SEC')
        AND a.zone IN ('027','028')
        AND a.alarm IN ('BA', 'BR')
        AND a.receivedtime::date = '2026-02-16'
");
echo "SEC alerts: " . $sec[0]->count . "\n\n";

// Test 9: Check a different date range (last 7 days)
echo "9. Checking last 7 days for any UPS-related alerts:\n";
$lastWeek = DB::connection('pgsql')->select("
    SELECT 
        receivedtime::date as date,
        COUNT(*) as count
    FROM alerts
    WHERE receivedtime::date >= CURRENT_DATE - INTERVAL '7 days'
        AND (
            (zone IN ('029','030') AND alarm IN ('AT','AR'))
            OR (zone IN ('001','002') AND alarm IN ('BA','BR'))
            OR (zone IN ('551','552') AND alarm IN ('BA','BR'))
            OR (zone IN ('027','028') AND alarm IN ('BA','BR'))
        )
    GROUP BY receivedtime::date
    ORDER BY date DESC
");
foreach ($lastWeek as $day) {
    echo "Date: {$day->date} - Count: {$day->count}\n";
}

echo "\n=== Test Complete ===\n";
