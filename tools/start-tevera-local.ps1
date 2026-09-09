$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$appRoot = Join-Path $projectRoot 'platform\public'
$phpExecutable = Join-Path $projectRoot '.tools\php83\php.exe'
$appUrl = 'http://127.0.0.1:8000/login'
function Test-Tevera {
    try { return (Invoke-WebRequest -Uri $appUrl -UseBasicParsing -TimeoutSec 3).StatusCode -eq 200 } catch { return $false }
}
if (-not (Test-Tevera)) {
    if (-not (Test-Path -LiteralPath $phpExecutable)) { throw 'The local PHP runtime is missing.' }
    Start-Process -FilePath $phpExecutable -ArgumentList '-S 127.0.0.1:8000 -t . ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php' -WorkingDirectory $appRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $projectRoot '.tools\tevera-web.stdout.log') -RedirectStandardError (Join-Path $projectRoot '.tools\tevera-web.stderr.log') | Out-Null
    for ($attempt = 0; $attempt -lt 10; $attempt++) {
        if (Test-Tevera) { break }
        Start-Sleep -Milliseconds 500
    }
    if (-not (Test-Tevera)) { throw 'TEVERA did not start. Check .tools\tevera-web.stderr.log.' }
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
