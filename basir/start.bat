@echo off
REM Basir - run on port 7777 (Windows): built-in voice + voice server + app server
cd /d "%~dp0"
if not exist data mkdir data
where php >nul 2>nul || (echo PHP is not installed. Install PHP 8.1+ and add it to PATH. & pause & exit /b 1)

REM Enable the PHP extensions Basir needs (mbstring, curl, ffi...) when php.ini does not
set "PHPARGS="
for /f "delims=" %%a in ('php tools\php_args.php') do set "PHPARGS=%%a"

if "%BASIR_NO_VOICE%"=="1" goto app
php %PHPARGS% -r "exit(extension_loaded('ffi') ? 0 : 1);" || (echo Note: PHP FFI is not available, the device voice will be used. & goto app)
echo Preparing the built-in voice (downloaded once, then works offline)...
REM First what Basir needs to speak right away (~140 MB)
php %PHPARGS% tools\voices.php install --quick || echo Could not download the voices now - the device voice will be used.
php %PHPARGS% tools\voices.php stop >nul 2>nul
start "" /B php %PHPARGS% src\voice_daemon.php 2>> data\voice.log
REM Then the distinctive English voice in the background; used automatically when ready
start "" /B php %PHPARGS% tools\voices.php install --reload > data\voice-install.log 2>&1

:app
echo.
echo Basir is running: http://localhost:7777
echo Close this window to stop Basir.
echo.
php %PHPARGS% -d post_max_size=32M -d memory_limit=256M -d max_execution_time=120 -S 0.0.0.0:7777 -t public router.php
pause
