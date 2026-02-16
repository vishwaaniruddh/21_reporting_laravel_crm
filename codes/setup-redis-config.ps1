# Setup Redis Configuration in .env
# This script adds Redis configuration to your .env file

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Redis Configuration Setup" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$envFile = ".env"

if (-not (Test-Path $envFile)) {
    Write-Host "❌ .env file not found" -ForegroundColor Red
    exit 1
}

Write-Host "Step 1: Checking current .env configuration..." -ForegroundColor Yellow

$envContent = Get-Content $envFile -Raw

# Check if Redis config already exists
if ($envContent -match "REDIS_HOST") {
    Write-Host "  Redis configuration already exists in .env" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Current Redis settings:" -ForegroundColor Cyan
    Get-Content $envFile | Select-String "REDIS_"
    Write-Host ""
} else {
    Write-Host "  No Redis configuration found" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Step 2: Adding Redis configuration..." -ForegroundColor Yellow
    
    $redisConfig = @"

# Redis Configuration (for Downloads V2)
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0
"@
    
    Add-Content -Path $envFile -Value $redisConfig
    Write-Host "✅ Redis configuration added to .env" -ForegroundColor Green
}

Write-Host ""
Write-Host "Step 3: Clearing Laravel config cache..." -ForegroundColor Yellow

php artisan config:clear | Out-Null
Write-Host "✅ Config cache cleared" -ForegroundColor Green

Write-Host ""
Write-Host "Step 4: Testing Redis connection..." -ForegroundColor Yellow

# Check if Redis server is running
try {
    $redisPing = redis-cli ping 2>&1
    if ($redisPing -eq "PONG") {
        Write-Host "✅ Redis server is running" -ForegroundColor Green
    } else {
        Write-Host "⚠️  Redis server not responding" -ForegroundColor Yellow
        Write-Host "Start Redis with: redis-server" -ForegroundColor White
    }
} catch {
    Write-Host "⚠️  Redis CLI not found" -ForegroundColor Yellow
    Write-Host "Make sure Redis is installed and in PATH" -ForegroundColor White
}

Write-Host ""
Write-Host "Step 5: Testing PHP Redis connection..." -ForegroundColor Yellow

$testScript = @'
try {
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);
    $result = $redis->ping();
    echo "PHP Redis: Connected - " . $result;
} catch (Exception $e) {
    echo "PHP Redis: Failed - " . $e->getMessage();
}
'@

$testResult = php -r $testScript 2>&1
Write-Host "  $testResult" -ForegroundColor White

if ($testResult -like "*Connected*") {
    Write-Host "✅ PHP can connect to Redis!" -ForegroundColor Green
} else {
    Write-Host "❌ PHP cannot connect to Redis" -ForegroundColor Red
    Write-Host ""
    Write-Host "Troubleshooting:" -ForegroundColor Yellow
    Write-Host "  1. Make sure Redis server is running: redis-server" -ForegroundColor White
    Write-Host "  2. Check if Redis extension is loaded: php -m | findstr redis" -ForegroundColor White
    Write-Host "  3. Check Redis is listening: redis-cli ping" -ForegroundColor White
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Redis Configuration Complete!" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Configuration:" -ForegroundColor Cyan
Write-Host "  Host: 127.0.0.1" -ForegroundColor White
Write-Host "  Port: 6379" -ForegroundColor White
Write-Host "  Database: 0" -ForegroundColor White
Write-Host ""

Write-Host "Next Steps:" -ForegroundColor Cyan
Write-Host "  1. Create V2 queue worker service" -ForegroundColor White
Write-Host "  2. Test Downloads V2 endpoints" -ForegroundColor White
Write-Host ""

Write-Host "Run: .\codes\create-queue-worker-v2-service.ps1" -ForegroundColor Yellow
Write-Host ""
