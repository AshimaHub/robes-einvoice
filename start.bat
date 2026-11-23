@echo off
setlocal EnableExtensions
cd /d "%~dp0"
set "PHP="
where php >nul 2>&1 && set "PHP=php"
if not defined PHP if exist "C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe" set "PHP=C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe"
if not defined PHP if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
if not defined PHP (
  for /f "delims=" %%P in ('dir /b /ad "C:\laragon\bin\php" 2^>nul') do (
    if exist "C:\laragon\bin\php\%%P\php.exe" set "PHP=C:\laragon\bin\php\%%P\php.exe"
  )
)
if not defined PHP (
  echo.
  echo PHP 8.1 or newer was not found.
  echo Install Laragon or PHP, then run this file again.
  echo.
  pause
  exit /b 1
)
for %%I in ("%PHP%") do set "PHPDIR=%%~dpI"
if exist "%PHPDIR%extras\ssl\openssl.cnf" set "OPENSSL_CONF=%PHPDIR%extras\ssl\openssl.cnf"
set "EXTRA="
"%PHP%" -r "exit(in_array('sqlite', PDO::getAvailableDrivers(), true) ? 0 : 1);"
if errorlevel 1 set "EXTRA=-d extension=pdo_sqlite"
echo.
echo Robes eInvoice
echo Open http://127.0.0.1:8787
echo Keep this window open while you use the app. Close it to stop.
echo.
start "" "http://127.0.0.1:8787"
"%PHP%" %EXTRA% -S 127.0.0.1:8787 -t public public\router.php
echo.
echo The app stopped.
pause
