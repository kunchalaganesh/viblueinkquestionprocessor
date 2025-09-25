@echo off
setlocal enabledelayedexpansion

REM --- Adjust if your install paths differ ---
set "PANDOC_DIR=C:\Program Files\Pandoc"
set "IMAGEMAGICK_DIR=C:\Program Files\ImageMagick-7.1.1-Q16-HDRI"

set "PATH=%PANDOC_DIR%;%IMAGEMAGICK_DIR%;%PATH%"

REM --- Add firewall rule for inbound TCP 3001 (ignore error if exists) ---
netsh advfirewall firewall add rule name="MCQ PHP 3001" dir=in action=allow protocol=TCP localport=3001 >nul 2>nul

REM --- Find a likely LAN IPv4 (skips APIPA 169.*) ---
for /f "tokens=2 delims=:" %%A in ('ipconfig ^| findstr /r "IPv4"') do (
  set ip=%%A
  set ip=!ip: =!
  echo !ip! | findstr /r "^169\." >nul && (set ip=) || (goto :gotip)
)
:gotip
if not defined ip (
  echo Could not detect LAN IPv4. Use this PC's IP manually from ipconfig.
) else (
  echo.
  echo Local server will be reachable on:  http://!ip!:3001
  echo Health check:                       http://!ip!:3001/api.php?health=1
  echo.
)

REM --- Serve the public folder on all interfaces (LAN) port 3001 ---
cd /d C:\mcq-extractor\public
php -S 0.0.0.0:3001

endlocal
