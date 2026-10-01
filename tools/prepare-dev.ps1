$ErrorActionPreference = 'Stop'
$devRoot = Split-Path $PSScriptRoot
$devTools = Join-Path $devRoot '.runtime/tools'
$devPhp = Join-Path $devTools 'php/php.exe'
$devCa = Join-Path $devTools 'cacert.pem'
if (-not (Test-Path -LiteralPath $devCa)) {
    Invoke-WebRequest 'https://curl.se/ca/cacert.pem' -OutFile $devCa
}
$devCaHashPath = Join-Path $devTools 'cacert.pem.sha256'
if(Test-Path -LiteralPath $devCaHashPath){$devCaExpected=(Get-Content -Raw -LiteralPath $devCaHashPath).Trim()}
else {
    $devCaHashContent = (Invoke-WebRequest 'https://curl.se/ca/cacert.pem.sha256').Content
    if ($devCaHashContent -is [byte[]]) { $devCaHashContent = [Text.Encoding]::UTF8.GetString($devCaHashContent) }
    $devCaExpected = ($devCaHashContent -split '\s+')[0].Trim()
}
if ((Get-FileHash -LiteralPath $devCa -Algorithm SHA256).Hash.ToLowerInvariant() -ne $devCaExpected) { throw 'CA bundle checksum mismatch' }
[IO.File]::WriteAllText($devCaHashPath,$devCaExpected,[Text.UTF8Encoding]::new($false))
$devIni = @"
extension_dir="$($devTools.Replace('\','/'))/php/ext"
extension=intl
extension=mbstring
extension=mysqli
extension=pdo_mysql
extension=openssl
extension=curl
extension=zip
extension=fileinfo
curl.cainfo="$($devCa.Replace('\','/'))"
openssl.cafile="$($devCa.Replace('\','/'))"
date.timezone=UTC
memory_limit=512M
display_errors=Off
log_errors=On
"@
[IO.File]::WriteAllText((Join-Path $devTools 'php/php.ini'),$devIni,[Text.UTF8Encoding]::new($false))
& $devPhp -m
if ($LASTEXITCODE -ne 0) { throw 'PHP startup failed' }
$devMysqlBase = (Get-ChildItem -LiteralPath (Join-Path $devTools 'mysql') -Directory | Select-Object -First 1).FullName
$devMysqlData = Join-Path $devRoot '.runtime/mysql-data'
$devLogs = Join-Path $devRoot '.runtime/logs'
New-Item -ItemType Directory -Path $devLogs -Force | Out-Null
if (-not (Test-Path -LiteralPath (Join-Path $devMysqlData 'auto.cnf'))) {
    New-Item -ItemType Directory -Path $devMysqlData -Force | Out-Null
    & (Join-Path $devMysqlBase 'bin/mysqld.exe') --no-defaults --initialize-insecure "--basedir=$devMysqlBase" "--datadir=$devMysqlData"
    if ($LASTEXITCODE -ne 0) { throw 'Workspace DB initialization failed' }
}
$devMysqlPidFile = Join-Path $devRoot '.runtime/mysql.pid'
$devMysqlRunning = $false
if (Test-Path -LiteralPath $devMysqlPidFile) {
    $devExistingMysql=Get-Process -Id ([int](Get-Content -LiteralPath $devMysqlPidFile)) -ErrorAction SilentlyContinue
    $devMysqlRunning=$devExistingMysql -and [IO.Path]::GetFullPath($devExistingMysql.Path) -eq [IO.Path]::GetFullPath((Join-Path $devMysqlBase 'bin/mysqld.exe'))
}
if (-not $devMysqlRunning) {
    $devMysqlProcess = Start-Process -WindowStyle Hidden -PassThru -FilePath (Join-Path $devMysqlBase 'bin/mysqld.exe') -ArgumentList @('--no-defaults',"--basedir=$devMysqlBase","--datadir=$devMysqlData",'--bind-address=127.0.0.1','--port=33307','--mysqlx=0','--console') -RedirectStandardOutput (Join-Path $devLogs 'mysql.stdout.log') -RedirectStandardError (Join-Path $devLogs 'mysql.stderr.log')
    $devMysqlProcess.Id | Set-Content -LiteralPath $devMysqlPidFile
}
$devMysqlClient = Join-Path $devMysqlBase 'bin/mysql.exe'
$devMysqlClientArguments=@('--no-defaults','--host=127.0.0.1','--port=33307','--user=root')
if(Test-Path -LiteralPath (Join-Path $devRoot '.runtime/local.json')){
    $devExistingConfig=Get-Content -Raw -LiteralPath (Join-Path $devRoot '.runtime/local.json')|ConvertFrom-Json
    $devClientConfig=Join-Path $devRoot '.runtime/mysql-client.cnf'
    [IO.File]::WriteAllText($devClientConfig,("[client]`nhost=127.0.0.1`nport=33307`nuser=root`npassword="+$devExistingConfig.root_password+"`n"),[Text.UTF8Encoding]::new($false))
    $devMysqlClientArguments=@("--defaults-file=$devClientConfig")
}
$devMysqlReady = $false
for ($devWait=0; $devWait -lt 30; $devWait++) {
    & $devMysqlClient @devMysqlClientArguments --connect-timeout=2 --execute='SELECT VERSION();' 2>$null
    if ($LASTEXITCODE -eq 0) { $devMysqlReady=$true; break }
    Start-Sleep -Milliseconds 500
}
if (-not $devMysqlReady) { throw 'Workspace DB did not become ready' }
$env:COMPOSER_HOME = Join-Path $devRoot '.runtime/composer-home'
$env:COMPOSER_CACHE_DIR = Join-Path $devRoot '.runtime/composer-cache'
foreach ($devApp in @('api','vmm-simulator','customer-console','admin-console')) {
    $devAppPath = Join-Path $devRoot ('apps/'+$devApp)
    if (-not (Test-Path -LiteralPath (Join-Path $devAppPath 'spark'))) {
        & $devPhp (Join-Path $devTools 'composer.phar') create-project codeigniter4/appstarter:4.7.4 $devAppPath --prefer-dist --no-interaction
        if ($LASTEXITCODE -ne 0) { throw "App starter failed: $devApp" }
    }
    Push-Location $devAppPath
    try { & $devPhp (Join-Path $devTools 'composer.phar') install --prefer-dist --no-interaction --no-progress; if($LASTEXITCODE -ne 0){throw "Locked dependencies failed: $devApp"} }
    finally { Pop-Location }
}
Push-Location (Join-Path $devRoot 'apps/api')
try {
    $devApiComposer=Get-Content -Raw -LiteralPath composer.json|ConvertFrom-Json
    if(-not $devApiComposer.require.'codeigniter4/shield'){
        & $devPhp (Join-Path $devTools 'composer.phar') require codeigniter4/shield:1.4.1 --no-interaction
        if ($LASTEXITCODE -ne 0) { throw 'Shield installation failed' }
    }
} finally { Pop-Location }
'Development runtime and app starters ready'
