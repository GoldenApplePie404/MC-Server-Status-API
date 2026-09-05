@echo off
setlocal enabledelayedexpansion
title MC Status API - Local Server
cd /d "%~dp0"

REM ============ Config ============
set "HOST=127.0.0.1"
set "PORT=8080"
set "OPEN_BROWSER=1"
REM =================================

where php >nul 2>nul
if errorlevel 1 (
    echo [ERROR] PHP not found. Install PHP 8.1+ and add it to PATH.
    pause
    exit /b 1
)
for /f "delims=" %%v in ('php -r "echo PHP_VERSION;"') do set "PHPV=%%v"
echo [INFO] Using PHP %PHPV%

if not exist "public\index.php" (
    echo [ERROR] public\index.php not found. Run this script from project root.
    pause
    exit /b 1
)

REM ---- Find a free port in 8080-8099 ----
REM Use probe-port.php (TCP connect test, reliable on any locale/encoding).
REM TIME_WAIT / remote 8081 lines do NOT count as "in use".
set "FREEPORT="
for /l %%p in (8080,1,8099) do (
    for /f "delims=" %%c in ('php scripts\probe-port.php %HOST% %%p') do set "USED=%%c"
    if "!USED!"=="0" (
        set "FREEPORT=%%p"
        goto port_ok
    )
)
if not defined FREEPORT (
    echo [ERROR] Ports 8080-8099 all in use. Free a port or edit the script.
    pause
    exit /b 1
)
:port_ok
set "PORT=%FREEPORT%"

echo.
echo   MC Status API - Local Server
echo   ============================================
echo   Home     http://%HOST%:%PORT%/
echo   Docs     http://%HOST%:%PORT%/docs
echo   Metrics  http://%HOST%:%PORT%/metrics
echo   Health   http://%HOST%:%PORT%/health
echo   API      http://%HOST%:%PORT%/api/ping?host=mc.hypixel.net
echo   Press Ctrl+C to stop.
echo   ============================================
echo.

if "%OPEN_BROWSER%"=="1" (
    start "" "http://%HOST%:%PORT%/"
)

REM Router mode: index.php as router script (required by README)
php -S %HOST%:%PORT% public/index.php

pause
