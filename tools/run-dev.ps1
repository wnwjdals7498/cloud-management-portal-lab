param([switch]$Stop)
$ErrorActionPreference='Stop'
$portalRoot=Split-Path $PSScriptRoot
$portalRuntime=Join-Path $portalRoot '.runtime'
$portalPidFile=Join-Path $portalRuntime 'web-processes.json'
if ($Stop) {
    $portalStopNginx = Get-ChildItem -LiteralPath (Join-Path $portalRuntime 'tools/nginx') -Filter 'nginx.exe' -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($portalStopNginx -and (Test-Path -LiteralPath (Join-Path $portalRuntime 'nginx/conf/nginx.conf'))) {
        & $portalStopNginx.FullName -p ((Join-Path $portalRuntime 'nginx').Replace('\','/')+'/') -c conf/nginx.conf -s quit 2>$null
        Start-Sleep -Milliseconds 400
    }
    if(Test-Path -LiteralPath $portalPidFile){foreach($portalRecord in (Get-Content -Raw -LiteralPath $portalPidFile|ConvertFrom-Json)){
        $portalProcess=Get-Process -Id $portalRecord.pid -ErrorAction SilentlyContinue
        $portalStarted=if($portalRecord.started -is [DateTime]){$portalRecord.started.ToUniversalTime().ToString('o')}else{[string]$portalRecord.started}
        $portalSameStart=$portalProcess -and ($portalRecord.started_ticks ? $portalProcess.StartTime.ToUniversalTime().Ticks -eq $portalRecord.started_ticks : (-not $portalStarted -or $portalProcess.StartTime.ToUniversalTime().ToString('o') -eq $portalStarted))
        if($portalProcess -and [IO.Path]::GetFullPath($portalProcess.Path) -eq [IO.Path]::GetFullPath($portalRecord.executable) -and $portalSameStart){Stop-Process -Id $portalProcess.Id}
    }}
    'Workspace web processes stopped'; exit
}
if(Test-Path -LiteralPath $portalPidFile){$portalLive=@(Get-Content -Raw -LiteralPath $portalPidFile|ConvertFrom-Json|Where-Object {Get-Process -Id $_.pid -ErrorAction SilentlyContinue}); if($portalLive.Count){throw 'Workspace web processes already running. Use -Stop first.'}}
$portalConfig=Get-Content -Raw -LiteralPath (Join-Path $portalRuntime 'local.json')|ConvertFrom-Json
$portalPhp=Join-Path $portalRuntime 'tools/php/php.exe'
$portalLogs=Join-Path $portalRuntime 'logs'
New-Item -ItemType Directory -Path $portalLogs -Force|Out-Null
$portalProcesses=[Collections.Generic.List[object]]::new()
foreach($portalApp in @(@{name='api';port=18100},@{name='vmm-simulator';port=18200},@{name='customer-console';port=18300},@{name='admin-console';port=18400})){
    $portalAppRoot=Join-Path $portalRoot ('apps/'+$portalApp.name)
    $portalServer=Start-Process -WindowStyle Hidden -PassThru -WorkingDirectory $portalAppRoot -FilePath $portalPhp -ArgumentList @('-S',('127.0.0.1:'+$portalApp.port),'-t','public','vendor/codeigniter4/framework/system/rewrite.php') -RedirectStandardOutput (Join-Path $portalLogs ($portalApp.name+'.stdout.log')) -RedirectStandardError (Join-Path $portalLogs ($portalApp.name+'.stderr.log'))
    $portalProcesses.Add(@{name=$portalApp.name;pid=$portalServer.Id;executable=[IO.Path]::GetFullPath($portalPhp);started_ticks=$portalServer.StartTime.ToUniversalTime().Ticks})
}
$portalNginxDir=Join-Path $portalRuntime 'nginx'
New-Item -ItemType Directory -Force -Path (Join-Path $portalNginxDir 'conf'),(Join-Path $portalNginxDir 'logs'),(Join-Path $portalNginxDir 'temp')|Out-Null
$portalNginxConfig=@'
worker_processes 1;
pid logs/nginx.pid;
error_log logs/error.log warn;
events { worker_connections 128; }
http {
  log_format portal '$time_iso8601 $host $request_method $uri $status';
  access_log logs/access.log portal;
  server { listen 127.0.0.1:18080 default_server; server_name _; return 403; }
'@
foreach($portalWeb in @(@{host='customer.localhost';port=18300},@{host='admin.localhost';port=18400})){
    $portalServerNames=if($portalWeb.host -eq 'customer.localhost'){'customer.localhost localhost 127.0.0.1'}else{$portalWeb.host}
    $portalNginxConfig+=@"
  server {
    listen 127.0.0.1:18080;
    server_name $portalServerNames;
    location /api/ {
      proxy_pass http://127.0.0.1:18100;
      proxy_set_header Host $($portalWeb.host);
      proxy_set_header X-Portal-Proxy $($portalConfig.proxy_secret);
      proxy_set_header X-User "";
      proxy_set_header X-Role "";
      proxy_set_header Authorization "";
      proxy_set_header Forwarded "";
      proxy_set_header X-Forwarded-For "";
      proxy_set_header X-Forwarded-Host "";
      proxy_set_header X-Forwarded-Proto "";
    }
    location / { proxy_pass http://127.0.0.1:$($portalWeb.port); proxy_set_header Host $($portalWeb.host); }
  }
"@
}
$portalNginxConfig+='}'+"`n"
[IO.File]::WriteAllText((Join-Path $portalNginxDir 'conf/nginx.conf'),$portalNginxConfig,[Text.UTF8Encoding]::new($false))
$portalNginx=(Get-ChildItem -LiteralPath (Join-Path $portalRuntime 'tools/nginx') -Directory|Select-Object -First 1).FullName+'/nginx.exe'
& $portalNginx -p ($portalNginxDir.Replace('\','/')+'/') -c conf/nginx.conf -t
if ($LASTEXITCODE -ne 0) { throw 'Workspace web configuration is invalid' }
$portalNginxProcess=Start-Process -WindowStyle Hidden -PassThru -FilePath $portalNginx -ArgumentList @('-p',($portalNginxDir.Replace('\','/')+'/'),'-c','conf/nginx.conf')
$portalProcesses.Add(@{name='nginx';pid=$portalNginxProcess.Id;executable=[IO.Path]::GetFullPath($portalNginx);started_ticks=$portalNginxProcess.StartTime.ToUniversalTime().Ticks})
[IO.File]::WriteAllText($portalPidFile,($portalProcesses|ConvertTo-Json),[Text.UTF8Encoding]::new($false))
Start-Sleep -Milliseconds 700
'Workspace portal: customer.localhost:18080 / admin.localhost:18080'
