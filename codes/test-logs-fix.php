<?php
// Test the logs reading logic
$logPath = __DIR__ . '/../storage/logs/cleanup-service.log';
$lines = 10;

echo "Testing log file reading...\n";
echo "Log path: $logPath\n";
echo "File exists: " . (file_exists($logPath) ? 'Yes' : 'No') . "\n\n";

if (!file_exists($logPath)) {
    echo "Log file not found!\n";
    exit(1);
}

try {
    // Read last N lines using PHP
    $file = new \SplFileObject($logPath, 'r');
    $file->seek(PHP_INT_MAX);
    $totalLines = $file->key() + 1;
    
    echo "Total lines in file: $totalLines\n";
    
    $startLine = max(0, $totalLines - $lines);
    $logLines = [];
    
    $file->seek($startLine);
    while (!$file->eof()) {
        $line = $file->current();
        if ($line !== false && trim($line) !== '') {
            $logLines[] = rtrim($line);
        }
        $file->next();
    }
    
    $logs = implode("\n", $logLines);
    
    echo "\nLast $lines lines:\n";
    echo "================\n";
    echo $logs;
    echo "\n================\n";
    echo "\nSuccess!\n";
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
