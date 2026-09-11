@echo off
echo ============================================================
echo   FriPay API Gateway - Redirection vers les microservices
echo ============================================================
echo.
echo Services attendus :
echo   Users Service   : http://localhost:8000
echo   Payments Service: http://localhost:8001
echo   Admin Service   : http://localhost:8002
echo.
echo Gateway demarree sur : http://localhost:8080
echo.
echo Lancement...
C:\Users\Pinel\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe -S localhost:8080 -t public
