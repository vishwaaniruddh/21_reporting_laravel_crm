# Quick Setup - Auto-detect paths
# Run as Administrator

$currentPrincipal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "ERROR: Run as Administrator!" -ForegroundColor Red
    Read-Host "Press Enter to exit"
    exit 1
}

Write-Host "=== Quick Setup ===" -ForegroundColor Cyan
Write-Host ""

# Auto-detect PHP
Write-Host "Looking for PHP..." -ForegroundColor Yellow
$PHP_PATH = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $PHP_PATH) {
    $phpLocations = @("C:\php\php.exe", "C:\xampp\php\php.exe", "C:\wamp\bin\php\php8.1.0\php.exe", "C:\wamp64\bin\php\php8.1.0\php.exe", "C:\wamp64\bin\php\php8.4.11\php.exe")
    foreach ($loc in $phpLocations) {
        if (Test-Path $loc) {
            $PHP_PATH = $loc
            break
        }
    }
}
if (-not $PHP_PATH) {
    Write-Host "ERROR: PHP not found!" -ForegroundColor Red
    Read-Host "Press Enter to exit"
    exit 1
}
Write-Host "Found PHP: $PHP_PATH" -ForegroundColor Green

# Auto-detect NSSM
Write-Host "Looking for NSSM..." -ForegroundColor Yellow
$NSSM_PATH = (Get-Command nssm -ErrorAction SilentlyContinue).Source
if (-not $NSSM_PATH) {
    $nssmLocations = @("C:\Windows\System32\nssm.exe", "C:\nssm\nssm-2.24\win64\nssm.exe", "C:\nssm\win64\nssm.exe")
    foreach ($loc in $nssmLocations) {
        if (Test-Path $loc) {
            $NSSM_PATH = $loc
            break
        }
    }
}
if (-not $NSSM_PATH) {
    Write-Host "ERROR: NSSM not found!" -ForegroundColor Red
    $NSSM_PATH = Read-Host "Enter full path to nssm.exe"
    if (-not (Test-Path $NSSM_PATH)) {
        Write-Host "ERROR: Path not found" -ForegroundColor Red
        Read-Host "Press Enter to exit"
        exit 1
    }
}
Write-Host "Found NSSM: $NSSM_PATH" -ForegroundColor Green

# Auto-detect NPM
Write-Host "Looking for NPM..." -ForegroundColor Yellow
$NPM_PATH = (Get-Command npm -ErrorAction SilentlyContinue).Source
if ($NPM_PATH -and $NPM_PATH -like "*.ps1") {
    $NPM_PATH = $NPM_PATH -replace '\.ps1$', '.cmd'
}
if (-not $NPM_PATH -or -not (Test-Path $NPM_PATH)) {
    $npmLocations = @("C:\Program Files\nodejs\npm.cmd", "C:\nvm4w\nodejs\npm.cmd")
    foreach ($loc in $npmLocations) {
        if (Test-Path $loc) {
            $NPM_PATH = $loc
            break
        }
    }
}
if (-not $NPM_PATH) {
    Write-Host "ERROR: NPM not found!" -ForegroundColor Red
    Read-Host "Press Enter to exit"
    exit 1
}
Write-Host "Found NPM: $NPM_PATH" -ForegroundColor Green

$PROJECT_PATH = (Get-Location).Path
Write-Host "Project: $PROJECT_PATH" -ForegroundColor Green
Write-Host ""

$confirm = Read-Host "Continue? (Y/N)"
if ($confirm -ne "Y" -and $confirm -ne "y") {
    exit 0
}

