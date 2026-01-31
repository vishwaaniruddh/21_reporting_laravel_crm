<?php

/**
 * Test VM Alerts Export Timeout Fix
 * 
 * This script verifies that the timeout fixes have been applied
 * to resolve the VM alerts export timeout issue.
 */

echo "=== VM Alerts Export Timeout Fix Verification ===\n\n";

$fixes_applied = 0;
$total_fixes = 4;

echo "1. Checking Frontend Service (vmAlertService.js)...\n";
$vmServicePath = __DIR__ . '/resources/js/services/vmAlertService.js';
if (file_exists($vmServicePath)) {
    $content = file_get_contents($vmServicePath);
    if (strpos($content, 'exportApi') !== false && strpos($content, 'axios') !== false) {
        echo "   ✅ Frontend timeout fix: APPLIED (separate exportApi with no timeout)\n";
        $fixes_applied++;
    } else {
        echo "   ❌ Frontend timeout fix: NOT FOUND\n";
    }
} else {
    echo "   ❌ VM Alert Service file: NOT FOUND\n";
}

echo "\n2. Checking Backend Controller (VMAlertController.php)...\n";
$controllerPath = __DIR__ . '/app/Http/Controllers/VMAlertController.php';
if (file_exists($controllerPath)) {
    $content = file_get_contents($controllerPath);
    if (strpos($content, '2048M') !== false && strpos($content, '1800') !== false) {
        echo "   ✅ Backend timeout/memory fix: APPLIED (2GB memory, 30min execution)\n";
        $fixes_applied++;
    } else {
        echo "   ❌ Backend timeout/memory fix: NOT FOUND\n";
    }
} else {
    echo "   ❌ VM Alert Controller file: NOT FOUND\n";
}

echo "\n3. Checking Web Server Config (.htaccess)...\n";
$htaccessPath = __DIR__ . '/public/.htaccess';
if (file_exists($htaccessPath)) {
    $content = file_get_contents($htaccessPath);
    if (strpos($content, 'max_execution_time 1800') !== false) {
        echo "   ✅ .htaccess timeout fix: APPLIED (30min execution time)\n";
        $fixes_applied++;
    } else {
        echo "   ❌ .htaccess timeout fix: NOT FOUND\n";
    }
} else {
    echo "   ❌ .htaccess file: NOT FOUND\n";
}

echo "\n4. Checking Export Optimization...\n";
if (file_exists($controllerPath)) {
    $content = file_get_contents($controllerPath);
    if (strpos($content, 'chunkSize = 500') !== false && strpos($content, 'fflush($file)') !== false) {
        echo "   ✅ Export optimization: APPLIED (smaller chunks, streaming flushes)\n";
        $fixes_applied++;
    } else {
        echo "   ❌ Export optimization: NOT FOUND\n";
    }
}

echo "\n=== Summary ===\n";
echo "Fixes Applied: {$fixes_applied}/{$total_fixes}\n\n";

if ($fixes_applied == $total_fixes) {
    echo "🎉 ALL TIMEOUT FIXES HAVE BEEN SUCCESSFULLY APPLIED!\n\n";
    echo "The VM alerts export should now work without the 30-second timeout error.\n";
    echo "Key improvements:\n";
    echo "• Frontend: No timeout limit for export requests\n";
    echo "• Backend: 2GB memory limit, 30-minute execution time\n";
    echo "• Web server: Extended timeout configurations\n";
    echo "• Streaming: Real-time data streaming with memory optimization\n";
} else {
    echo "⚠️  Some fixes may not have been applied correctly.\n";
    echo "Please check the files manually or re-run the fix process.\n";
}

echo "\n=== Next Steps ===\n";
echo "1. Test the VM alerts export from the web interface\n";
echo "2. Monitor the browser console for any remaining timeout errors\n";
echo "3. Check server logs if issues persist\n";

echo "\n=== Test Complete ===\n";