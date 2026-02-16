<?php
// Test Redis Extension

echo "<h1>Redis Extension Test</h1>";

// Check if Redis class exists
if (class_exists('Redis')) {
    echo "<p style='color: green;'>✅ Redis class is available</p>";
    
    // Try to connect
    try {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379);
        $result = $redis->ping();
        echo "<p style='color: green;'>✅ Redis connection successful: " . $result . "</p>";
        
        // Test set/get
        $redis->set('test_key', 'Hello from PHP!');
        $value = $redis->get('test_key');
        echo "<p style='color: green;'>✅ Redis set/get works: " . $value . "</p>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Redis connection failed: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color: red;'>❌ Redis class not found</p>";
    echo "<p>Redis extension is not loaded in Apache PHP</p>";
}

echo "<hr>";
echo "<h2>PHP Info</h2>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>Loaded Extensions:</p>";
echo "<pre>";
print_r(get_loaded_extensions());
echo "</pre>";

echo "<hr>";
echo "<h2>Redis Extension Info</h2>";
if (extension_loaded('redis')) {
    echo "<p style='color: green;'>✅ Redis extension is loaded</p>";
    phpinfo(INFO_MODULES);
} else {
    echo "<p style='color: red;'>❌ Redis extension is NOT loaded</p>";
}
?>
