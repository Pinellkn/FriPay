@echo off
echo Restauration de la base de donnees FriPay...
echo.

set PGPASSWORD=
"C:\laragon\bin\postgresql\postgresql\bin\psql.exe" -U postgres -c "CREATE DATABASE fripay;" 2>nul
echo Base de donnees creee (ou deja existante)

"C:\laragon\bin\postgresql\postgresql\bin\psql.exe" -U postgres -d fripay < fripay_database_dump.sql
echo.
echo Restauration terminee !
pause
