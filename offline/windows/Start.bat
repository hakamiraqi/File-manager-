@echo off
chcp 65001 >nul
cd /d "%~dp0"
title FileNest - Offline server
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\start.ps1" %*
