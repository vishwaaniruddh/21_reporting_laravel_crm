# Test if the service command works
$phpPath = "C:\wamp64\bin\php\php8.2.0\php.exe"
$projectPath = "C:\wamp64\www\comfort_reporting_crm\dual-database-app"

Write-Host "Testing service command..." -ForegroundColor Cyan
Write-Host "PHP: $phpPath"
Write-Host "Project: $projectPath"
Write-Host ""

Set-Location $projectPath

Write-Host "Running command for 10 seconds..." -ForegroundColor Yellow
$job = Start-Job -ScriptBlock {
    param($php, $path)
    Set-Location $path
    & $php artisan sites:update-worker --poll-interval=5 --batch-size=100 --max-retries=3
} -ArgumentList $phpPath, $projectPath

Start-Sleep -Seconds 10
$output = Receive-Job $job
Stop-Job $job
Remove-Job $job

Write-Host "Output:" -ForegroundColor Green
Write-Host $output

Write-Host ""
Write-Host "If you see worker output above, the command works!" -ForegroundColor Green
