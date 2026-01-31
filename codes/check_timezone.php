<?php

echo "=== Timezone Information ===\n\n";

// UTC time (what Laravel scheduler uses)
date_default_timezone_set('UTC');
$utcTime = date('H:i:s');
$utcMinute = (int)date('i');
echo "Current UTC time: $utcTime\n";

// India time
date_default_timezone_set('Asia/Kolkata');
$indiaTime = date('H:i:s');
echo "Current India time: $indiaTime\n\n";

// Calculate next sync (based on UTC)
date_default_timezone_set('UTC');
$currentMinute = (int)date('i');
$currentHour = (int)date('H');

// Find next :00, :20, or :40
if ($currentMinute < 20) {
    $nextSyncMinute = 20;
    $nextSyncHour = $currentHour;
} elseif ($currentMinute < 40) {
    $nextSyncMinute = 40;
    $nextSyncHour = $currentHour;
} else {
    $nextSyncMinute = 0;
    $nextSyncHour = ($currentHour + 1) % 24;
}

$minutesUntilSync = ($nextSyncHour * 60 + $nextSyncMinute) - ($currentHour * 60 + $currentMinute);
if ($minutesUntilSync < 0) {
    $minutesUntilSync += 24 * 60;
}

echo "=== Sync Schedule (UTC) ===\n\n";
echo "Sync runs at: :00, :20, :40 (UTC)\n";
echo "Next sync at: " . sprintf("%02d:%02d", $nextSyncHour, $nextSyncMinute) . " UTC\n";
echo "Time until next sync: $minutesUntilSync minutes\n\n";

// Convert to India time
$nextSyncTimestamp = mktime($nextSyncHour, $nextSyncMinute, 0);
date_default_timezone_set('Asia/Kolkata');
echo "Next sync at: " . date('H:i', $nextSyncTimestamp) . " India time\n\n";

echo "=== Recent Sync Times (India Time) ===\n\n";
date_default_timezone_set('UTC');
$recentSyncs = [];
for ($i = 0; $i < 5; $i++) {
    $syncMinute = $nextSyncMinute - (20 * ($i + 1));
    $syncHour = $nextSyncHour;
    while ($syncMinute < 0) {
        $syncMinute += 60;
        $syncHour--;
    }
    if ($syncHour < 0) {
        $syncHour += 24;
    }
    
    $syncTimestamp = mktime($syncHour, $syncMinute, 0);
    date_default_timezone_set('Asia/Kolkata');
    $recentSyncs[] = date('H:i', $syncTimestamp) . " India time";
    date_default_timezone_set('UTC');
}

foreach (array_reverse($recentSyncs) as $sync) {
    echo "- $sync\n";
}

date_default_timezone_set('Asia/Kolkata');
echo "- " . date('H:i', $nextSyncTimestamp) . " India time (NEXT)\n";
