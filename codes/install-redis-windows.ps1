# Install Redis for Windows
# This script downloads and sets up Redis

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Redis Installation for Windows" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$redisVersion = "3.0.504"
$redisUrl = "https://github.com/microsoftarchive/redis/releases/download/win-$redisVersion/Redis-x64-$redisVersion.zip"
$downloadPath = "$env:TEMP\Redis-x64-$redisVersion.zip"
$installPath = "C:\Redis"

Write-Host "Step 1: Downloading Redis..." -ForegroundColor Yellow
Write-Host "  URL: $redisUrl" -ForegroundColor Gray

try {
    Invoke-WebRequest -Uri $redisUrl -OutFile $downloadPath
    Write-Host "✅ Downloaded successfully" -ForegroundColor Green
} catch {
    Write-Host "❌ Download failed: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host ""
    Write-Host "Manual download:" -ForegroundColor Yellow
    Write-Host "  1. Go to: https://github.com/microsoftarchive/redis/releases" -ForegroundColor White
    Write-Host "  2. Download: Redis-x64-3.0.504.zip" -ForegroundColor White
    Write-Host "  3. Extract to: C:\Redis" -ForegroundColor White
    exit 1
}

Write-Host ""
Write-Host "Step 2: Extracting Redis..." -ForegroundColor Yellow

if (Test-Path $installPath) {
    Write-Host "  Redis folder already exists at $installPath" -ForegroundColor Yellow
    Write-Host "  Remove it? (Y/N): " -NoNewline
    $remove = Read-Host
    if ($remove -eq "Y") {
        Remove-Item -Path $installPath -Recurse -Force
    } else {
        Write-Host "  Skipping extraction" -ForegroundColor Yellow
    }
}

if (-not (Test-Path $installPath)) {
    Expand-Archive -Path $downloadPath -DestinationPath $installPath -Force
    Write-Host "✅ Extracted to $installPath" -ForegroundColor Green
}

Write-Host ""
Write-Host "Step 3: Adding Redis to PATH..." -ForegroundColor Yellow

$currentPath = [Environment]::GetEnvironmentVariable("Path", "Machine")
if ($currentPath -notlike "*$installPath*") {
    [Environment]::SetEnvironmentVariable("Path", "$currentPath;$installPath", "Machine")
    $env:Path = "$env:Path;$installPath"
    Write-Host "✅ Added to PATH" -ForegroundColor Green
} else {
    Write-Host "  Already in PATH" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Step 4: Testing Redis..." -ForegroundColor Yellow

# Start Redis server in background
Start-Process -FilePath "$installPath\redis-server.exe" -WindowStyle Hidden

Start-Sleep -Seconds 2

# Test connection
try {
    $testResult = & "$installPath\redis-cli.exe" ping 2>&1
    if ($testResult -eq "PONG") {
        Write-Host "✅ Redis is running!" -ForegroundColor Green
    } else {
        Write-Host "⚠️  Redis started but not responding correctly" -ForegroundColor Yellow
    }
} catch {
    Write-Host "⚠️  Could not test Redis" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Redis Installation Complete!" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Redis Location: $installPath" -ForegroundColor White
Write-Host ""

Write-Host "Commands:" -ForegroundColor Yellow
Write-Host "  Start Redis:  redis-server" -ForegroundColor White
Write-Host "  Test Redis:   redis-cli ping" -ForegroundColor White
Write-Host "  Stop Redis:   redis-cli shutdown" -ForegroundColor White
Write-Host ""

Write-Host "Next Steps:" -ForegroundColor Cyan
Write-Host "  1. Install PHP Redis extension" -ForegroundColor White
Write-Host "  2. Update .env file" -ForegroundColor White
Write-Host "  3. Create V2 queue worker service" -ForegroundColor White
Write-Host ""

Write-Host "Run: .\codes\install-php-redis-extension.ps1" -ForegroundColor Yellow
Write-Host ""
