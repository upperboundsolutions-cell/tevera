$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$compiler = Get-ChildItem -LiteralPath (Join-Path $projectRoot '.tools/jdk21') -Filter javac.exe -Recurse | Where-Object { $_.Directory.Name -eq 'bin' } | Select-Object -First 1
if (-not $compiler) { throw 'Workspace Java 21 JDK is missing.' }
$env:JAVA_HOME = $compiler.Directory.Parent.FullName
$env:GRADLE_USER_HOME = Join-Path $projectRoot '.tools/gradle-cache'
Set-Location -LiteralPath $projectRoot
$gradle = Join-Path $projectRoot '.tools/gradle/gradle-9.5.1/bin/gradle.bat'
if (-not (Test-Path -LiteralPath $gradle)) { $gradle = Join-Path $projectRoot 'gradlew.bat' }
& $gradle assemble --no-daemon --console=plain
exit $LASTEXITCODE
