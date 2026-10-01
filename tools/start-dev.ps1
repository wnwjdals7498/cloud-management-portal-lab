$ErrorActionPreference='Stop'
& (Join-Path $PSScriptRoot 'prepare-dev.ps1')
if($LASTEXITCODE -ne 0){throw 'Local environment preparation failed'}
$startRoot=Split-Path $PSScriptRoot
$startPhp=Join-Path $startRoot '.runtime/tools/php/php.exe'
& $startPhp (Join-Path $PSScriptRoot 'configure-local.php')
if($LASTEXITCODE -ne 0){throw 'Local configuration failed'}
foreach($startRole in @('api','vmm-simulator')){& $startPhp (Join-Path $PSScriptRoot 'portal-cli.php') $startRole schema; if($LASTEXITCODE -ne 0){throw 'Local schema preparation failed'}}
& $startPhp (Join-Path $PSScriptRoot 'portal-cli.php') api seed
if($LASTEXITCODE -ne 0){throw 'Local account preparation failed'}
& (Join-Path $PSScriptRoot 'run-dev.ps1')
& (Join-Path $PSScriptRoot 'run-workers.ps1')
