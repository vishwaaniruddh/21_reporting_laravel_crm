# Test Service Management API
# This script tests the service management API endpoints

Write-Host "=== Testing Service Management API ===" -ForegroundColor Cyan
Write-Host ""

# Test GET /api/services
Write-Host "Testing GET /api/services..." -ForegroundColor Yellow
try {
    $response = Invoke-WebRequest -Uri "http://192.168.100.21:9000/api/services" -Method GET -UseBasicParsing
    Write-Host "Status Code: $($response.StatusCode)" -ForegroundColor Green
    Write-Host "Response:" -ForegroundColor Green
    $response.Content | ConvertFrom-Json | ConvertTo-Json -Depth 10
} catch {
    Write-Host "Error: $($_.Exception.Message)" -ForegroundColor Red
    if ($_.Exception.Response) {
        $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
        $reader.BaseStream.Position = 0
        $reader.DiscardBufferedData()
        $responseBody = $reader.ReadToEnd()
        Write-Host "Response Body: $responseBody" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "=== Test Complete ===" -ForegroundColor Cyan
