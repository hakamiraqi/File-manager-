# Starts the FileNest offline server: MariaDB, PHP workers, Caddy, the queue worker and the
# scheduler, then keeps them running (a crashed one is restarted) until this window is closed
# or Stop.bat is run. Everything lives in this folder; nothing is installed on Windows.
param([switch]$Headless)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root
$app = Join-Path $root 'app'
$php = Join-Path $root 'php\php.exe'
$phpCgi = Join-Path $root 'php\php-cgi.exe'
$phpIni = Join-Path $root 'php\php.ini'
$logs = Join-Path $app 'storage\logs'
New-Item -ItemType Directory -Force $logs | Out-Null
$startLog = Join-Path $logs 'start.log'

function Say($msg) {
    $line = "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] $msg"
    Write-Host $msg
    Add-Content -Path $startLog -Value $line -Encoding UTF8
}

# --- Settings (config.txt) ---
$cfg = @{ HTTP_PORT = '80'; PHP_WORKERS = '8'; SERVER_IP = ''; OPEN_BROWSER = '1' }
if (Test-Path "$root\config.txt") {
    foreach ($l in Get-Content "$root\config.txt") {
        if ($l -match '^\s*([A-Z_]+)\s*=\s*(.*?)\s*$') { $cfg[$Matches[1]] = $Matches[2] }
    }
}
$port = [int]$cfg.HTTP_PORT
$workers = [Math]::Max(1, [int]$cfg.PHP_WORKERS)
$dbPort = 3308

function Get-LanIp {
    if ($cfg.SERVER_IP) { return $cfg.SERVER_IP }
    $ip = Get-NetIPConfiguration -ErrorAction SilentlyContinue |
        Where-Object { $_.IPv4DefaultGateway -and $_.NetAdapter.Status -eq 'Up' } |
        ForEach-Object { $_.IPv4Address.IPAddress } | Select-Object -First 1
    if (-not $ip) {
        $ip = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
            Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } |
            Select-Object -ExpandProperty IPAddress -First 1
    }
    if (-not $ip) { $ip = '127.0.0.1' }
    return $ip
}
$ip = Get-LanIp
Set-Content -Path "$root\lan-ip.txt" -Value $ip -Encoding ASCII

# Anything left from an earlier run that was not stopped cleanly.
& "$PSScriptRoot\stop.ps1" -Quiet
Remove-Item "$root\data\stop.flag" -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force "$root\data" | Out-Null

# --- PHP ---
if (-not (Test-Path $phpIni)) {
    $tpl = Get-Content "$root\php\php.ini.template" -Raw
    $tpl = $tpl.Replace('__EXT_DIR__', (Join-Path $root 'php\ext')).Replace('__CA_FILE__', (Join-Path $root 'php\cacert.pem'))
    Set-Content -Path $phpIni -Value $tpl -Encoding ASCII
}
if (-not (Test-Path "$env:WINDIR\System32\vcruntime140.dll") -and (Test-Path "$root\vc_redist.x64.exe")) {
    Say 'Installing the Visual C++ runtime (one time)...'
    Start-Process "$root\vc_redist.x64.exe" -ArgumentList '/install', '/quiet', '/norestart' -Wait
}

function Hidden($file, $argList, $out) {
    $p = @{ FilePath = $file; ArgumentList = $argList; WindowStyle = 'Hidden'; PassThru = $true; WorkingDirectory = $app }
    if ($out) { $p.RedirectStandardOutput = "$out.out"; $p.RedirectStandardError = "$out.err" }
    return Start-Process @p
}

