# FriPay Microservices Startup Script
$PHP = "C:\Users\Pinel\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
$ROOT = "C:\Users\Pinel\Fripay projet\API_Fripay-PushA"

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

# MySQL (XAMPP) - pas un service Windows, doit etre lance manuellement.
# On verifie s'il tourne deja avant de le demarrer pour eviter les doublons.
$mysqlProc = Get-Process mysqld -ErrorAction SilentlyContinue
if ($mysqlProc) {
    Write-Host "[0/4] MySQL (XAMPP) deja demarre (PID: $($mysqlProc.Id))" -ForegroundColor Green
} else {
    Write-Host "[0/4] MySQL (XAMPP)..." -NoNewline
    Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList "--defaults-file=C:\xampp\mysql\bin\my.ini" -WindowStyle Hidden
    Start-Sleep 3
    $mysqlProc = Get-Process mysqld -ErrorAction SilentlyContinue
    if ($mysqlProc) {
        Write-Host " OK (PID: $($mysqlProc.Id))" -ForegroundColor Green
    } else {
        Write-Host " ECHEC - verifier C:\xampp\mysql\bin\mysqld.exe" -ForegroundColor Red
    }
}

# Start Users Service (port 8000)
Write-Host "[1/4] Users Service (port 8000)..." -NoNewline
$p1 = Start-Process -NoNewWindow -FilePath $PHP -ArgumentList "-S 0.0.0.0:8000 -t `"$ROOT\fripay-users\public`"" -PassThru
Start-Sleep 2
Write-Host " OK (PID: $($p1.Id))" -ForegroundColor Green

# Start Payments Service (port 8001)
Write-Host "[2/4] Payments Service (port 8001)..." -NoNewline
$p2 = Start-Process -NoNewWindow -FilePath $PHP -ArgumentList "-S 0.0.0.0:8001 -t `"$ROOT\fripay-payments\public`"" -PassThru
Start-Sleep 2
Write-Host " OK (PID: $($p2.Id))" -ForegroundColor Green

# Start Admin Service (port 8002)
Write-Host "[3/4] Admin Service (port 8002)..." -NoNewline
$p3 = Start-Process -NoNewWindow -FilePath $PHP -ArgumentList "-S 0.0.0.0:8002 -t `"$ROOT\fripay-admin\public`"" -PassThru
Start-Sleep 2
Write-Host " OK (PID: $($p3.Id))" -ForegroundColor Green

# Start API Gateway (port 8080)
Write-Host "[4/4] API Gateway (port 8080)..." -NoNewline
$p4 = Start-Process -NoNewWindow -FilePath $PHP -ArgumentList "-S 0.0.0.0:8080 -t `"$ROOT\fripay-gateway\public`"" -PassThru
Start-Sleep 2
Write-Host " OK (PID: $($p4.Id))" -ForegroundColor Green

Write-Host "`n=== Tous les services sont demarres ! ===" -ForegroundColor Green
Write-Host "Users:    http://localhost:8000/api/v1/up"
Write-Host "Payments: http://localhost:8001/api/v1/up"
Write-Host "Admin:    http://localhost:8002/api/v1/up"
Write-Host "Gateway:  http://localhost:8080/api/v1/auth/register"
Write-Host "`nPIDs: users=$($p1.Id) payments=$($p2.Id) admin=$($p3.Id) gateway=$($p4.Id)"
