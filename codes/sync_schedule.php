<?php

$currentTime = new DateTime();
$currentMinute = (int)$currentTime->format('i');

// Partitioned sync runs every 20 minutes (at :00, :20, :40)
$nextSyncMinute = ceil($currentMinute / 20) * 20;
if ($nextSyncMinute >= 60) {
    $nextSyncMinute = 0;
    $nextSyncTime = (clone $currentTime)->modify('+1 hour')->setTime((int)$currentTime->format('H') + 1, 0);
} else {
    $nextSyncTime = (clone $currentTime)->setTime((int)$currentTime->format('H'), $nextSyncMinute);
}

$minutesUntilSync = ($nextSyncTime->getTimestamp() - $currentTime->getTimestamp()) / 60;

echo "=== Sync Schedule ===\n\n";
echo "Current time: " . $currentTime->format('Y-m-d H:i:s') . "\n";
echo "Next partitioned sync: " . $nextSyncTime->format('H:i:s') . "\n";
echo "Time until next sync: " . round($minutesUntilSync) . " minutes\n\n";

echo "Sync runs every 20 minutes at:\n";
echo "- :00 (top of the hour)\n";
echo "- :20 (20 minutes past)\n";
echo "- :40 (40 minutes past)\n\n";

echo "Each sync processes 10 batches (~100,000 records)\n";
echo "Estimated time to complete remaining 484,232 records: ~100 minutes (5 sync cycles)\n";
