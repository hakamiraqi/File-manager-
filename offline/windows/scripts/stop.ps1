# Stops everything the FileNest offline server started from this folder: the watchdog in the
# Start window, the web server, PHP, and the database (shut down cleanly so no data is lost).
param([switch]$Quiet)

$root = Split-Path -Parent $PSScriptRoot
$dbPort = 3308
if (-not $Quiet) {
    New-Item -ItemType Directory -Force "$root\data" | Out-Null
    Set-Content "$root\data\stop.flag" 'stop'
    # Lets the watchdog see the flag before its processes go away.
    Start-Sleep -Seconds 6
}

function Get-Ours($names) {
    Get-Process -Name $names -ErrorAction SilentlyContinue | Where-Object {
        try { $_.Path -and $_.Path.StartsWith($root, [StringComparison]::OrdinalIgnoreCase) } catch { $false }
    }
}

Get-Ours @('caddy', 'php-cgi', 'php') | Stop-Process -Force -ErrorAction SilentlyContinue

if (Get-Ours @('mariadbd')) {
    & "$root\mariadb\bin\mariadb-admin.exe" --user=root --host=127.0.0.1 "--port=$dbPort" shutdown 2>$null | Out-Null
    for ($i = 0; $i -lt 30 -and (Get-Ours @('mariadbd')); $i++) { Start-Sleep -Seconds 1 }
    Get-Ours @('mariadbd') | Stop-Process -Force -ErrorAction SilentlyContinue
}
if (-not $Quiet) { Write-Host 'FileNest stopped.' }
