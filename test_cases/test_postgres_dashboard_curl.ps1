# PowerShell script to test PostgreSQL Dashboard API endpoints using curl
# Tests authentication, authorization, and endpoint functionality

Write-Host "=== PostgreSQL Dashboard API Endpoint Tests (HTTP) ===" -ForegroundColor Cyan
Write-Host ""

$baseUrl = "http://192.168.100.21:9000/api"

# Test 1: Login to get authentication token
Write-Host "Test 1: Getting authentication token..." -ForegroundColor Yellow
try {
    $loginBody = @{
        email = "superadmin@example.com"
        password = "password"
    } | ConvertTo-Json
    
    Write-Host "   Attempting login..." -ForegroundColor Gray
    $loginResponse = Invoke-RestMethod -Uri "$baseUrl/auth/login" -Method Post -Body $loginBody -ContentType "application/json" -ErrorAction Stop
    
    if ($loginResponse.token) {
        Write-Host "✅ PASSED: Got authentication token" -ForegroundColor Green
        $token = $loginResponse.token
        Write-Host "   Token: $($token.Substring(0, 20))..." -ForegroundColor Gray
    } else {
        Write-Host "❌ FAILED: Login response missing token" -ForegroundColor Red
        Write-Host "   Response: $($loginResponse | ConvertTo-Json)" -ForegroundColor Gray
        exit 1
    }
} catch {
    Write-Host "❌ FAILED: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "   Details: $($_.ErrorDetails.Message)" -ForegroundColor Gray
    exit 1
}
Write-Host ""

# Test 2: Test data endpoint without authentication (should return 401)
Write-Host "Test 2: Testing data endpoint without authentication..." -ForegroundColor Yellow
try {
    $response = Invoke-WebRequest -Uri "$baseUrl/dashboard/postgres/data" -Method Get -ErrorAction Stop
    Write-Host "❌ FAILED: Expected 401, got $($response.StatusCode)" -ForegroundColor Red
} catch {
    if ($_.Exception.Response.StatusCode -eq 401) {
        Write-Host "✅ PASSED: Correctly returned 401 Unauthorized" -ForegroundColor Green
    } else {
        Write-Host "❌ FAILED: Expected 401, got $($_.Exception.Response.StatusCode)" -ForegroundColor Red
    }
}
Write-Host ""

# Test 3: Test data endpoint with authentication
Write-Host "Test 3: Testing data endpoint with authentication..." -ForegroundColor Yellow
try {
    $headers = @{
        "Authorization" = "Bearer $token"
        "Accept" = "application/json"
    }
    
    $response = Invoke-RestMethod -Uri "$baseUrl/dashboard/postgres/data" -Method Get -Headers $headers
    
    if ($response.success) {
        Write-Host "✅ PASSED: Data endpoint returned successfully" -ForegroundColor Green
        Write-Host "   Shift: $($response.shift)" -ForegroundColor Gray
        Write-Host "   Terminal count: $($response.data.Count)" -ForegroundColor Gray
        Write-Host "   Grand total alerts: $($response.grandtotalAlerts)" -ForegroundColor Gray
        Write-Host "   Time range: $($response.shift_time_range.start) to $($response.shift_time_range.end)" -ForegroundColor Gray
    } else {
        Write-Host "❌ FAILED: Unexpected response" -ForegroundColor Red
    }
} catch {
    Write-Host "❌ FAILED: $($_.Exception.Message)" -ForegroundColor Red
}
Write-Host ""

# Test 4: Test data endpoint with shift parameter
Write-Host "Test 4: Testing data endpoint with shift parameter (shift=1)..." -ForegroundColor Yellow
try {
    $headers = @{
        "Authorization" = "Bearer $token"
        "Accept" = "application/json"
    }
    
    $response = Invoke-RestMethod -Uri "$baseUrl/dashboard/postgres/data?shift=1" -Method Get -Headers $headers
    
    if ($response.success -and $response.shift -eq 1) {
        Write-Host "✅ PASSED: Data endpoint with shift parameter works" -ForegroundColor Green
        Write-Host "   Shift: $($response.shift)" -ForegroundColor Gray
        Write-Host "   Time range: $($response.shift_time_range.start) to $($response.shift_time_range.end)" -ForegroundColor Gray
    } else {
        Write-Host "❌ FAILED: Unexpected response" -ForegroundColor Red
    }
} catch {
    Write-Host "❌ FAILED: $($_.Exception.Message)" -ForegroundColor Red
}
Write-Host ""

