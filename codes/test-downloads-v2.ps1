# Test Downloads V2 (Redis) - Complete Test Script
# This script tests all V2 endpoints with your token

$token = "380|7Q9SLn77ebJ5Q9dwp3ObBiG7KcTy2TImW4OhVMM086eed40f"
$baseUrl = "http://192.168.100.21:9000"

$headers = @{
    "Authorization" = "Bearer $token"
    "Content-Type" = "application/json"
}

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Testing Downloads V2 (Redis)" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Test 1: Get Partitions (should be cached in Redis)
Write-Host "Test 1: Get Partitions (All Alerts)" -ForegroundColor Yellow
Write-Host "GET $baseUrl/api/downloads-v2/partitions?type=all-alerts" -ForegroundColor Gray
try {
    $partitions = Invoke-RestMethod -Uri "$baseUrl/api/downloads-v2/partitions?type=all-alerts" -Method GET -Headers $headers
    Write-Host "✅ Success!" -ForegroundColor Green
    Write-Host "  Found $($partitions.data.Count) partitions" -ForegroundColor White
    Write-Host "  Cached: $($partitions.cached)" -ForegroundColor White
    
    if ($partitions.data.Count -gt 0) {
        Write-Host "`n  Sample partitions:" -ForegroundColor Cyan
        $partitions.data | Select-Object -First 5 | Format-Table date, records, alerts_count, backalerts_count -AutoSize
    }
} catch {
    Write-Host "❌ Failed: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host ""

# Test 2: Get Partitions again (should be cached now)
Write-Host "Test 2: Get Partitions Again (Should be cached)" -ForegroundColor Yellow
Write-Host "GET $baseUrl/api/downloads-v2/partitions?type=all-alerts" -ForegroundColor Gray
try {
    $partitionsCached = Invoke-RestMethod -Uri "$baseUrl/api/downloads-v2/partitions?type=all-alerts" -Method GET -Headers $headers
    Write-Host "✅ Success!" -ForegroundColor Green
    Write-Host "  Cached: $($partitionsCached.cached)" -ForegroundColor $(if ($partitionsCached.cached) { "Green" } else { "Yellow" })
} catch {
    Write-Host "❌ Failed: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host ""

# Test 3: Request Export
Write-Host "Test 3: Request Export (Queue to Redis)" -ForegroundColor Yellow
Write-Host "POST $baseUrl/api/downloads-v2/request" -ForegroundColor Gray

$exportBody = @{
    type = "all-alerts"
    date = "2026-01-30"
} | ConvertTo-Json

try {
    $exportRequest = Invoke-RestMethod -Uri "$baseUrl/api/downloads-v2/request" -Method POST -Headers $headers -Body $exportBody
    Write-Host "✅ Success!" -ForegroundColor Green
    Write-Host "  Job ID: $($exportRequest.data.job_id)" -ForegroundColor White
    Write-Host "  Message: $($exportRequest.data.message)" -ForegroundColor White
    Write-Host "  Version: $($exportRequest.data.version)" -ForegroundColor White
    
    $jobId = $exportRequest.data.job_id
    
    Write-Host ""
    
    # Test 4: Check Status immediately
    Write-Host "Test 4: Check Export Status (Real-time from Redis)" -ForegroundColor Yellow
    Write-Host "GET $baseUrl/api/downloads-v2/status/$jobId" -ForegroundColor Gray
    
    Start-Sleep -Seconds 1
    
    try {
        $status = Invoke-RestMethod -Uri "$baseUrl/api/downloads-v2/status/$jobId" -Method GET -Headers $headers
        Write-Host "✅ Success!" -ForegroundColor Green
        Write-Host "  Status: $($status.data.status)" -ForegroundColor White
        Write-Host "  Source: $($status.source)" -ForegroundColor $(if ($status.source -eq "redis") { "Green" } else { "Yellow" })
        Write-Host "  Progress: $($status.data.progress_percent)%" -ForegroundColor White
        
        if ($status.data.total_records) {
            Write-Host "  Records: $($status.data.total_records)" -ForegroundColor White
        }
    } catch {
        Write-Host "❌ Failed: $($_.Exception.Message)" -ForegroundColor Red
    }
    
    Write-Host ""
    
    # Test 5: Monitor progress
    Write-Host "Test 5: Monitor Progress (Polling every 2 seconds)" -ForegroundColor Yellow
    Write-Host "Press Ctrl+C to stop monitoring" -ForegroundColor Gray
    Write-Host ""
    
    $maxChecks = 30
    $checkCount = 0
    
    while ($checkCount -lt $maxChecks) {
        try {
            $status = Invoke-RestMethod -Uri "$baseUrl/api/downloads-v2/status/$jobId" -Method GET -Headers $headers
            
            $statusColor = switch ($status.data.status) {
                "pending" { "Yellow" }
                "processing" { "Cyan" }
                "completed" { "Green" }
                "failed" { "Red" }
                default { "White" }
            }
            
            Write-Host "  [$([DateTime]::Now.ToString('HH:mm:ss'))] Status: $($status.data.status) | Progress: $($status.data.progress_percent)% | Records: $($status.data.records_processed)" -ForegroundColor $statusColor
            
            if ($status.data.status -eq "completed") {
                Write-Host "`n✅ Export completed!" -ForegroundColor Green
                Write-Host "  Total Records: $($status.data.total_records)" -ForegroundColor White
                Write-Host "  File: $($status.data.filepath)" -ForegroundColor White
                break
            }
            
            if ($status.data.status -eq "failed") {
                Write-Host "`n❌ Export failed!" -ForegroundColor Red
                Write-Host "  Error: $($status.data.error_message)" -ForegroundColor Red
                break
            }
            
            Start-Sleep -Seconds 2
            $checkCount++
            
        } catch {
            Write-Host "  Error checking status: $($_.Exception.Message)" -ForegroundColor Red
            break
        }
    }
    
} catch {
    Write-Host "❌ Failed: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host ""

# Test 6: Get My Exports
Write-Host "Test 6: Get My Exports History" -ForegroundColor Yellow
Write-Host "GET $baseUrl/api/downloads-v2/my-exports" -ForegroundColor Gray
try {
    $myExports = Invoke-RestMethod -Uri "$baseUrl/api/downloads-v2/my-exports" -Method GET -Headers $headers
    Write-Host "✅ Success!" -ForegroundColor Green
    Write-Host "  Total Exports: $($myExports.data.Count)" -ForegroundColor White
    Write-Host "  Version: $($myExports.version)" -ForegroundColor White
    
    if ($myExports.data.Count -gt 0) {
        Write-Host "`n  Recent exports:" -ForegroundColor Cyan
        $myExports.data | Select-Object -First 5 | Format-Table job_id, type, date, status, total_records, created_at -AutoSize
    }
} catch {
    Write-Host "❌ Failed: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host ""

# Test 7: Get Statistics
Write-Host "Test 7: Get Redis Queue Statistics" -ForegroundColor Yellow
Write-Host "GET $baseUrl/api/downloads-v2/stats" -ForegroundColor Gray
try {
    $stats = Invoke-RestMethod -Uri "$baseUrl/api/downloads-v2/stats" -Method GET -Headers $headers
    Write-Host "✅ Success!" -ForegroundColor Green
    Write-Host "  Queue Size: $($stats.data.queue_size) jobs" -ForegroundColor White
    Write-Host "  Recent Jobs in Redis: $($stats.data.recent_jobs_in_redis)" -ForegroundColor White
    Write-Host "  Version: $($stats.data.version)" -ForegroundColor White
    
    if ($stats.data.database_stats) {
        Write-Host "`n  Database Stats:" -ForegroundColor Cyan
        $stats.data.database_stats | Format-Table -AutoSize
    }
} catch {
    Write-Host "❌ Failed: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Testing Complete!" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Redis monitoring commands
Write-Host "Redis Monitoring Commands:" -ForegroundColor Yellow
Write-Host "  redis-cli LLEN queues:exports-v2" -ForegroundColor White
Write-Host "  redis-cli KEYS export_job_v2:*" -ForegroundColor White
Write-Host "  redis-cli MONITOR" -ForegroundColor White
Write-Host ""

# Service status
Write-Host "Check V2 Worker Service:" -ForegroundColor Yellow
Write-Host "  Get-Service AlertPortalQueueWorkerV2" -ForegroundColor White
Write-Host "  Get-Content storage\logs\queue-worker-v2-service.log -Tail 50" -ForegroundColor White
Write-Host ""
