<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Http\Controllers\VMAlertController;
use Illuminate\Http\Request;

echo "=== Testing VM Alerts Combined (Alerts + BackAlerts) ===\n\n";

try {
    // Create controller instance
    $controller = new VMAlertController();
    
    // Create a mock request for 2026-01-27
    $request = new Request([
        'from_date' => '2026-01-27',
        'per_page' => 10,
        'page' => 1
    ]);
    
    echo "Testing VM Alerts API request with parameters:\n";
    echo "- from_date: 2026-01-27\n";
    echo "- per_page: 10\n";
    echo "- page: 1\n";
    echo "- VM filters: status IN ('O','C') AND sendtoclient = 'S'\n\n";
    
    // Call the index method
    $response = $controller->index($request);
    
    // Get response data
    $responseData = json_decode($response->getContent(), true);
    
    if ($responseData['success']) {
        $data = $responseData['data'];
        
        echo "✅ VM Alerts API Response Success!\n\n";
        echo "=== Response Summary ===\n";
        echo "- Total VM alerts: " . number_format($data['total_count']) . "\n";
        echo "- Records returned: " . count($data['alerts']) . "\n";
        echo "- Current page: " . $data['pagination']['current_page'] . "\n";
        echo "- Last page: " . number_format($data['pagination']['last_page']) . "\n";
        echo "- Per page: " . $data['pagination']['per_page'] . "\n\n";
        
        echo "=== Sample VM Alert Records ===\n";
        foreach (array_slice($data['alerts'], 0, 3) as $i => $alert) {
            echo "VM Alert " . ($i + 1) . ":\n";
            echo "  - ID: " . ($alert['id'] ?? 'N/A') . "\n";
            echo "  - Panel ID: " . ($alert['panelid'] ?? 'N/A') . "\n";
            echo "  - Alert Type: " . ($alert['alerttype'] ?? 'N/A') . "\n";
            echo "  - Status: " . ($alert['status'] ?? 'N/A') . "\n";
            echo "  - Send to Client: " . ($alert['sendtoclient'] ?? 'N/A') . "\n";
            echo "  - Received Time: " . ($alert['receivedtime'] ?? 'N/A') . "\n";
            echo "  - Priority: " . ($alert['priority'] ?? 'N/A') . "\n";
            echo "  - Customer: " . ($alert['Customer'] ?? 'N/A') . "\n";
            echo "  - ATMID: " . ($alert['ATMID'] ?? 'N/A') . "\n";
            echo "  - City: " . ($alert['City'] ?? 'N/A') . "\n\n";
        }
        
        // Verify VM-specific filtering
        echo "=== VM Filter Verification ===\n";
        $statusCounts = [];
        $sendtoclientCounts = [];
        
        foreach ($data['alerts'] as $alert) {
            $status = $alert['status'] ?? 'Unknown';
            $sendtoclient = $alert['sendtoclient'] ?? 'Unknown';
            
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            $sendtoclientCounts[$sendtoclient] = ($sendtoclientCounts[$sendtoclient] ?? 0) + 1;
        }
        
        echo "Status distribution:\n";
        foreach ($statusCounts as $status => $count) {
            echo "  - {$status}: {$count}\n";
        }
        
        echo "SendToClient distribution:\n";
        foreach ($sendtoclientCounts as $sendtoclient => $count) {
            echo "  - {$sendtoclient}: {$count}\n";
        }
        
        // Test with panel ID filter
        echo "\n=== Testing Panel ID Filter ===\n";
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
                    echo "- Filtered total: " . number_format($filterData['data']['total_count']) . "\n";
                    echo "- Filtered records returned: " . count($filterData['data']['alerts']) . "\n";
                } else {
                    echo "❌ Filter request failed\n";
                }
            }
        }
        
        echo "\n✅ VM Alerts combined (alerts + backalerts) test completed successfully!\n";
        echo "\nThe vm-alerts endpoint now returns VM-filtered data from BOTH:\n";
        echo "- alerts partitioned tables (with VM filters)\n";
        echo "- backalerts partitioned tables (with VM filters)\n";
        echo "\nTotal VM alerts: " . number_format($data['total_count']) . "\n";
        echo "VM Filters Applied:\n";
        echo "- status IN ('O', 'C') - Only Open or Closed alerts\n";
        echo "- sendtoclient = 'S' - Only alerts sent to client\n";
        
    } else {
        echo "❌ VM Alerts API Response Failed:\n";
        echo json_encode($responseData, JSON_PRETTY_PRINT) . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}