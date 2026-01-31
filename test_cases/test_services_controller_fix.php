<?php

/**
 * Test Services Controller Fix
 * 
 * This script verifies that all services are now included in the ServiceManagementController
 */

echo "=== Services Controller Fix Verification ===\n\n";

// Check if the controller file exists and has been updated
$controllerPath = __DIR__ . '/app/Http/Controllers/ServiceManagementController.php';

if (!file_exists($controllerPath)) {
    echo "❌ ServiceManagementController.php not found!\n";
    exit(1);
}

$content = file_get_contents($controllerPath);

// Expected services that should be in the controller
$expectedServices = [
    'AlertInitialSync',
    'AlertUpdateSync', 
    'AlertBackupSync',
    'AlertCleanup',
    'AlertMysqlBackup',
    'AlertPortal',
    'AlertViteDev',
    'BackAlertUpdateSync',
    'SitesUpdateSync',
];

echo "Checking for all expected services in controller...\n\n";

$foundServices = [];
$missingServices = [];

foreach ($expectedServices as $service) {
    if (strpos($content, "'{$service}'") !== false) {
        $foundServices[] = $service;
        echo "✅ {$service} - FOUND\n";
    } else {
        $missingServices[] = $service;
        echo "❌ {$service} - MISSING\n";
    }
}

echo "\n=== Summary ===\n";
echo "Total Expected: " . count($expectedServices) . "\n";
echo "Found: " . count($foundServices) . "\n";
echo "Missing: " . count($missingServices) . "\n\n";

if (empty($missingServices)) {
    echo "🎉 ALL SERVICES ARE NOW INCLUDED IN THE CONTROLLER!\n\n";
    echo "The services page at http://192.168.100.21:9000/services should now show all 9 services:\n";
    foreach ($foundServices as $service) {
        echo "  • {$service}\n";
    }
} else {
    echo "⚠️  Some services are still missing:\n";
    foreach ($missingServices as $service) {
        echo "  • {$service}\n";
    }
}

echo "\n=== Log File Mappings Check ===\n";

// Check if log file mappings are updated
$logMappings = [
    'AlertBackupSync' => 'backup-sync-service.log',
    'BackAlertUpdateSync' => 'backalert-update-sync-service.log', 
    'SitesUpdateSync' => 'sites-update-sync-service.log',
];

foreach ($logMappings as $service => $logFile) {
    if (strpos($content, "'{$service}' => '{$logFile}'") !== false) {
        echo "✅ {$service} log mapping - FOUND\n";
    } else {
        echo "❌ {$service} log mapping - MISSING\n";
    }
}

echo "\n=== Next Steps ===\n";
echo "1. Refresh the services page: http://192.168.100.21:9000/services\n";
echo "2. All 9 services should now be visible\n";
echo "3. You can start/stop/restart services from the web interface\n";

echo "\n=== Test Complete ===\n";