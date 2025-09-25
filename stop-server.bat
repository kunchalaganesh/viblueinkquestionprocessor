@echo off
echo Stopping Nginx and PHP FastCGI...
taskkill /IM php-cgi.exe /F >nul 2>&1
cd /d C:\nginx
nginx.exe -s quit
echo Done.
