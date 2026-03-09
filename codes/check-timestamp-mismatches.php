<?php

/**
 * Check Timestamp Mismatches Between MySQL and PostgreSQL
 * 
 * Compares timestamps (createtime, receivedtime, closedtime) for alerts
 * on the current date between MySQL source and PostgreSQL partition tables.
 * 
 * Processes in batches of 1000 to handle large datasets efficiently.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

// Increase memory limit for large datasets
ini_set('memory_limit', '512M');

// Get date to check (default: today)
$checkDate = isset($argv[1]) ? Carbon::parse($argv[1]) : Carbon::today();
$dateStr = $checkDate->toDateString(); // YYYY-MM-DD
$partitionTable = 'alerts_' . $checkDate->format('Y_m_d'); // alerts_2026_03_04

echo "=== Timestamp Mismatch Checker ===\n";
echo "Date: {$dateStr}\n";
echo "Partition Table: {$partitionTable}\n\n";

// Check if partition table exists
$tableExists = DB::connection('pgsql')
    ->select("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = ?)", [$partitionTable]);

if (!$tableExists[0]->exists) {
    echo "❌ Partition table '{$partitionTable}' does not exist in PostgreSQL.\n";
    echo "No data to compare for this date.\n";
    exit(1);
}

echo "Step 1: Counting alerts...\n";

// Count alerts first
$mysqlCount = DB::connection('mysql')
    ->table('alerts')
    ->whereDate('receivedtime', $dateStr)
    ->count();

$pgCount = DB::connection('pgsql')
    ->table($partitionTable)
    ->count();

echo "MySQL alerts: " . number_format($mysqlCount) . "\n";
echo "PostgreSQL alerts: " . number_format($pgCount) . "\n\n";

if ($mysqlCount === 0) {
    echo "No alerts found in MySQL for this date.\n";
    exit(0);
}

if ($pgCount === 0) {
    echo "No alerts found in PostgreSQL partition for this date.\n";
    exit(0);
}

echo "Step 2: Processing in batches of 1000...\n\n";

$batchSize = 1000;
$mismatches = [];
$matched = 0;
$tolerance = 1; // 1 second tolerance
$processed = 0;

// Process MySQL alerts in chunks
DB::connection('mysql')
    ->table('alerts')
    ->whereDate('receivedtime', $dateStr)
    ->orderBy('id')
    ->chunk($batchSize, function ($mysqlChunk) use (&$mismatches, &$matched, &$processed, $tolerance, $partitionTable, $mysqlCount) {
        // Get corresponding PostgreSQL records for this chunk
        $ids = $mysqlChunk->pluck('id')->toArray();
        
        $pgAlerts = DB::connection('pgsql')
            ->table($partitionTable)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
        
        foreach ($mysqlChunk as $mysqlAlert) {
            $id = $mysqlAlert->id;
            $processed++;
            
            // Progress indicator
            if ($processed % 1000 === 0) {
                $percent = round(($processed / $mysqlCount) * 100, 1);
                echo "  Progress: {$processed} / " . number_format($mysqlCount) . " ({$percent}%)\r";
            }
            
            // Check if alert exists in PostgreSQL
            if (!$pgAlerts->has($id)) {
                $mismatches[] = [
                    'id' => $id,
                    'issue' => 'missing_in_postgres',
                    'mysql_createtime' => $mysqlAlert->createtime,
                    'mysql_receivedtime' => $mysqlAlert->receivedtime,
                    'mysql_closedtime' => $mysqlAlert->closedtime,
                    'pg_createtime' => null,
                    'pg_receivedtime' => null,
                    'pg_closedtime' => null,
                    'panelid' => $mysqlAlert->panelid,
                    'zone' => $mysqlAlert->zone,
                ];
                continue;
            }
            
            $pgAlert = $pgAlerts->get($id);
            
            // Compare timestamps
            $createtimeMismatch = false;
            $receivedtimeMismatch = false;
            $closedtimeMismatch = false;
            
            // Compare createtime
            if ($mysqlAlert->createtime && $pgAlert->createtime) {
                $diff = abs(strtotime($mysqlAlert->createtime) - strtotime($pgAlert->createtime));
                if ($diff > $tolerance) {
                    $createtimeMismatch = true;
                }
            }
            
            // Compare receivedtime
            if ($mysqlAlert->receivedtime && $pgAlert->receivedtime) {
                $diff = abs(strtotime($mysqlAlert->receivedtime) - strtotime($pgAlert->receivedtime));
                if ($diff > $tolerance) {
                    $receivedtimeMismatch = true;
                }
            }
            
            // Compare closedtime
            if ($mysqlAlert->closedtime && $pgAlert->closedtime) {
                $diff = abs(strtotime($mysqlAlert->closedtime) - strtotime($pgAlert->closedtime));
                if ($diff > $tolerance) {
                    $closedtimeMismatch = true;
                }
            } elseif (($mysqlAlert->closedtime && !$pgAlert->closedtime) || 
                      (!$mysqlAlert->closedtime && $pgAlert->closedtime)) {
                $closedtimeMismatch = true;
            }
            
            // If any mismatch found, add to results
            if ($createtimeMismatch || $receivedtimeMismatch || $closedtimeMismatch) {
                $mismatches[] = [
                    'id' => $id,
                    'issue' => 'timestamp_mismatch',
                    'createtime_mismatch' => $createtimeMismatch,
                    'receivedtime_mismatch' => $receivedtimeMismatch,
                    'closedtime_mismatch' => $closedtimeMismatch,
                    'mysql_createtime' => $mysqlAlert->createtime,
                    'mysql_receivedtime' => $mysqlAlert->receivedtime,
                    'mysql_closedtime' => $mysqlAlert->closedtime,
                    'pg_createtime' => $pgAlert->createtime,
                    'pg_receivedtime' => $pgAlert->receivedtime,
                    'pg_closedtime' => $pgAlert->closedtime,
                    'createtime_diff_hours' => $createtimeMismatch ? 
                        round((strtotime($mysqlAlert->createtime) - strtotime($pgAlert->createtime)) / 3600, 2) : 0,
                    'receivedtime_diff_hours' => $receivedtimeMismatch ? 
                        round((strtotime($mysqlAlert->receivedtime) - strtotime($pgAlert->receivedtime)) / 3600, 2) : 0,
                    'closedtime_diff_hours' => ($closedtimeMismatch && $mysqlAlert->closedtime && $pgAlert->closedtime) ? 
                        round((strtotime($mysqlAlert->closedtime) - strtotime($pgAlert->closedtime)) / 3600, 2) : 0,
                    'panelid' => $mysqlAlert->panelid,
                    'zone' => $mysqlAlert->zone,
                ];
            } else {
                $matched++;
            }
        }
        
        // Free memory after each chunk
        unset($pgAlerts);
        gc_collect_cycles();
    });

echo "\n\n";

// Display results
echo "=== Results ===\n";
echo "Total alerts in MySQL: " . number_format($mysqlCount) . "\n";
echo "Total alerts in PostgreSQL: " . number_format($pgCount) . "\n";
echo "Matched (timestamps identical): " . number_format($matched) . "\n";
echo "Mismatched: " . number_format(count($mismatches)) . "\n\n";

if (count($mismatches) > 0) {
    echo "❌ Found " . number_format(count($mismatches)) . " mismatches!\n\n";
    
    // Save to JSON file for web page
    $outputFile = storage_path('app/timestamp-mismatches-' . $checkDate->format('Y-m-d') . '.json');
    file_put_contents($outputFile, json_encode([
        'date' => $dateStr,
        'partition_table' => $partitionTable,
        'generated_at' => now()->toDateTimeString(),
        'summary' => [
            'total_mysql' => $mysqlCount,
            'total_postgres' => $pgCount,
            'matched' => $matched,
            'mismatched' => count($mismatches),
        ],
        'mismatches' => $mismatches,
    ], JSON_PRETTY_PRINT));
    
    echo "Detailed results saved to: {$outputFile}\n";
    echo "View in web interface: http://localhost:9000/timestamp-mismatches\n\n";
    
    // Display first 10 mismatches
    echo "First 10 mismatches:\n";
    echo str_repeat("-", 150) . "\n";
    printf("%-12s %-20s %-12s %-20s %-20s %-20s %-20s %-15s\n", 
        "Alert ID", "Issue", "Panel ID", "MySQL createtime", "PG createtime", "MySQL receivedtime", "PG receivedtime", "Diff (hours)");
    echo str_repeat("-", 150) . "\n";
    
    foreach (array_slice($mismatches, 0, 10) as $mismatch) {
        printf("%-12s %-20s %-12s %-20s %-20s %-20s %-20s %-15s\n",
            $mismatch['id'],
            $mismatch['issue'],
            $mismatch['panelid'] ?? 'N/A',
            $mismatch['mysql_createtime'] ?? 'NULL',
            $mismatch['pg_createtime'] ?? 'NULL',
            $mismatch['mysql_receivedtime'] ?? 'NULL',
            $mismatch['pg_receivedtime'] ?? 'NULL',
            isset($mismatch['receivedtime_diff_hours']) ? $mismatch['receivedtime_diff_hours'] : 'N/A'
        );
    }
    
    if (count($mismatches) > 10) {
        echo "\n... and " . number_format(count($mismatches) - 10) . " more. See JSON file or web interface for full list.\n";
    }
    
} else {
    echo "✅ All timestamps match! No mismatches found.\n";
    
    // Save success result
    $outputFile = storage_path('app/timestamp-mismatches-' . $checkDate->format('Y-m-d') . '.json');
    file_put_contents($outputFile, json_encode([
        'date' => $dateStr,
        'partition_table' => $partitionTable,
        'generated_at' => now()->toDateTimeString(),
        'summary' => [
            'total_mysql' => $mysqlCount,
            'total_postgres' => $pgCount,
            'matched' => $matched,
            'mismatched' => 0,
        ],
        'mismatches' => [],
    ], JSON_PRETTY_PRINT));
    
    echo "Results saved to: {$outputFile}\n";
}

echo "\n=== Usage ===\n";
echo "Check specific date: php codes/check-timestamp-mismatches.php 2026-03-04\n";
echo "Check today: php codes/check-timestamp-mismatches.php\n";
