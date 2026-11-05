@echo off
chcp 65001 >nul
:: Lets phones and other computers on the network reach this server (run once, as administrator).
net session >nul 2>&1 || (powershell -NoProfile -Command "Start-Process -Verb RunAs -FilePath '%~f0'" & exit /b)
cd /d "%~dp0"
set PORT=80
for /f "tokens=2 delims==" %%a in ('findstr /b "HTTP_PORT=" config.txt') do set PORT=%%a
netsh advfirewall firewall delete rule name="FileNest" >nul 2>&1
netsh advfirewall firewall add rule name="FileNest" dir=in action=allow protocol=TCP localport=%PORT%
echo.
echo Done: port %PORT% is open for the local network.
pause