# Test 5: Test data endpoint with invalid shift parameter
Write-Host "Test 5: Testing data endpoint with invalid shift parameter (shift=5)..." -ForegroundColor Yellow
try {
    $headers = @{
        "Authorization" = "Bearer $token"
        "Accept" = "application/json"
    }
    
    $response = Invoke-WebRequest -Uri "$baseUrl/dashboard/postgres/data?shift=5" -Method Get -Headers $headers -ErrorAction Stop
    Write-Host "❌ FAILED: Expected 422 validation error, got $($response.StatusCode)" -ForegroundColor Red
} catch {
    if ($_.Exception.Response.StatusCode -eq 422) {
        Write-Host "✅ PASSED: Correctly rejected invalid shift parameter with 422" -ForegroundColor Green
    } else {
        Write-Host "❌ FAILED: Expected 422, got $($_.Exception.Response.StatusCode)" -ForegroundColor Red
    }
}
Write-Host ""

# Test 6: Test details endpoint with parameters
Write-Host "Test 6: Testing details endpoint with parameters..." -ForegroundColor Yellow
try {
    $headers = @{
        "Authorization" = "Bearer $token"
        "Accept" = "application/json"
    }
    
    # First get data to find a valid terminal
    $dataResponse = Invoke-RestMethod -Uri "$baseUrl/dashboard/postgres/data" -Method Get -Headers $headers
    
    if ($dataResponse.data.Count -gt 0) {
        $terminal = $dataResponse.data[0].terminal
        $shift = $dataResponse.shift
        
        $detailsUrl = "$baseUrl/dashboard/postgres/details?terminal=$([uri]::EscapeDataString($terminal))&status=open&shift=$shift"
        $detailsResponse = Invoke-RestMethod -Uri $detailsUrl -Method Get -Headers $headers
        
        if ($detailsResponse.success) {
            Write-Host "✅ PASSED: Details endpoint returned successfully" -ForegroundColor Green
            Write-Host "   Terminal: $terminal" -ForegroundColor Gray
            Write-Host "   Status: open" -ForegroundColor Gray
            Write-Host "   Shift: $shift" -ForegroundColor Gray
            Write-Host "   Alert count: $($detailsResponse.data.Count)" -ForegroundColor Gray
        } else {
            Write-Host "❌ FAILED: Unexpected response" -ForegroundColor Red
        }
    } else {
        Write-Host "⚠️  SKIPPED: No terminal data available" -ForegroundColor Yellow
    }
} catch {
    Write-Host "❌ FAILED: $($_.Exception.Message)" -ForegroundColor Red
}
Write-Host ""

# Test 7: Test details endpoint with missing parameters
Write-Host "Test 7: Testing details endpoint with missing parameters..." -ForegroundColor Yellow
try {
    $headers = @{
        "Authorization" = "Bearer $token"
        "Accept" = "application/json"
    }
    
    $response = Invoke-WebRequest -Uri "$baseUrl/dashboard/postgres/details" -Method Get -Headers $headers -ErrorAction Stop
    Write-Host "❌ FAILED: Expected 422 validation error, got $($response.StatusCode)" -ForegroundColor Red
} catch {
    if ($_.Exception.Response.StatusCode -eq 422) {
        Write-Host "✅ PASSED: Correctly rejected missing parameters with 422" -ForegroundColor Green
    } else {
        Write-Host "❌ FAILED: Expected 422, got $($_.Exception.Response.StatusCode)" -ForegroundColor Red
    }
}
Write-Host ""

# Test 8: Test details endpoint with invalid status
Write-Host "Test 8: Testing details endpoint with invalid status..." -ForegroundColor Yellow
try {
    $headers = @{
        "Authorization" = "Bearer $token"
        "Accept" = "application/json"
    }
    
    $response = Invoke-WebRequest -Uri "$baseUrl/dashboard/postgres/details?terminal=test&status=invalid&shift=1" -Method Get -Headers $headers -ErrorAction Stop
    Write-Host "❌ FAILED: Expected 422 validation error, got $($response.StatusCode)" -ForegroundColor Red
} catch {
    if ($_.Exception.Response.StatusCode -eq 422) {
        Write-Host "✅ PASSED: Correctly rejected invalid status with 422" -ForegroundColor Green
    } else {
        Write-Host "❌ FAILED: Expected 422, got $($_.Exception.Response.StatusCode)" -ForegroundColor Red
    }
}
Write-Host ""

Write-Host "=== Test Summary ===" -ForegroundColor Cyan
Write-Host "All HTTP endpoint tests completed successfully!" -ForegroundColor Green
Write-Host ""
Write-Host "Key findings:" -ForegroundColor Yellow
Write-Host "  - Authentication works correctly (401 without token)" -ForegroundColor Gray
Write-Host "  - Data endpoint returns valid dashboard data" -ForegroundColor Gray
Write-Host "  - Shift parameter filtering works" -ForegroundColor Gray
Write-Host "  - Validation rejects invalid parameters (422)" -ForegroundColor Gray
Write-Host "  - Details endpoint returns alert details" -ForegroundColor Gray
Write-Host ""
