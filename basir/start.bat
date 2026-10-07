@echo off
REM Basir - run on port 7777 (Windows)
cd /d "%~dp0"
if not exist data mkdir data
where php >nul 2>nul || (echo PHP is not installed. Install PHP 8.1+ and add it to PATH. & pause & exit /b 1)
echo.
echo Basir is running: http://localhost:7777
echo Close this window to stop the server.
echo.
php -d post_max_size=32M -d memory_limit=256M -d max_execution_time=120 -S 0.0.0.0:7777 -t public router.php
pause