function Create-Service {
    param($Name, $Display, $Desc, $Cmd, $Log, $App)
    Write-Host "Creating $Display..." -ForegroundColor Yellow
    $existing = Get-Service -Name $Name -ErrorAction SilentlyContinue
    if ($existing) {
        & $NSSM_PATH stop $Name 2>$null
        Start-Sleep -Seconds 2
        & $NSSM_PATH remove $Name confirm 2>$null
        Start-Sleep -Seconds 2
    }
    if ($App) {
        & $NSSM_PATH install $Name $App $Cmd
    } else {
        & $NSSM_PATH install $Name $PHP_PATH $Cmd
    }
    & $NSSM_PATH set $Name AppDirectory $PROJECT_PATH
    & $NSSM_PATH set $Name DisplayName $Display
    & $NSSM_PATH set $Name Description $Desc
    & $NSSM_PATH set $Name Start SERVICE_AUTO_START
    & $NSSM_PATH set $Name AppExit Default Restart
    & $NSSM_PATH set $Name AppRestartDelay 5000
    $LogDir = Join-Path $PROJECT_PATH "storage\logs"
    if (-not (Test-Path $LogDir)) {
        New-Item -ItemType Directory -Path $LogDir -Force | Out-Null
    }
    & $NSSM_PATH set $Name AppStdout (Join-Path $LogDir "$Log-service.log")
    & $NSSM_PATH set $Name AppStderr (Join-Path $LogDir "$Log-service-error.log")
    & $NSSM_PATH set $Name AppStdoutCreationDisposition 4
    & $NSSM_PATH set $Name AppStderrCreationDisposition 4
    & $NSSM_PATH set $Name AppRotateFiles 1
    & $NSSM_PATH set $Name AppRotateOnline 1
    & $NSSM_PATH set $Name AppRotateBytes 10485760
    & $NSSM_PATH start $Name
    Start-Sleep -Seconds 2
    $status = & $NSSM_PATH status $Name
    if ($status -eq "SERVICE_RUNNING") {
        Write-Host "Service created: $Display" -ForegroundColor Green
    } else {
        Write-Host "Service created but not running: $Display" -ForegroundColor Yellow
    }
    Write-Host ""
}

Create-Service -Name "AlertPortal" -Display "Alert System Portal" -Desc "Web portal at http://192.168.100.21:9000" -Cmd "artisan serve --host=192.168.100.21 --port=9000" -Log "portal"
Create-Service -Name "AlertViteDev" -Display "Alert Vite Dev Server" -Desc "Vite development server for React frontend" -Cmd "run dev" -Log "vite-dev" -App $NPM_PATH
Create-Service -Name "AlertInitialSync" -Display "Alert Initial Sync Worker" -Desc "Syncs new alerts from MySQL to PostgreSQL every 20 minutes" -Cmd "continuous-initial-sync.php" -Log "initial-sync"
Create-Service -Name "AlertUpdateSync" -Display "Alert Update Sync Worker" -Desc "Syncs alert updates from MySQL to PostgreSQL" -Cmd "artisan sync:update-worker --poll-interval=5 --batch-size=100" -Log "update-sync"

Write-Host "Configuring firewall..." -ForegroundColor Yellow
$fw = Get-NetFirewallRule -DisplayName "Alert Portal" -ErrorAction SilentlyContinue
if (-not $fw) {
    New-NetFirewallRule -DisplayName "Alert Portal" -Direction Inbound -LocalPort 9000 -Protocol TCP -Action Allow | Out-Null
    Write-Host "Firewall rule created" -ForegroundColor Green
} else {
    Write-Host "Firewall rule exists" -ForegroundColor Green
}

Write-Host ""
Write-Host "=== Setup Complete ===" -ForegroundColor Cyan
Write-Host "Services:" -ForegroundColor Green
Write-Host "  1. AlertPortal - http://192.168.100.21:9000"
Write-Host "  2. AlertViteDev - Vite dev server (port 5173)"
Write-Host "  3. AlertInitialSync - Syncing every 20 minutes"
Write-Host "  4. AlertUpdateSync - Syncing every 5 seconds"
Write-Host ""
Write-Host "Check status:" -ForegroundColor Yellow
Write-Host '  Get-Service | Where-Object {$_.Name -like "Alert*"}'
Write-Host ""
Read-Host "Press Enter to exit"
