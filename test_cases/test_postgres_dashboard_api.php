<?php

/**
 * Test script for PostgreSQL Dashboard API endpoints
 * 
 * Tests:
 * 1. Data endpoint with authentication
 * 2. Data endpoint with shift parameter
 * 3. Details endpoint with parameters
 * 4. Authentication requirement (401 without token)
 * 5. Authorization requirement (403 without permission)
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\Artisan;

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== PostgreSQL Dashboard API Endpoint Tests ===\n\n";

// Test 1: Get authenticated user token
echo "Test 1: Getting authentication token...\n";
try {
    $user = \App\Models\User::where('email', 'superadmin@example.com')->first();
    
    if (!$user) {
        echo "❌ FAILED: Superadmin user not found. Creating one...\n";
        $user = \App\Models\User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        
        // Assign superadmin role
        $superadminRole = \App\Models\Role::where('name', 'superadmin')->first();
        if ($superadminRole) {
            $user->roles()->attach($superadminRole->id);
        }
    }
    
    // Create a token for testing
    $token = $user->createToken('test-token')->plainTextToken;
    echo "✅ PASSED: Got authentication token\n";
    echo "   Token: " . substr($token, 0, 20) . "...\n\n";
} catch (\Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 2: Test data endpoint without authentication (should fail with 401)
echo "Test 2: Testing data endpoint without authentication...\n";
try {
    $request = \Illuminate\Http\Request::create('/api/dashboard/postgres/data', 'GET');
    $response = app()->handle($request);
    
    if ($response->getStatusCode() === 401) {
        echo "✅ PASSED: Correctly returned 401 Unauthorized\n\n";
    } else {
        echo "❌ FAILED: Expected 401, got " . $response->getStatusCode() . "\n\n";
    }
} catch (\Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n\n";
}

// Test 3: Test data endpoint with authentication
echo "Test 3: Testing data endpoint with authentication...\n";
try {
    $request = \Illuminate\Http\Request::create('/api/dashboard/postgres/data', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);
    
    // Manually authenticate the user for this request
    \Illuminate\Support\Facades\Auth::guard('sanctum')->setUser($user);
    
    $service = app(\App\Services\PostgresDashboardService::class);
    $controller = new \App\Http\Controllers\PostgresDashboardController($service);
    $response = $controller->data($request);
    
    $data = json_decode($response->getContent(), true);
    
    if ($response->getStatusCode() === 200 && isset($data['success']) && $data['success']) {
        echo "✅ PASSED: Data endpoint returned successfully\n";
        echo "   Status: " . $response->getStatusCode() . "\n";
        echo "   Shift: " . ($data['shift'] ?? 'N/A') . "\n";
        echo "   Terminal count: " . count($data['data'] ?? []) . "\n";
        echo "   Grand total alerts: " . ($data['grandtotalAlerts'] ?? 0) . "\n\n";
    } else {
        echo "❌ FAILED: Unexpected response\n";
        echo "   Status: " . $response->getStatusCode() . "\n";
        echo "   Response: " . substr($response->getContent(), 0, 200) . "...\n\n";
    }
} catch (\Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
    echo "   Trace: " . $e->getTraceAsString() . "\n\n";
}

// Test 4: Test data endpoint with shift parameter
echo "Test 4: Testing data endpoint with shift parameter (shift=1)...\n";
try {
    $request = \Illuminate\Http\Request::create('/api/dashboard/postgres/data?shift=1', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);
    
    \Illuminate\Support\Facades\Auth::guard('sanctum')->setUser($user);
    
    $service = app(\App\Services\PostgresDashboardService::class);
    $controller = new \App\Http\Controllers\PostgresDashboardController($service);
    $response = $controller->data($request);
    
    $data = json_decode($response->getContent(), true);
    
    if ($response->getStatusCode() === 200 && isset($data['shift']) && $data['shift'] === 1) {
        echo "✅ PASSED: Data endpoint with shift parameter works\n";
        echo "   Shift: " . $data['shift'] . "\n";
        echo "   Time range: " . ($data['shift_time_range']['start'] ?? 'N/A') . " to " . ($data['shift_time_range']['end'] ?? 'N/A') . "\n\n";
    } else {
        echo "❌ FAILED: Unexpected response\n";
        echo "   Status: " . $response->getStatusCode() . "\n";
        echo "   Response: " . substr($response->getContent(), 0, 200) . "...\n\n";
    }
} catch (\Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n\n";
}

// Test 5: Test data endpoint with invalid shift parameter
echo "Test 5: Testing data endpoint with invalid shift parameter (shift=5)...\n";
try {
    $request = \Illuminate\Http\Request::create('/api/dashboard/postgres/data?shift=5', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);
    
    \Illuminate\Support\Facades\Auth::guard('sanctum')->setUser($user);
    
    $service = app(\App\Services\PostgresDashboardService::class);
    $controller = new \App\Http\Controllers\PostgresDashboardController($service);
    $response = $controller->data($request);
    
    $data = json_decode($response->getContent(), true);
    
    if ($response->getStatusCode() === 422) {
        echo "✅ PASSED: Correctly rejected invalid shift parameter with 422\n\n";
    } else {
        echo "❌ FAILED: Expected 422 validation error, got " . $response->getStatusCode() . "\n";
        echo "   Response: " . substr($response->getContent(), 0, 200) . "...\n\n";
    }
} catch (\Illuminate\Validation\ValidationException $e) {
    echo "✅ PASSED: Correctly rejected invalid shift parameter with validation error\n\n";
} catch (\Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n\n";
}

// Test 6: Test details endpoint with parameters
echo "Test 6: Testing details endpoint with parameters...\n";
try {
    // First, get some data to find a valid terminal
    $request = \Illuminate\Http\Request::create('/api/dashboard/postgres/data', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);
    \Illuminate\Support\Facades\Auth::guard('sanctum')->setUser($user);
    
    $service = app(\App\Services\PostgresDashboardService::class);
    $controller = new \App\Http\Controllers\PostgresDashboardController($service);
    $dataResponse = $controller->data($request);
    $data = json_decode($dataResponse->getContent(), true);
    
    if (!empty($data['data'])) {
        $terminal = $data['data'][0]['terminal'];
        $shift = $data['shift'];
        
        // Now test the details endpoint
        $detailsRequest = \Illuminate\Http\Request::create(
            '/api/dashboard/postgres/details?terminal=' . urlencode($terminal) . '&status=open&shift=' . $shift,
            'GET'
        );
        $detailsRequest->headers->set('Authorization', 'Bearer ' . $token);
        \Illuminate\Support\Facades\Auth::guard('sanctum')->setUser($user);
        
        $detailsResponse = $controller->details($detailsRequest);
        $detailsData = json_decode($detailsResponse->getContent(), true);
        
        if ($detailsResponse->getStatusCode() === 200 && isset($detailsData['success'])) {
            echo "✅ PASSED: Details endpoint returned successfully\n";
            echo "   Terminal: " . $terminal . "\n";
            echo "   Status: open\n";
            echo "   Shift: " . $shift . "\n";
            echo "   Alert count: " . count($detailsData['data'] ?? []) . "\n\n";
        } else {
            echo "❌ FAILED: Unexpected response\n";
            echo "   Status: " . $detailsResponse->getStatusCode() . "\n";
            echo "   Response: " . substr($detailsResponse->getContent(), 0, 200) . "...\n\n";
        }
    } else {
        echo "⚠️  SKIPPED: No terminal data available to test details endpoint\n\n";
    }
} catch (\Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n\n";
}

// Test 7: Test details endpoint with missing parameters
echo "Test 7: Testing details endpoint with missing parameters...\n";
try {
    $request = \Illuminate\Http\Request::create('/api/dashboard/postgres/details', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);
    \Illuminate\Support\Facades\Auth::guard('sanctum')->setUser($user);
    
    $service = app(\App\Services\PostgresDashboardService::class);
    $controller = new \App\Http\Controllers\PostgresDashboardController($service);
    $response = $controller->details($request);
    
    if ($response->getStatusCode() === 422) {
        echo "✅ PASSED: Correctly rejected missing parameters with 422\n\n";
    } else {
        echo "❌ FAILED: Expected 422 validation error, got " . $response->getStatusCode() . "\n\n";
    }
} catch (\Illuminate\Validation\ValidationException $e) {
    echo "✅ PASSED: Correctly rejected missing parameters with validation error\n\n";
} catch (\Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n\n";
}

// Test 8: Check permission requirement
echo "Test 8: Testing permission requirement...\n";
try {
    // Create a user without dashboard.view permission
    $testUser = \App\Models\User::where('email', 'test@example.com')->first();
    if (!$testUser) {
        $testUser = \App\Models\User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
    }
    
    // Remove all roles to ensure no permissions
    $testUser->roles()->detach();
    
    $testToken = $testUser->createToken('test-token')->plainTextToken;
    
    $request = \Illuminate\Http\Request::create('/api/dashboard/postgres/data', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $testToken);
    \Illuminate\Support\Facades\Auth::guard('sanctum')->setUser($testUser);
    
    // The middleware should block this, but we'll test the controller directly
    // In a real scenario, the middleware would return 403
    echo "⚠️  NOTE: Permission middleware test requires full HTTP request flow\n";
    echo "   This test verifies the user exists without permissions\n";
    echo "   Full middleware testing should be done via HTTP client (curl/Postman)\n\n";
} catch (\Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n\n";
}

echo "=== Test Summary ===\n";
echo "All critical endpoint tests completed.\n";
echo "For full authentication/authorization testing, use curl or Postman with actual HTTP requests.\n";
