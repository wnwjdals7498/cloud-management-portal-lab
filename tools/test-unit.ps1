$ErrorActionPreference='Stop'
$unitRoot=Split-Path $PSScriptRoot
$unitPhp=Join-Path $unitRoot '.runtime/tools/php/php.exe'
foreach($unitRole in @('api','vmm-simulator')){
    & $unitPhp (Join-Path $PSScriptRoot 'portal-cli.php') $unitRole schema --test
    if($LASTEXITCODE -ne 0){throw "Isolated test schema preparation failed: $unitRole"}
}
foreach($unitApp in @('api','vmm-simulator','customer-console','admin-console')){
    Push-Location (Join-Path $unitRoot ('apps/'+$unitApp))
    try { & $unitPhp vendor/phpunit/phpunit/phpunit --configuration phpunit.dist.xml --no-coverage; if($LASTEXITCODE -ne 0){throw "Test run failed: $unitApp"} }
    finally { Pop-Location }
}
