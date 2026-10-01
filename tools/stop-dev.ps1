$ErrorActionPreference='Stop'
$stopRoot=Split-Path $PSScriptRoot
& (Join-Path $PSScriptRoot 'run-workers.ps1') -Stop
& (Join-Path $PSScriptRoot 'run-dev.ps1') -Stop
$stopConfigPath=Join-Path $stopRoot '.runtime/local.json'
if(Test-Path -LiteralPath $stopConfigPath){
    $stopConfig=Get-Content -Raw -LiteralPath $stopConfigPath|ConvertFrom-Json
    $stopMysqlAdmin=Get-ChildItem -LiteralPath (Join-Path $stopRoot '.runtime/tools/mysql') -Recurse -Filter mysqladmin.exe|Select-Object -First 1
    $stopDefaults=Join-Path $stopRoot '.runtime/mysql-stop.cnf'
    [IO.File]::WriteAllText($stopDefaults,("[client]`nhost=127.0.0.1`nport="+$stopConfig.mysql_port+"`nuser=root`npassword="+$stopConfig.root_password+"`n"),[Text.UTF8Encoding]::new($false))
    if($stopMysqlAdmin){
        & $stopMysqlAdmin.FullName "--defaults-file=$stopDefaults" --connect-timeout=2 shutdown
        if($LASTEXITCODE -ne 0){throw 'Workspace MySQL shutdown failed'}
        $stopMysqlPidFile=Join-Path $stopRoot '.runtime/mysql.pid'
        if(Test-Path -LiteralPath $stopMysqlPidFile){
            $stopMysqlProcess=Get-Process -Id ([int](Get-Content -LiteralPath $stopMysqlPidFile)) -ErrorAction SilentlyContinue
            $stopMysqlExe=[IO.Path]::GetFullPath((Join-Path $stopMysqlAdmin.DirectoryName 'mysqld.exe'))
            if($stopMysqlProcess -and [IO.Path]::GetFullPath($stopMysqlProcess.Path) -eq $stopMysqlExe){
                if(-not $stopMysqlProcess.WaitForExit(30000)){throw 'Workspace MySQL has not finished shutting down'}
            }
        }
    }
}
'Workspace prototype stopped; persisted data kept'
