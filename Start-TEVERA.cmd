@echo off
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\start-tevera-local.ps1" -OpenBrowser
if errorlevel 1 (
    pause
    exit /b 1
)
