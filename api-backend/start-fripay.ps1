# FriPay Startup Script — application unique (fusion users+payments+admin)
# ROOT = dossier ou se trouve ce script (portable, fonctionne pour tout collaborateur qui clone le repo)
$ROOT = $PSScriptRoot
$APP = "$ROOT\fripay"

function Get-PhpVersion([string]$phpPath) {
    return (& $phpPath -r "echo PHP_VERSION;" 2>$null)
}

# Detection automatique de PHP (PATH, puis emplacement winget en repli).
$PHP = $null
$candidate = (Get-Command php -ErrorAction SilentlyContinue).Source
if ($candidate -and [version](Get-PhpVersion $candidate) -ge [version]"8.3.0") {
    $PHP = $candidate
}
if (-not $PHP) {
    $wingetCandidates = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Filter "php.exe" -Recurse -ErrorAction SilentlyContinue | Select-Object -ExpandProperty FullName
    foreach ($wp in $wingetCandidates) {
        if ([version](Get-PhpVersion $wp) -ge [version]"8.3.0") { $PHP = $wp; break }
    }
}
if (-not $PHP -and $candidate) {
    Write-Host "ERREUR: seule PHP $(Get-PhpVersion $candidate) trouvee, mais les dependances exigent >= 8.3.0. Installez-la (ex: winget install PHP.PHP) et relancez." -ForegroundColor Red
    exit 1
}
if (-not $PHP) {
    Write-Host "ERREUR: PHP introuvable. Installez PHP (ex: winget install PHP.PHP) et relancez." -ForegroundColor Red
    exit 1
}
Write-Host "[PHP] Utilisation de $PHP ($(Get-PhpVersion $PHP))" -ForegroundColor Cyan

Write-Host "=== FriPay - Demarrage (application unique) ===" -ForegroundColor Green

# Nettoyage prealable : tue tout processus deja present sur le port 8080
function Stop-PortProcess([int]$port) {
    $conns = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue
    if ($conns) {
        $pids = $conns | Select-Object -ExpandProperty OwningProcess -Unique
        foreach ($procId in $pids) {
            try {
                Stop-Process -Id $procId -Force -ErrorAction Stop
                Write-Host "  Port $port : processus existant PID $procId arrete" -ForegroundColor Yellow
            } catch {
                Write-Host "  Port $port : impossible d'arreter PID $procId ($_)" -ForegroundColor Red
            }
        }
    }
}
Write-Host "[nettoyage] Verification du port 8080..." -ForegroundColor Cyan
Stop-PortProcess -port 8080
Start-Sleep 1

# XAMPP - ouverture du panneau de controle pour un retour visuel (non bloquant)
if ((Test-Path "C:\xampp\xampp-control.exe") -and -not (Get-Process xampp-control -ErrorAction SilentlyContinue)) {
    Write-Host "[XAMPP] Ouverture du panneau de controle..." -ForegroundColor Cyan
    Start-Process -FilePath "C:\xampp\xampp-control.exe"
}

# MySQL (XAMPP) - pas un service Windows, doit etre lance manuellement.
$MYSQL_BIN = "C:\xampp\mysql\bin\mysql.exe"
$DB_NAME = "fripay"

$mysqlProc = Get-Process mysqld -ErrorAction SilentlyContinue
if ($mysqlProc) {
    Write-Host "[1/5] MySQL (XAMPP) deja demarre (PID: $($mysqlProc.Id))" -ForegroundColor Green
} else {
    Write-Host "[1/5] MySQL (XAMPP)..." -NoNewline
    Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList "--defaults-file=C:\xampp\mysql\bin\my.ini" -WindowStyle Hidden
    Start-Sleep 3
    $mysqlProc = Get-Process mysqld -ErrorAction SilentlyContinue
    if ($mysqlProc) {
        Write-Host " OK (PID: $($mysqlProc.Id))" -ForegroundColor Green
    } else {
        Write-Host " ECHEC - verifier C:\xampp\mysql\bin\mysqld.exe" -ForegroundColor Red
    }
}

# Attente que MySQL accepte les connexions
Write-Host "[1/5] Attente de la disponibilite de MySQL..." -NoNewline
$mysqlReady = $false
for ($i = 0; $i -lt 20; $i++) {
    & $MYSQL_BIN -u root -e "SELECT 1;" *> $null
    if ($LASTEXITCODE -eq 0) { $mysqlReady = $true; break }
    Start-Sleep 1
}
if ($mysqlReady) {
    Write-Host " OK" -ForegroundColor Green
} else {
    Write-Host " ECHEC - MySQL ne repond pas (verifier XAMPP)" -ForegroundColor Red
}

# Creation de la base si absente (le schema est cree/mis a jour par les
# migrations Artisan ci-dessous, pas par un dump SQL statique)
if ($mysqlReady) {
    Write-Host "[2/5] Base de donnees '$DB_NAME'..." -NoNewline
    & $MYSQL_BIN -u root -e "CREATE DATABASE IF NOT EXISTS ``$DB_NAME`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" *> $null
    Write-Host " OK" -ForegroundColor Green
}

# .env : cree depuis .env.example si absent, cle d'application generee
if (-not (Test-Path "$APP\.env")) {
    Write-Host "[3/5] Creation de .env depuis .env.example..." -ForegroundColor Yellow
    Copy-Item "$APP\.env.example" "$APP\.env"
}

# Dependances Composer : un seul composer.json pour toute l'appli desormais
if (-not (Test-Path "$APP\vendor")) {
    Write-Host "[3/5] Installation des dependances (composer install, 1ere fois seulement)..." -ForegroundColor Yellow
    Push-Location $APP
    composer install --no-interaction
    Pop-Location
}

Push-Location $APP
$hasKey = (Get-Content .env | Select-String "^APP_KEY=.+").Count -gt 0
if (-not $hasKey) {
    Write-Host "[3/5] Generation de la cle d'application..." -ForegroundColor Yellow
    & $PHP artisan key:generate --ansi
}

# Migrations (idempotent : ne rejoue que ce qui manque)
Write-Host "[4/5] Migrations..." -NoNewline
& $PHP artisan migrate --force 2>&1 | Out-Null
Write-Host " OK" -ForegroundColor Green
Pop-Location

# Lancement de l'appli unique (port 8080, comme l'ancien gateway — l'app
# mobile n'a donc rien a reconfigurer)
Write-Host "[5/5] FriPay (port 8080)..." -NoNewline
$p1 = Start-Process -NoNewWindow -FilePath $PHP -ArgumentList "-S 0.0.0.0:8080 -t `"$APP\public`"" -PassThru
Start-Sleep 2
Write-Host " OK (PID: $($p1.Id))" -ForegroundColor Green

Write-Host "`n=== FriPay est demarre ! ===" -ForegroundColor Green

# Warm-up : le serveur PHP integre (php -S) est mono-thread et le tout
# premier appel declenche le boot complet de Laravel (autoload, service
# providers...). On absorbe ce cout ici, pendant le demarrage.
Write-Host "Warm-up (1er boot Laravel)..." -NoNewline
try {
    Invoke-WebRequest -Uri "http://127.0.0.1:8080/api/v1/up" -TimeoutSec 25 -UseBasicParsing *> $null
    Write-Host " OK" -ForegroundColor Green
} catch {
    Write-Host "`n  Warm-up echoue ($_)" -ForegroundColor Yellow
}

Write-Host "FriPay: http://localhost:8080/api/v1/up"
Write-Host "`nPID: $($p1.Id)"
