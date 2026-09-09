$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$javaExecutable = Get-ChildItem -LiteralPath (Join-Path $projectRoot '.tools/jdk21') -Filter java.exe -Recurse | Where-Object { $_.Directory.Name -eq 'bin' } | Select-Object -First 1 -ExpandProperty FullName
if (-not $javaExecutable) { throw 'Workspace Java 21 runtime is missing.' }
$serverJar = Join-Path $projectRoot 'target/tracker-server.jar'
if (-not (Test-Path -LiteralPath $serverJar)) { throw 'Build the server with gradlew.bat assemble first.' }
Set-Location -LiteralPath $projectRoot
& $javaExecutable -jar $serverJar .tools/traccar-local.xml
exit $LASTEXITCODE
