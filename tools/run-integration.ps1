param([switch]$Stop)
$ErrorActionPreference='Stop'
$integrationRoot=Split-Path $PSScriptRoot
$integrationRuntime=Join-Path $integrationRoot '.runtime'
$integrationPid=Join-Path $integrationRuntime 'integration-processes.json'
if($Stop){
    $integrationNginx=Get-ChildItem -LiteralPath (Join-Path $integrationRuntime 'tools/nginx') -Recurse -Filter nginx.exe|Select-Object -First 1
    & $integrationNginx.FullName -p ((Join-Path $integrationRuntime 'nginx-integration')+'/') -c conf/nginx.conf -s quit 2>$null
    if(Test-Path -LiteralPath $integrationPid){foreach($integrationRecord in (Get-Content -Raw -LiteralPath $integrationPid|ConvertFrom-Json)){ $integrationProcess=Get-Process -Id $integrationRecord.pid -ErrorAction SilentlyContinue; if($integrationProcess -and [IO.Path]::GetFullPath($integrationProcess.Path) -eq [IO.Path]::GetFullPath($integrationRecord.exe)){Stop-Process -Id $integrationProcess.Id} }}
    'Integration web processes stopped';exit
}
$integrationConfig=Get-Content -Raw -LiteralPath (Join-Path $integrationRuntime 'local.json')|ConvertFrom-Json -AsHashtable
foreach($integrationRole in @('api','sim')){ $integrationConfig[$integrationRole+'_db']=$integrationConfig[($integrationRole -eq 'api' ? 'test' : 'sim_test')+'_db'];$integrationConfig[$integrationRole+'_user']=$integrationConfig[($integrationRole -eq 'api' ? 'test' : 'sim_test')+'_user'];$integrationConfig[$integrationRole+'_password']=$integrationConfig[($integrationRole -eq 'api' ? 'test' : 'sim_test')+'_password'] }
$integrationConfig.api_url='http://127.0.0.1:18101';$integrationConfig.simulator_url='http://127.0.0.1:18201';$integrationConfig.customer_origin='http://customer.localhost:18081';$integrationConfig.admin_origin='http://admin.localhost:18081'
$integrationConfig.simulation.delay_min_seconds=0;$integrationConfig.simulation.delay_max_seconds=0;$integrationConfig.simulation.late_min_seconds=2;$integrationConfig.simulation.late_max_seconds=2;$integrationConfig.simulation.duplicate_delay_seconds=0;$integrationConfig.job_timeout_seconds=1
$integrationCfgPath=Join-Path $integrationRuntime 'integration.json'
[IO.File]::WriteAllText($integrationCfgPath,($integrationConfig|ConvertTo-Json -Depth 12),[Text.UTF8Encoding]::new($false))
$integrationOldConfig=$env:PORTAL_CONFIG
$env:PORTAL_CONFIG=$integrationCfgPath
$integrationPhp=Join-Path $integrationRuntime 'tools/php/php.exe'
$integrationRecords=[Collections.Generic.List[object]]::new()
try{
foreach($integrationRole in @('api','vmm-simulator')){
    & $integrationPhp (Join-Path $PSScriptRoot 'portal-cli.php') $integrationRole schema --test
    if($LASTEXITCODE -ne 0){throw "Integration test schema preparation failed: $integrationRole"}
}
& $integrationPhp (Join-Path $PSScriptRoot 'portal-cli.php') api seed --test
if($LASTEXITCODE -ne 0){throw 'Integration test account preparation failed'}
foreach($integrationApp in @(@{name='api';port=18101},@{name='vmm-simulator';port=18201})){
    $integrationProcess=Start-Process -WindowStyle Hidden -PassThru -WorkingDirectory (Join-Path $integrationRoot ('apps/'+$integrationApp.name)) -FilePath $integrationPhp -ArgumentList @('-S',('127.0.0.1:'+$integrationApp.port),'-t','public','vendor/codeigniter4/framework/system/rewrite.php') -RedirectStandardOutput (Join-Path $integrationRuntime ('logs/integration-'+$integrationApp.name+'.stdout.log')) -RedirectStandardError (Join-Path $integrationRuntime ('logs/integration-'+$integrationApp.name+'.stderr.log'))
    $integrationRecords.Add(@{pid=$integrationProcess.Id;exe=$integrationPhp})
}
}finally{$env:PORTAL_CONFIG=$integrationOldConfig}
$integrationNginxRoot=Join-Path $integrationRuntime 'nginx-integration'
New-Item -ItemType Directory -Force -Path (Join-Path $integrationNginxRoot 'conf'),(Join-Path $integrationNginxRoot 'logs'),(Join-Path $integrationNginxRoot 'temp')|Out-Null
$integrationNginxConf=(Get-Content -Raw -LiteralPath (Join-Path $integrationRuntime 'nginx/conf/nginx.conf')).Replace(':18080',':18081').Replace(':18100',':18101')
[IO.File]::WriteAllText((Join-Path $integrationNginxRoot 'conf/nginx.conf'),$integrationNginxConf,[Text.UTF8Encoding]::new($false))
$integrationNginx=(Get-ChildItem -LiteralPath (Join-Path $integrationRuntime 'tools/nginx') -Recurse -Filter nginx.exe|Select-Object -First 1).FullName
& $integrationNginx -p ($integrationNginxRoot+'/') -c conf/nginx.conf -t
if($LASTEXITCODE -ne 0){throw 'Integration web configuration failed'}
$integrationProcess=Start-Process -WindowStyle Hidden -PassThru -FilePath $integrationNginx -ArgumentList @('-p',($integrationNginxRoot+'/'),'-c','conf/nginx.conf')
$integrationRecords.Add(@{pid=$integrationProcess.Id;exe=$integrationNginx})
[IO.File]::WriteAllText($integrationPid,($integrationRecords|ConvertTo-Json),[Text.UTF8Encoding]::new($false))
Start-Sleep -Milliseconds 600
'Dedicated MySQL test HTTP boundary ready'
