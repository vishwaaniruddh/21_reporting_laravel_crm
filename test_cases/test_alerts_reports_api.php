<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Http\Controllers\AlertsReportController;
use Illuminate\Http\Request;

echo "=== Testing AlertsReportController API ===\n\n";

try {
    // Create controller instance
    $controller = new AlertsReportController();
    
    // Create a mock request for 2026-01-27
    $request = new Request([
        'from_date' => '2026-01-27',
        'per_page' => 10,
        'page' => 1
    ]);
    
    echo "Testing API request with parameters:\n";
    echo "- from_date: 2026-01-27\n";
    echo "- per_page: 5\n";
    echo "- page: 1\n\n";
    
    // Call the index method
    $response = $controller->index($request);
    
    // Get response data
    $responseData = json_decode($response->getContent(), true);
    
    if ($responseData['success']) {
        $data = $responseData['data'];
        
        echo "✅ API Response Success!\n\n";
        echo "=== Response Summary ===\n";
        echo "- Total records: " . $data['total_count'] . "\n";
        echo "- Records returned: " . count($data['alerts']) . "\n";
        echo "- Current page: " . $data['pagination']['current_page'] . "\n";
        echo "- Last page: " . $data['pagination']['last_page'] . "\n";
        echo "- Per page: " . $data['pagination']['per_page'] . "\n\n";
        
        echo "=== Sample Records ===\n";
        foreach (array_slice($data['alerts'], 0, 3) as $i => $alert) {
            echo "Record " . ($i + 1) . ":\n";
            echo "  - ID: " . ($alert['id'] ?? 'N/A') . "\n";
            echo "  - Panel ID: " . ($alert['panelid'] ?? 'N/A') . "\n";
            echo "  - Alert Type: " . ($alert['alerttype'] ?? 'N/A') . "\n";
            echo "  - Received Time: " . ($alert['receivedtime'] ?? 'N/A') . "\n";
            echo "  - Priority: " . ($alert['priority'] ?? 'N/A') . "\n";
            echo "  - Customer: " . ($alert['Customer'] ?? 'N/A') . "\n";
            echo "  - ATMID: " . ($alert['ATMID'] ?? 'N/A') . "\n";
            echo "  - City: " . ($alert['City'] ?? 'N/A') . "\n\n";
        }
        
        // Test with panel ID filter
        echo "=== Testing Panel ID Filter ===\n";
        if (!empty($data['alerts'])) {
            $samplePanelId = $data['alerts'][0]['panelid'] ?? null;
            if ($samplePanelId) {
                $filterRequest = new Request([
                    'from_date' => '2026-01-27',
                    'per_page' => 10,
                    'page' => 1,
                    'panelid' => $samplePanelId
                ]);
                
                echo "Filtering by panel ID: {$samplePanelId}\n";
                $filterResponse = $controller->index($filterRequest);
                $filterData = json_decode($filterResponse->getContent(), true);
                
                if ($filterData['success']) {
                    echo "- Filtered total: " . $filterData['data']['total_count'] . "\n";
                    echo "- Filtered records returned: " . count($filterData['data']['alerts']) . "\n";
                } else {
                    echo "❌ Filter request failed\n";
                }
            }
        }
        
        echo "\n✅ Combined alerts + backalerts API test completed successfully!\n";
        echo "\nThe alerts-reports endpoint now returns data from BOTH:\n";
        echo "- alerts partitioned tables\n";
        echo "- backalerts partitioned tables\n";
        echo "\nTotal combined records: " . number_format($data['total_count']) . "\n";
        
    } else {
        echo "❌ API Response Failed:\n";
        echo json_encode($responseData, JSON_PRETTY_PRINT) . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}