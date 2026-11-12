# FileNest offline: office editor add-on (ONLYOFFICE Docs Community).
#
# Adds Word / Excel / PowerPoint / PDF editing to the offline server. Unzip this
# folder INSIDE the FileNest folder (so it sits next to FileNest.exe, as
# office-addon\) and run install-office.cmd as administrator. It installs, with no
# internet needed:
#   1. onlyoffice-documentserver-prerequisites.exe  (Erlang, RabbitMQ, PostgreSQL)
#   2. onlyoffice-documentserver.exe                (the editor, as Windows services, port 8088)
# then sets the shared signing key in the editor and in FileNest's app\.env.
# Needs 64-bit Windows 10/11 or Server 2016+, 4 GB RAM or more and ~10 GB of disk.

param([int]$Port = 8088)

$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$root = Split-Path -Parent $here
$envFile = Join-Path $root 'app\.env'

function Say($t) { Write-Host "`n== $t" -ForegroundColor Cyan }

# Waits for the installer itself only: Start-Process -Wait also waits for everything it
# started, and the prerequisites leave Erlang's epmd running, so it would never return.
function Install([string]$exe, [string]$arguments, [int]$minutes = 40) {
    $p = [Diagnostics.Process]::Start((Join-Path $here $exe), $arguments)
    if (-not $p.WaitForExit($minutes * 60000)) { throw "$exe did not finish in $minutes minutes." }
    if ($p.ExitCode -ne 0) { throw "$exe failed (code $($p.ExitCode))." }
}

$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { throw 'Run install-office.cmd as administrator (right click > Run as administrator).' }
if (-not (Test-Path $envFile)) { throw "FileNest was not found next to this folder ($envFile). Put office-addon inside the FileNest folder and start FileNest once first." }

Say 'Prerequisites (Erlang, RabbitMQ, PostgreSQL)'
Install 'onlyoffice-documentserver-prerequisites.exe' '/VERYSILENT /SUPPRESSMSGBOXES /NORESTART /SP-'

Say "ONLYOFFICE Docs (port $Port)"
Install 'onlyoffice-documentserver.exe' "/VERYSILENT /SUPPRESSMSGBOXES /NORESTART /SP- /DS_PORT=$Port"

$ds = Join-Path ${env:ProgramFiles} 'ONLYOFFICE\DocumentServer'
$local = Join-Path $ds 'config\local.json'
if (-not (Test-Path $local)) { throw "ONLYOFFICE was not installed in $ds." }

Say 'Signing key and local network access'
function Set-Path($obj, [string[]]$path, $value) {
    $o = $obj
    for ($i = 0; $i -lt $path.Count - 1; $i++) {
        $k = $path[$i]
        if (-not ($o.PSObject.Properties.Name -contains $k) -or $null -eq $o.$k) {
            $o | Add-Member -NotePropertyName $k -NotePropertyValue ([pscustomobject]@{}) -Force
        }
        $o = $o.$k
    }
    $o | Add-Member -NotePropertyName $path[-1] -NotePropertyValue $value -Force
}
$cfg = Get-Content $local -Raw -Encoding UTF8 | ConvertFrom-Json
$secret = $null
try { $secret = $cfg.services.CoAuthoring.secret.inbox.string } catch { }
if ([string]::IsNullOrWhiteSpace($secret)) {
    $bytes = New-Object byte[] 30
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $secret = ([Convert]::ToBase64String($bytes) -replace '[^A-Za-z0-9]', '')
}
foreach ($k in 'inbox', 'outbox', 'session') { Set-Path $cfg @('services', 'CoAuthoring', 'secret', $k, 'string') $secret }
Set-Path $cfg @('services', 'CoAuthoring', 'token', 'enable', 'browser') $true
Set-Path $cfg @('services', 'CoAuthoring', 'token', 'enable', 'request', 'inbox') $true
Set-Path $cfg @('services', 'CoAuthoring', 'token', 'enable', 'request', 'outbox') $true
# FileNest is on this same computer (127.0.0.1): the editor must be allowed to fetch from it.
Set-Path $cfg @('services', 'CoAuthoring', 'request-filtering-agent', 'allowPrivateIPAddress') $true
Set-Path $cfg @('services', 'CoAuthoring', 'request-filtering-agent', 'allowMetaIPAddress') $false
[IO.File]::WriteAllText($local, ($cfg | ConvertTo-Json -Depth 20), (New-Object Text.UTF8Encoding $false))

foreach ($s in 'ds-docservice', 'ds-converter') {
    if (Get-Service $s -ErrorAction SilentlyContinue) { Restart-Service $s -Force }
}

Say 'FileNest settings (app\.env)'
$httpPort = 80
$conf = Join-Path $root 'config.txt'
if (Test-Path $conf) {
    $m = Select-String -Path $conf -Pattern '^HTTP_PORT=(\d+)' | Select-Object -First 1
    if ($m) { $httpPort = [int]$m.Matches[0].Groups[1].Value }
}
$want = [ordered]@{
    ONLYOFFICE_JWT_SECRET       = $secret
    ONLYOFFICE_PUBLIC_URL       = '/office'
    ONLYOFFICE_INTERNAL_URL     = "http://127.0.0.1:$Port"
    ONLYOFFICE_APP_INTERNAL_URL = "http://127.0.0.1:$httpPort"
}
$lines = @(Get-Content $envFile -Encoding UTF8 | Where-Object { $_ -notmatch '^ONLYOFFICE_[A-Z_]+=' })
$lines += ''
$lines += '# Office editor add-on (office-addon\install-office.ps1)'
foreach ($k in $want.Keys) { $lines += "$k=$($want[$k])" }
[IO.File]::WriteAllLines($envFile, $lines, (New-Object Text.UTF8Encoding $false))
$php = Join-Path $root 'php\php.exe'
if (Test-Path $php) {
    Push-Location (Join-Path $root 'app')
    & $php -c (Join-Path $root 'php\php.ini') artisan config:clear 2>&1 | Out-Null
    Pop-Location
}

Say 'Check'
$ok = $false
for ($i = 0; $i -lt 40 -and -not $ok; $i++) {
    Start-Sleep -Seconds 3
    try { $ok = (Invoke-WebRequest -Uri "http://127.0.0.1:$Port/healthcheck" -UseBasicParsing -TimeoutSec 5).Content -match 'true' } catch { }
}
if ($ok) {
    Write-Host "`nThe office editor is ready. Restart FileNest from its panel (Stop, then Start)," -ForegroundColor Green
    Write-Host 'then open any Word, Excel, PowerPoint or PDF file and choose "Open in editor".' -ForegroundColor Green
} else {
    Write-Warning "ONLYOFFICE did not answer on port $Port yet. Give it a minute, check the ds-docservice service in services.msc, then restart FileNest."
}