# --- MariaDB ---
$dataDir = Join-Path $root 'data\mysql'
$mariadbd = Join-Path $root 'mariadb\bin\mariadbd.exe'
$mariadbCli = Join-Path $root 'mariadb\bin\mariadb.exe'
if (-not (Test-Path (Join-Path $dataDir 'mysql'))) {
    Say 'First start: creating the database (one time)...'
    New-Item -ItemType Directory -Force $dataDir | Out-Null
    & (Join-Path $root 'mariadb\bin\mariadb-install-db.exe') "--datadir=$dataDir" "--port=$dbPort" | Out-Null
}
function Start-Db {
    Hidden $mariadbd @("--datadir=`"$dataDir`"", "--port=$dbPort", '--bind-address=127.0.0.1', '--character-set-server=utf8mb4',
        '--collation-server=utf8mb4_unicode_ci', '--innodb-buffer-pool-size=256M', '--max-connections=200') (Join-Path $root 'data\mariadb')
}
$db = Start-Db
$ready = $false
for ($i = 0; $i -lt 60 -and -not $ready; $i++) {
    Start-Sleep -Seconds 1
    & $mariadbCli --user=root --host=127.0.0.1 "--port=$dbPort" -e 'select 1' 2>$null | Out-Null
    $ready = ($LASTEXITCODE -eq 0)
}
if (-not $ready) { Say 'The database did not start. See data\mariadb.err'; Get-Content "$root\data\mariadb.err" -Tail 20 -ErrorAction SilentlyContinue; exit 1 }
& $mariadbCli --user=root --host=127.0.0.1 "--port=$dbPort" -e 'CREATE DATABASE IF NOT EXISTS filenest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'

# --- The application ---
function Artisan { & $php -c $phpIni (Join-Path $app 'artisan') @args; if ($LASTEXITCODE -ne 0) { throw "artisan $($args -join ' ') failed" } }
$envFile = Join-Path $app '.env'
if (-not (Test-Path $envFile)) {
    Copy-Item (Join-Path $app '.env.offline') $envFile
    Push-Location $app; Artisan key:generate --force --no-interaction | Out-Null; Pop-Location
}
# The address other devices use, for links in e-mails and share pages.
$appUrl = if ($port -eq 80) { "http://$ip" } else { "http://${ip}:$port" }
(Get-Content $envFile) -replace '^APP_URL=.*', "APP_URL=$appUrl" | Set-Content $envFile -Encoding UTF8

Push-Location $app
Say 'Preparing the database...'
Artisan migrate --force --no-interaction | Out-Null
if (-not (Test-Path (Join-Path $app 'storage\app\.seeded'))) {
    Artisan db:seed --force --no-interaction | Out-Null
    New-Item -ItemType File (Join-Path $app 'storage\app\.seeded') | Out-Null
}
foreach ($d in 'storage\app\filemanager', 'storage\app\public', 'storage\framework\cache', 'storage\framework\sessions', 'storage\framework\views') {
    New-Item -ItemType Directory -Force (Join-Path $app $d) | Out-Null
}
Artisan config:cache | Out-Null
Artisan route:cache | Out-Null
Artisan view:cache | Out-Null
Pop-Location

# --- PHP workers, queue, scheduler ---
$env:PHP_FCGI_MAX_REQUESTS = '0'
$env:PHP_FCGI_CHILDREN = '0'
$workerPorts = 1..$workers | ForEach-Object { 9200 + $_ }
$procs = @{}
function Start-Worker($wp) { Hidden $phpCgi @('-b', "127.0.0.1:$wp", '-c', "`"$phpIni`"") $null }
function Start-Queue { Hidden $php @('-c', "`"$phpIni`"", 'artisan', 'queue:work', '--tries=3', '--timeout=1800', '--sleep=3') (Join-Path $logs 'queue') }
function Start-Scheduler { Hidden $php @('-c', "`"$phpIni`"", 'artisan', 'schedule:work') (Join-Path $logs 'scheduler') }
foreach ($wp in $workerPorts) { $procs["php$wp"] = Start-Worker $wp }
$procs['queue'] = Start-Queue
$procs['scheduler'] = Start-Scheduler

# --- Caddy (web server) ---
$public = (Join-Path $app 'public').Replace('\', '/')
$appsDir = (Join-Path $root 'apps').Replace('\', '/')
$upstreams = ($workerPorts | ForEach-Object { "127.0.0.1:$_" }) -join ' '
$caddyfile = @"
{
	admin off
	auto_https off
	servers {
		protocols h1 h2c
	}
}

:$port {
	# The Windows, Android and iPhone apps for this network.
	handle_path /apps/* {
		root * "$appsDir"
		@apk path *.apk
		header @apk Content-Type application/vnd.android.package-archive
		@bin path *.apk *.exe *.ipa *.zip
		header @bin Content-Disposition attachment
		file_server browse
	}
	handle {
		root * "$public"
		encode gzip
		php_fastcgi $upstreams {
			lb_policy least_conn
			lb_try_duration 10s
			read_timeout 3600s
			write_timeout 3600s
		}
		file_server
	}
}
"@
Set-Content -Path "$root\Caddyfile" -Value $caddyfile -Encoding ASCII
$caddy = Join-Path $root 'caddy\caddy.exe'
function Start-Caddy { Hidden $caddy @('run', '--config', "`"$root\Caddyfile`"", '--adapter', 'caddyfile') (Join-Path $logs 'caddy') }
$procs['caddy'] = Start-Caddy
$procs['db'] = $db

$local = if ($port -eq 80) { 'http://localhost' } else { "http://localhost:$port" }
$ok = $false
for ($i = 0; $i -lt 60 -and -not $ok; $i++) {
    Start-Sleep -Seconds 1
    try { $ok = (Invoke-WebRequest -Uri "$local/up" -UseBasicParsing -TimeoutSec 5).StatusCode -eq 200 } catch { }
}
if (-not $ok) { Say "The web server did not answer on port $port. If another program uses it, change HTTP_PORT in config.txt."; Get-Content (Join-Path $logs 'caddy.err') -Tail 15 -ErrorAction SilentlyContinue }

Write-Host ''
Write-Host '==============================================================' -ForegroundColor Cyan
Write-Host '  FileNest is running (offline server)' -ForegroundColor Green
Write-Host "  On this computer:     $local"
Write-Host "  Other devices (Wi-Fi): $appUrl"
Write-Host "  Apps for phones/PCs:   $appUrl/apps/"
Write-Host '  In the app: Server settings > Local network address = the address above.'
Write-Host '  First login: admin@filenest.test / password (change it right away).'
Write-Host '  Keep this window open. To stop: close it or run Stop.bat.'
Write-Host '==============================================================' -ForegroundColor Cyan
Say "Started on $appUrl"

if (-not $Headless -and $cfg.OPEN_BROWSER -eq '1') { Start-Process $local }

# --- Watchdog: restart anything that stopped ---
while ($true) {
    Start-Sleep -Seconds 5
    if (Test-Path "$root\data\stop.flag") { break }
    foreach ($key in @($procs.Keys)) {
        if (-not $procs[$key].HasExited) { continue }
        Say "Restarting $key"
        $procs[$key] = switch -Wildcard ($key) {
            'php*' { Start-Worker ([int]$key.Substring(3)) }
            'queue' { Start-Queue }
            'scheduler' { Start-Scheduler }
            'caddy' { Start-Caddy }
            'db' { Start-Db }
        }
    }
}
