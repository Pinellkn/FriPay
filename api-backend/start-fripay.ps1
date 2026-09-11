# FriPay Microservices Startup Script
# Portable : fonctionne depuis n'importe quel emplacement du clone (pas de chemin code en dur).
$ROOT = $PSScriptRoot

Write-Host "=== FriPay - Demarrage des microservices ===" -ForegroundColor Green

# --- Detection automatique de PHP >= 8.3 ---
function Find-Php {
    # 1) PHP deja dans le PATH
    $inPath = Get-Command php -ErrorAction SilentlyContinue
    if ($inPath) {
        $v = & $inPath.Source -r "echo PHP_VERSION;"
        if ([version]($v -replace '-.*','') -ge [version]"8.3.0") { return $inPath.Source }
    }
    # 2) Emplacements WinGet courants
    $candidates = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Filter "php.exe" -Recurse -ErrorAction SilentlyContinue |
        Where-Object { $_.FullName -match "PHP\.PHP\.(8\.[3-9]|9\.)" }
    foreach ($c in $candidates) { return $c.FullName }
    return $null
}

$PHP = Find-Php
if (-not $PHP) {
    Write-Host "[ERREUR] PHP >= 8.3 introuvable. Installe-le (winget install PHP.PHP.8.5) puis relance ce script." -ForegroundColor Red
    exit 1
}
Write-Host "[php] Utilisation de : $PHP" -ForegroundColor Cyan

# --- Detection automatique de XAMPP ---
function Find-Xampp {
    foreach ($drive in (Get-PSDrive -PSProvider FileSystem)) {
        $p = "$($drive.Root)xampp"
        if (Test-Path "$p\mysql\bin\mysqld.exe") { return $p }
    }
    return $null
}
$XAMPP = Find-Xampp
if (-not $XAMPP) {
    Write-Host "[ERREUR] XAMPP introuvable (cherche <lecteur>:\xampp). Installe XAMPP puis relance ce script." -ForegroundColor Red
    exit 1
}
$MYSQLD = "$XAMPP\mysql\bin\mysqld.exe"
$MYSQL_INI = "$XAMPP\mysql\bin\my.ini"
$MYSQL_CLI = "$XAMPP\mysql\bin\mysql.exe"

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

# MySQL (XAMPP) - pas un service Windows, doit etre lance manuellement.
# On verifie s'il tourne deja avant de le demarrer pour eviter les doublons.
$mysqlProc = Get-Process mysqld -ErrorAction SilentlyContinue
if ($mysqlProc) {
    Write-Host "[0/5] MySQL (XAMPP) deja demarre (PID: $($mysqlProc.Id))" -ForegroundColor Green
} else {
    Write-Host "[0/5] MySQL (XAMPP)..." -NoNewline
    Start-Process -FilePath $MYSQLD -ArgumentList "--defaults-file=`"$MYSQL_INI`"" -WindowStyle Hidden
    Start-Sleep 3
    $mysqlProc = Get-Process mysqld -ErrorAction SilentlyContinue
    if ($mysqlProc) {
        Write-Host " OK (PID: $($mysqlProc.Id))" -ForegroundColor Green
    } else {
        Write-Host " ECHEC - verifier $MYSQLD" -ForegroundColor Red
        exit 1
    }
}

# --- Creation de la base de donnees + import du schema (idempotent) ---
Write-Host "[1/5] Base de donnees 'fripay'..." -NoNewline
& $MYSQL_CLI -u root -e "CREATE DATABASE IF NOT EXISTS fripay CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>$null
$tableCount = (& $MYSQL_CLI -u root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='fripay';" 2>$null)
if ([int]$tableCount -eq 0) {
    Write-Host " creee, import du schema..." -NoNewline
    Get-Content "$ROOT\fripay-schema.sql" -Raw | & $MYSQL_CLI -u root fripay 2>$null
    Write-Host " OK" -ForegroundColor Green
} else {
    Write-Host " deja prete ($tableCount tables)" -ForegroundColor Green
}

# --- Dependances composer, .env, cle app : dans le bon ordre pour chaque service ---
foreach ($svc in "fripay-users","fripay-payments","fripay-admin","fripay-gateway") {
    $svcPath = "$ROOT\$svc"

    if ((Test-Path "$svcPath\composer.json") -and -not (Test-Path "$svcPath\vendor")) {
        Write-Host "  [$svc] installation des dependances composer..." -ForegroundColor Yellow
        Push-Location $svcPath
        composer install --no-interaction --quiet
        Pop-Location
    }

    if (-not (Test-Path "$svcPath\.env") -and (Test-Path "$svcPath\.env.example")) {
        Copy-Item "$svcPath\.env.example" "$svcPath\.env"
        Write-Host "  [$svc] .env cree depuis .env.example" -ForegroundColor Yellow
        if ((Test-Path "$svcPath\artisan") -and (Test-Path "$svcPath\vendor\autoload.php")) {
            & $PHP "$svcPath\artisan" key:generate --ansi | Out-Null
        }
    }
}

# Start Users Service (port 8000)
Write-Host "[2/5] Users Service (port 8000)..." -NoNewline
$p1 = Start-Process -NoNewWindow -FilePath $PHP -ArgumentList "-S 0.0.0.0:8000 -t `"$ROOT\fripay-users\public`"" -PassThru
Start-Sleep 2
Write-Host " OK (PID: $($p1.Id))" -ForegroundColor Green

# Start Payments Service (port 8001)
Write-Host "[3/5] Payments Service (port 8001)..." -NoNewline
$p2 = Start-Process -NoNewWindow -FilePath $PHP -ArgumentList "-S 0.0.0.0:8001 -t `"$ROOT\fripay-payments\public`"" -PassThru
Start-Sleep 2
Write-Host " OK (PID: $($p2.Id))" -ForegroundColor Green

# Start Admin Service (port 8002)
Write-Host "[4/5] Admin Service (port 8002)..." -NoNewline
$p3 = Start-Process -NoNewWindow -FilePath $PHP -ArgumentList "-S 0.0.0.0:8002 -t `"$ROOT\fripay-admin\public`"" -PassThru
Start-Sleep 2
Write-Host " OK (PID: $($p3.Id))" -ForegroundColor Green

# Start API Gateway (port 8080)
Write-Host "[5/5] API Gateway (port 8080)..." -NoNewline
$p4 = Start-Process -NoNewWindow -FilePath $PHP -ArgumentList "-S 0.0.0.0:8080 -t `"$ROOT\fripay-gateway\public`"" -PassThru
Start-Sleep 2
Write-Host " OK (PID: $($p4.Id))" -ForegroundColor Green

Write-Host "`n=== Tous les services sont demarres ! ===" -ForegroundColor Green
Write-Host "Users:    http://localhost:8000/api/v1/up"
Write-Host "Payments: http://localhost:8001/api/v1/up"
Write-Host "Admin:    http://localhost:8002/api/v1/up"
Write-Host "Gateway:  http://localhost:8080/api/v1/auth/register"
Write-Host "`nPIDs: users=$($p1.Id) payments=$($p2.Id) admin=$($p3.Id) gateway=$($p4.Id)"
