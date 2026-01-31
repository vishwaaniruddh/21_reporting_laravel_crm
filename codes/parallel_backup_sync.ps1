# Parallel Backup Sync - Ultra Fast Processing
# Runs 4 parallel processes to sync 9.3M records in ~3-5 minutes

Write-Host "=== PARALLEL BACKUP SYNC (Ultra Fast) ===" -ForegroundColor Cyan
Write-Host "Strategy: 4 parallel processes with ID ranges" -ForegroundColor Yellow
Write-Host "Expected completion: 3-5 minutes" -ForegroundColor Green
Write-Host ""

# Stop the current slow service first
Write-Host "Stopping current AlertBackupSync service..." -ForegroundColor Yellow
Stop-Service AlertBackupSync -ErrorAction SilentlyContinue
Write-Host "✓ Service stopped" -ForegroundColor Green
Write-Host ""

# Define ID ranges for parallel processing
$ranges = @(
    @{Name="Process1"; StartId=0; EndId=2500000; BatchSize=15000},
    @{Name="Process2"; StartId=2500000; EndId=5000000; BatchSize=15000},
    @{Name="Process3"; StartId=5000000; EndId=7500000; BatchSize=15000},
    @{Name="Process4"; StartId=7500000; EndId=10000000; BatchSize=15000}
)

Write-Host "ID Range Distribution:" -ForegroundColor Cyan
foreach ($range in $ranges) {
    $recordCount = ($range.EndId - $range.StartId) / 1000
    Write-Host "  $($range.Name): IDs $($range.StartId) - $($range.EndId) (~${recordCount}K records)" -ForegroundColor White
}
Write-Host ""

# Create log directory
$logDir = "storage\logs\parallel_sync"
if (!(Test-Path $logDir)) {
    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
}

Write-Host "Starting parallel sync processes..." -ForegroundColor Yellow

$jobs = @()
$startTime = Get-Date

foreach ($range in $ranges) {
    $command = "php artisan sync:backup-data --start-id=$($range.StartId) --batch-size=$($range.BatchSize) --continuous --force"
    $logFile = "$logDir\$($range.Name).log"
    
    Write-Host "  Starting $($range.Name)..." -ForegroundColor Gray
    
    # Start background job
    $job = Start-Job -ScriptBlock {
        param($cmd, $log, $workDir)
        Set-Location $workDir
        Invoke-Expression "$cmd > $log 2>&1"
    } -ArgumentList $command, $logFile, (Get-Location).Path
    
    $jobs += @{Job=$job; Name=$range.Name; LogFile=$logFile}
}

Write-Host "✓ All processes started" -ForegroundColor Green
Write-Host ""

# Monitor progress
Write-Host "Monitoring progress (press Ctrl+C to stop monitoring)..." -ForegroundColor Yellow
Write-Host ""

try {
    while ($true) {
        $allCompleted = $true
        $currentTime = Get-Date
        $elapsed = $currentTime - $startTime
        
        Write-Host "=== Progress Update [Elapsed: $($elapsed.ToString('hh\:mm\:ss'))] ===" -ForegroundColor Cyan
        
        foreach ($jobInfo in $jobs) {
            $job = $jobInfo.Job
            $name = $jobInfo.Name
            $logFile = $jobInfo.LogFile
            
            if ($job.State -eq "Running") {
                $allCompleted = $false
                
                # Get last few lines of log for progress
                if (Test-Path $logFile) {
                    $lastLines = Get-Content $logFile -Tail 3 -ErrorAction SilentlyContinue
                    $progressLine = $lastLines | Where-Object { $_ -match "Progress:" } | Select-Object -Last 1
                    
                    if ($progressLine) {
                        Write-Host "  $name`: $progressLine" -ForegroundColor White
                    } else {
                        Write-Host "  $name`: Running..." -ForegroundColor Gray
                    }
                } else {
                    Write-Host "  $name`: Starting..." -ForegroundColor Gray
                }
            } elseif ($job.State -eq "Completed") {
                Write-Host "  $name`: ✓ Completed" -ForegroundColor Green
            } elseif ($job.State -eq "Failed") {
                Write-Host "  $name`: ✗ Failed" -ForegroundColor Red
            }
        }
        
        if ($allCompleted) {
            break
        }
        
        Write-Host ""
        Start-Sleep -Seconds 10
    }
} catch {
    Write-Host "Monitoring stopped by user" -ForegroundColor Yellow
}

# Wait for all jobs to complete
Write-Host "Waiting for all processes to complete..." -ForegroundColor Yellow
$jobs | ForEach-Object { Wait-Job $_.Job | Out-Null }

$endTime = Get-Date
$totalTime = $endTime - $startTime

Write-Host ""
Write-Host "=== PARALLEL SYNC COMPLETED ===" -ForegroundColor Cyan
Write-Host "Total time: $($totalTime.ToString('hh\:mm\:ss'))" -ForegroundColor Green
Write-Host ""

# Show results
Write-Host "Process Results:" -ForegroundColor Yellow
foreach ($jobInfo in $jobs) {
    $job = $jobInfo.Job
    $name = $jobInfo.Name
    $logFile = $jobInfo.LogFile
    
    $status = if ($job.State -eq "Completed") { "✓ Success" } else { "✗ Failed" }
    $color = if ($job.State -eq "Completed") { "Green" } else { "Red" }
    
    Write-Host "  $name`: $status" -ForegroundColor $color
    
    # Show final stats from log
    if (Test-Path $logFile) {
        $completedLine = Get-Content $logFile | Where-Object { $_ -match "Total records processed:" } | Select-Object -Last 1
        if ($completedLine) {
            Write-Host "    $completedLine" -ForegroundColor Gray
        }
    }
}

# Cleanup jobs
$jobs | ForEach-Object { Remove-Job $_.Job -Force }

Write-Host ""
Write-Host "Next steps:" -ForegroundColor Yellow
Write-Host "1. Verify all data synced successfully" -ForegroundColor White
Write-Host "2. Clean up source table: TRUNCATE TABLE alerts_all_data;" -ForegroundColor White
Write-Host "3. Remove AlertBackupSync service (no longer needed)" -ForegroundColor White
Write-Host ""
Write-Host "View detailed logs in: $logDir" -ForegroundColor Gray