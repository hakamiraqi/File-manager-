@echo off
rem FileNest offline: installs the office editor (Word, Excel, PowerPoint, PDF). Run as administrator.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-office.ps1" %*
pause
