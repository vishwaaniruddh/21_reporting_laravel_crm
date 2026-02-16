# Performance Comparison: Downloads V1 (Database) vs V2 (Redis)
# This script tests both implementations and compares their performance

param(
    [string]$Token = "",
    [string]$BaseUrl = "http://192.168.100.21:9000",
    [string]$Date = "2026-01-30",
    [string]$Type = "all-alerts"
)

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Downloads V1 vs V2 Performance Test" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

if ($Token -eq "") {
    Write-Host "❌ ERROR: Token is required" -ForegroundColor Red
    Write-Host "Usage: .\test-v1-vs-v2-performance.ps1 -Token 'YOUR_TOKEN'" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Get token by logging in:" -ForegroundColor Yellow
    Write-Host "  POST $BaseUrl/api/auth/login" -ForegroundColor White
    Write-Host "  Body: {`"email`": `"user@example.com`", `"password`": `"password`"}" -ForegroundColor White
    exit 1
}

$headers = @{
    "Authorization" = "Bearer $Token"
    "Content-Type" = "application/json"
}

$body = @{
    type = $Type
    date = $Date
} | ConvertTo-Json

Write-Host "Test Configuration:" -ForegroundColor Cyan
Write-Host "  Base URL: $BaseUrl" -ForegroundColor White
Write-Host "  Date: $Date" -ForegroundColor White
Write-Host "  Type: $Type" -ForegroundColor White
Write-Host ""

# Test V1 (Database Queue)
Write-Host "Testing V1 (Database Queue)..." -ForegroundColor Yellow
Write-Host "================================" -ForegroundColor Yellow

$v1StartTime = Get-Date
try {
    $v1Response = Invoke-RestMethod -Uri "$BaseUrl/api/downloads/request" -Method POST -Headers $headers -Body $body
    $v1EndTime = Get-Date
    $v1Duration = ($v1EndTime - $v1StartTime).TotalMilliseconds
    
    Write-Host "✅ V1 Request successful" -ForegroundColor Green
    Write-Host "  Response Time: $([math]::Round($v1Duration, 2))ms" -ForegroundColor White
    Write-Host "  Job ID: $($v1Response.data.job_id)" -ForegroundColor White
    
    $v1JobId = $v1Response.data.job_id
    
    # Check status
    Start-Sleep -Seconds 1
    $v1StatusStart = Get-Date
    $v1Status = Invoke-RestMethod -Uri "$BaseUrl/api/downloads/status/$v1JobId" -Method GET -Headers $headers
    $v1StatusEnd = Get-Date
    $v1StatusDuration = ($v1StatusEnd - $v1StatusStart).TotalMilliseconds
    
    Write-Host "  Status Check: $([math]::Round($v1StatusDuration, 2))ms" -ForegroundColor White
    Write-Host "  Status: $($v1Status.data.status)" -ForegroundColor White
    
} catch {
    Write-Host "❌ V1 Request failed: $($_.Exception.Message)" -ForegroundColor Red
    $v1Duration = 0
    $v1StatusDuration = 0
}

Write-Host ""

# Test V2 (Redis Queue)
Write-Host "Testing V2 (Redis Queue)..." -ForegroundColor Yellow
Write-Host "=============================" -ForegroundColor Yellow

$v2StartTime = Get-Date
try {
    $v2Response = Invoke-RestMethod -Uri "$BaseUrl/api/downloads-v2/request" -Method POST -Headers $headers -Body $body
    $v2EndTime = Get-Date
    $v2Duration = ($v2EndTime - $v2StartTime).TotalMilliseconds
    
    Write-Host "✅ V2 Request successful" -ForegroundColor Green
    Write-Host "  Response Time: $([math]::Round($v2Duration, 2))ms" -ForegroundColor White
    Write-Host "  Job ID: $($v2Response.data.job_id)" -ForegroundColor White
    Write-Host "  Version: $($v2Response.data.version)" -ForegroundColor White
    
    $v2JobId = $v2Response.data.job_id
    
    # Check status
    Start-Sleep -Seconds 1
    $v2StatusStart = Get-Date
    $v2Status = Invoke-RestMethod -Uri "$BaseUrl/api/downloads-v2/status/$v2JobId" -Method GET -Headers $headers
    $v2StatusEnd = Get-Date
    $v2StatusDuration = ($v2StatusEnd - $v2StatusStart).TotalMilliseconds
    
    Write-Host "  Status Check: $([math]::Round($v2StatusDuration, 2))ms" -ForegroundColor White
    Write-Host "  Status: $($v2Status.data.status)" -ForegroundColor White
    Write-Host "  Source: $($v2Status.source)" -ForegroundColor White
    
} catch {
    Write-Host "❌ V2 Request failed: $($_.Exception.Message)" -ForegroundColor Red
    $v2Duration = 0
    $v2StatusDuration = 0
}

Write-Host ""

# Comparison
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Performance Comparison" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Request Response Time:" -ForegroundColor Yellow
Write-Host "  V1 (Database): $([math]::Round($v1Duration, 2))ms" -ForegroundColor White
Write-Host "  V2 (Redis):    $([math]::Round($v2Duration, 2))ms" -ForegroundColor White
if ($v1Duration -gt 0 -and $v2Duration -gt 0) {
    $requestImprovement = [math]::Round($v1Duration / $v2Duration, 2)
    Write-Host "  Improvement:   ${requestImprovement}x faster" -ForegroundColor Green
}
Write-Host ""

Write-Host "Status Check Time:" -ForegroundColor Yellow
Write-Host "  V1 (Database): $([math]::Round($v1StatusDuration, 2))ms" -ForegroundColor White
Write-Host "  V2 (Redis):    $([math]::Round($v2StatusDuration, 2))ms" -ForegroundColor White
if ($v1StatusDuration -gt 0 -and $v2StatusDuration -gt 0) {
    $statusImprovement = [math]::Round($v1StatusDuration / $v2StatusDuration, 2)
    Write-Host "  Improvement:   ${statusImprovement}x faster" -ForegroundColor Green
}
Write-Host ""

# Additional Redis stats
Write-Host "Redis Statistics:" -ForegroundColor Yellow
try {
    $redisQueueSize = & redis-cli LLEN "queues:exports-v2" 2>&1
    Write-Host "  Queue Size: $redisQueueSize jobs" -ForegroundColor White
    
    $redisKeys = & redis-cli KEYS "export_job_v2:*" 2>&1
    $redisJobCount = ($redisKeys | Measure-Object).Count
    Write-Host "  Active Jobs in Redis: $redisJobCount" -ForegroundColor White
} catch {
    Write-Host "  ⚠️  Could not fetch Redis stats" -ForegroundColor Yellow
}

Write-Host ""

# Recommendations
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Recommendations" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

if ($v2Duration -gt 0 -and $v1Duration -gt 0) {
    if ($v2Duration -lt $v1Duration) {
        $improvement = [math]::Round((($v1Duration - $v2Duration) / $v1Duration) * 100, 1)
        Write-Host "✅ V2 (Redis) is $improvement% faster than V1" -ForegroundColor Green
        Write-Host ""
        Write-Host "Consider migrating to V2 if:" -ForegroundColor Yellow
        Write-Host "  - Performance improvement is significant (>50%)" -ForegroundColor White
        Write-Host "  - Redis is stable in your environment" -ForegroundColor White
        Write-Host "  - You have resources to maintain Redis" -ForegroundColor White
    } else {
        Write-Host "⚠️  V1 (Database) performed better in this test" -ForegroundColor Yellow
        Write-Host ""
        Write-Host "Possible reasons:" -ForegroundColor Yellow
        Write-Host "  - Redis not properly configured" -ForegroundColor White
        Write-Host "  - Network latency to Redis" -ForegroundColor White
        Write-Host "  - Small dataset (Redis benefits more with scale)" -ForegroundColor White
    }
}

Write-Host ""
Write-Host "Next Steps:" -ForegroundColor Cyan
Write-Host "  1. Run this test multiple times for average" -ForegroundColor White
Write-Host "  2. Test with different dates and data sizes" -ForegroundColor White
Write-Host "  3. Monitor both workers over 1-2 weeks" -ForegroundColor White
Write-Host "  4. Compare user feedback" -ForegroundColor White
Write-Host ""
Write-Host "📖 Full documentation: Documents\DOWNLOADS_V2_REDIS_SETUP.md" -ForegroundColor Yellow
Write-Host ""
