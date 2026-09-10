param([switch]$OpenBrowser)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$appRoot = Join-Path $projectRoot 'platform\public'
$phpExecutable = Join-Path $projectRoot '.tools\php83\php.exe'
$port = 8000
$appUrl = "http://127.0.0.1:$port/login"
# Resolve PHP extensions from this checkout, including after a folder rename.
if (-not (Test-Path -LiteralPath $phpExecutable)) { throw 'The local PHP runtime is missing.' }
$phpIni = Join-Path (Split-Path $phpExecutable -Parent) 'php.ini'
$extensionDirectory = (Join-Path (Split-Path $phpExecutable -Parent) 'ext').Replace('\', '/')
$iniText = [System.IO.File]::ReadAllText($phpIni)
$extensionSetting = 'extension_dir="' + $extensionDirectory + '"'
if ($iniText -match '(?m)^extension_dir\s*=.*$') {
    $updatedIni = [regex]::Replace($iniText, '(?m)^extension_dir\s*=.*$', [System.Text.RegularExpressions.MatchEvaluator]{ param($match) $extensionSetting })
} else { $updatedIni = $extensionSetting + "`r`n" + $iniText }
if ($updatedIni -ne $iniText) { [System.IO.File]::WriteAllText($phpIni, $updatedIni, (New-Object System.Text.UTF8Encoding($false))) }
$extensionCheck = & $phpExecutable -m
if ($LASTEXITCODE -ne 0 -or $extensionCheck -notcontains 'mbstring' -or $extensionCheck -notcontains 'pdo_mysql') { throw 'PHP extensions could not load. Check .tools/php83/php.ini and the ext folder.' }

function Test-Tevera {
    try { return (Invoke-WebRequest -Uri $appUrl -UseBasicParsing -TimeoutSec 3).StatusCode -eq 200 } catch { return $false }
}
if (-not (Test-Tevera)) {
    # An old elevated PHP process may survive a failed launch and cannot be stopped here.
    # Select an available local port instead of launching duplicate servers onto it.
    while (Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue) {
        $port++
        if ($port -gt 8009) { throw 'Ports 8000-8009 are occupied. Close unused local servers and retry.' }
        $appUrl = "http://127.0.0.1:$port/login"
        if (Test-Tevera) { break }
    }
    if ($port -ne 8000) { Write-Host "Port 8000 is occupied; using $appUrl" }
    if (-not (Test-Tevera)) {
    if (-not (Test-Path -LiteralPath $phpExecutable)) { throw 'The local PHP runtime is missing.' }
    Start-Process -FilePath $phpExecutable -ArgumentList "-S 127.0.0.1:$port -t . ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php" -WorkingDirectory $appRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $projectRoot '.tools\tevera-web.stdout.log') -RedirectStandardError (Join-Path $projectRoot '.tools\tevera-web.stderr.log') | Out-Null
    }
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        if (Test-Tevera) { break }
        Start-Sleep -Milliseconds 500
    }
    if (-not (Test-Tevera)) { throw 'TEVERA did not start. Check .tools\tevera-web.stderr.log and platform\storage\logs\laravel.log. Ensure XAMPP MySQL is running.' }
}
Write-Host "TEVERA is running: $appUrl"

# Local background services. PID files prevent duplicate workers on repeated launches.
foreach ($serviceName in @('scheduler', 'worker')) {
    $pidFile = Join-Path $projectRoot ".tools\tevera-$serviceName.pid"
    $existingService = $null
    if (Test-Path -LiteralPath $pidFile) {
        $savedId = 0
        if ([int]::TryParse((Get-Content -LiteralPath $pidFile -Raw).Trim(), [ref]$savedId)) {
            $existingService = Get-Process -Id $savedId -ErrorAction SilentlyContinue
        }
    }
    if (-not $existingService -or $existingService.ProcessName -ne 'php') {
        $serviceArgs = if ($serviceName -eq 'scheduler') { 'artisan schedule:work' } else { 'artisan queue:work --sleep=3 --tries=3 --timeout=60' }
        $serviceProcess = Start-Process -FilePath $phpExecutable -ArgumentList $serviceArgs -WorkingDirectory (Join-Path $projectRoot 'platform') -WindowStyle Hidden -RedirectStandardOutput (Join-Path $projectRoot ".tools\tevera-$serviceName.stdout.log") -RedirectStandardError (Join-Path $projectRoot ".tools\tevera-$serviceName.stderr.log") -PassThru
        $serviceProcess.Id | Set-Content -LiteralPath $pidFile
    }
}
Write-Host 'Local scheduler and queue worker are running. Keep XAMPP MySQL running.'

if ($OpenBrowser) { Start-Process $appUrl }
