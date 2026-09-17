# FriPay Microservices Startup Script
# ROOT = dossier ou se trouve ce script (portable, fonctionne pour tout collaborateur qui clone le repo)
$ROOT = $PSScriptRoot

function Get-PhpVersion([string]$phpPath) {
    return (& $phpPath -r "echo PHP_VERSION;" 2>$null)
}

# Detection automatique de PHP (PATH, puis emplacement winget en repli).
# Les vendor/ ont ete composer install avec PHP >= 8.3 : on rejette toute
# version inferieure (ex: XAMPP 8.2.4 trouve en premier dans le PATH).
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

Write-Host "=== FriPay - Demarrage des microservices ===" -ForegroundColor Green

# Nettoyage prealable : tue tout processus deja present sur ces 4 ports
# (evite le bug de doublons PHP deja rencontre -> routage non deterministe,
# erreurs intermittentes cote app mobile comme sur l'ecran Plaintes).
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

Write-Host "[nettoyage] Verification des ports 8000/8001/8002/8080..." -ForegroundColor Cyan
foreach ($p in 8000, 8001, 8002, 8080) { Stop-PortProcess -port $p }
Start-Sleep 1

# XAMPP - ouverture du panneau de controle pour un retour visuel (non bloquant)
if ((Test-Path "C:\xampp\xampp-control.exe") -and -not (Get-Process xampp-control -ErrorAction SilentlyContinue)) {
    Write-Host "[XAMPP] Ouverture du panneau de controle..." -ForegroundColor Cyan
    Start-Process -FilePath "C:\xampp\xampp-control.exe"
}

# MySQL (XAMPP) - pas un service Windows, doit etre lance manuellement.
# On verifie s'il tourne deja avant de le demarrer pour eviter les doublons.
$MYSQL_BIN = "C:\xampp\mysql\bin\mysql.exe"
$SQL_DUMP = "$ROOT\fripay.sql"
$DB_NAME = "fripay"

$mysqlProc = Get-Process mysqld -ErrorAction SilentlyContinue
if ($mysqlProc) {
    Write-Host "[0/5] MySQL (XAMPP) deja demarre (PID: $($mysqlProc.Id))" -ForegroundColor Green
} else {
    Write-Host "[0/5] MySQL (XAMPP)..." -NoNewline
    Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList "--defaults-file=C:\xampp\mysql\bin\my.ini" -WindowStyle Hidden
    Start-Sleep 3
    $mysqlProc = Get-Process mysqld -ErrorAction SilentlyContinue
    if ($mysqlProc) {
        Write-Host " OK (PID: $($mysqlProc.Id))" -ForegroundColor Green
    } else {
        Write-Host " ECHEC - verifier C:\xampp\mysql\bin\mysqld.exe" -ForegroundColor Red
    }
}

# Attente que MySQL accepte les connexions (utile au tout premier demarrage)
Write-Host "[0/5] Attente de la disponibilite de MySQL..." -NoNewline
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

# Creation de la base de donnees si absente + import du schema initial (1re fois seulement)
if ($mysqlReady) {
    Write-Host "[0/5] Base de donnees '$DB_NAME'..." -NoNewline
    & $MYSQL_BIN -u root -e "CREATE DATABASE IF NOT EXISTS ``$DB_NAME`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" *> $null

    $tableCount = & $MYSQL_BIN -u root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME';"

    if ([int]$tableCount -eq 0) {
        Write-Host " creee, import du schema (fripay.sql)..." -NoNewline
        if (Test-Path $SQL_DUMP) {
            cmd /c "`"$MYSQL_BIN`" -u root $DB_NAME < `"$SQL_DUMP`""
            Write-Host " OK" -ForegroundColor Green
        } else {
            Write-Host " ECHEC - fripay.sql introuvable dans $ROOT" -ForegroundColor Red
        }
    } else {
        Write-Host " OK ($tableCount tables deja presentes)" -ForegroundColor Green
    }
}

# Start Users Service (port 8000)
Write-Host "[1/5] Users Service (port 8000)..." -NoNewline
$p1 = Start-Process -NoNewWindow -FilePath $PHP -ArgumentList "-S 0.0.0.0:8000 -t `"$ROOT\fripay-users\public`"" -PassThru
Start-Sleep 2
Write-Host " OK (PID: $($p1.Id))" -ForegroundColor Green

# Start Payments Service (port 8001)
Write-Host "[2/5] Payments Service (port 8001)..." -NoNewline
$p2 = Start-Process -NoNewWindow -FilePath $PHP -ArgumentList "-S 0.0.0.0:8001 -t `"$ROOT\fripay-payments\public`"" -PassThru
Start-Sleep 2
Write-Host " OK (PID: $($p2.Id))" -ForegroundColor Green

# Start Admin Service (port 8002)
Write-Host "[3/5] Admin Service (port 8002)..." -NoNewline
$p3 = Start-Process -NoNewWindow -FilePath $PHP -ArgumentList "-S 0.0.0.0:8002 -t `"$ROOT\fripay-admin\public`"" -PassThru
Start-Sleep 2
Write-Host " OK (PID: $($p3.Id))" -ForegroundColor Green

# Start API Gateway (port 8080)
Write-Host "[4/5] API Gateway (port 8080)..." -NoNewline
$p4 = Start-Process -NoNewWindow -FilePath $PHP -ArgumentList "-S 0.0.0.0:8080 -t `"$ROOT\fripay-gateway\public`"" -PassThru
Start-Sleep 2
Write-Host " OK (PID: $($p4.Id))" -ForegroundColor Green

Write-Host "`n=== Tous les services sont demarres ! ===" -ForegroundColor Green

# Warm-up : le serveur PHP integre (php -S) est mono-thread et le tout
# premier appel a chaque service declenche le boot complet de Laravel
# (autoload, service providers...), qui peut depasser 15s a froid. Sans ce
# warm-up, c'est le TELEPHONE qui encaissait ce delai au premier login
# (gateway -> "Circuit breaker failure : timed out after 15005ms" -> 502
# cote app). On absorbe ce cout ici, pendant le demarrage, pas devant l'usager.
Write-Host "[5/5] Warm-up des services (1er boot Laravel)..." -NoNewline
foreach ($warmup in @(
    @{ Name = "Users";    Url = "http://127.0.0.1:8000/api/v1/up" },
    @{ Name = "Payments"; Url = "http://127.0.0.1:8001/api/v1/up" },
    @{ Name = "Admin";    Url = "http://127.0.0.1:8002/api/v1/up" }
)) {
    try {
        Invoke-WebRequest -Uri $warmup.Url -TimeoutSec 25 -UseBasicParsing *> $null
    } catch {
        Write-Host "`n  $($warmup.Name) : warm-up echoue ($_)" -ForegroundColor Yellow
    }
}
Write-Host " OK" -ForegroundColor Green

Write-Host "Users:    http://localhost:8000/api/v1/up"
Write-Host "Payments: http://localhost:8001/api/v1/up"
Write-Host "Admin:    http://localhost:8002/api/v1/up"
Write-Host "Gateway:  http://localhost:8080/api/v1/auth/register"
Write-Host "`nPIDs: users=$($p1.Id) payments=$($p2.Id) admin=$($p3.Id) gateway=$($p4.Id)"
