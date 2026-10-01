param([switch]$Stop)
$ErrorActionPreference='Stop'
$workerRoot=Split-Path $PSScriptRoot
$workerPidFile=Join-Path $workerRoot '.runtime/worker-processes.json'
if($Stop){
    if(Test-Path -LiteralPath $workerPidFile){foreach($workerRecord in (Get-Content -Raw -LiteralPath $workerPidFile|ConvertFrom-Json)){
        $workerProcess=Get-Process -Id $workerRecord.pid -ErrorAction SilentlyContinue
        $workerStarted=if($workerRecord.started -is [DateTime]){$workerRecord.started.ToUniversalTime().ToString('o')}else{[string]$workerRecord.started}
        $workerSameStart=$workerProcess -and ($workerRecord.started_ticks ? $workerProcess.StartTime.ToUniversalTime().Ticks -eq $workerRecord.started_ticks : $workerProcess.StartTime.ToUniversalTime().ToString('o') -eq $workerStarted)
        if($workerProcess -and [IO.Path]::GetFullPath($workerProcess.Path) -eq [IO.Path]::GetFullPath($workerRecord.exe) -and $workerSameStart){Stop-Process -Id $workerProcess.Id}
    }}
    'Workspace workers stopped';exit
}
if(Test-Path -LiteralPath $workerPidFile){$workerAlive=@(Get-Content -Raw -LiteralPath $workerPidFile|ConvertFrom-Json|Where-Object {Get-Process -Id $_.pid -ErrorAction SilentlyContinue});if($workerAlive.Count){throw 'Workspace workers already running'}}
$workerPhp=[IO.Path]::GetFullPath((Join-Path $workerRoot '.runtime/tools/php/php.exe'))
$workerRecords=[Collections.Generic.List[object]]::new()
foreach($workerApp in @('api','vmm-simulator')){
    $workerProcess=Start-Process -WindowStyle Hidden -PassThru -WorkingDirectory $workerRoot -FilePath $workerPhp -ArgumentList @('tools/portal-cli.php',$workerApp,'loop') -RedirectStandardOutput (Join-Path $workerRoot ('.runtime/logs/'+$workerApp+'-worker.stdout.log')) -RedirectStandardError (Join-Path $workerRoot ('.runtime/logs/'+$workerApp+'-worker.stderr.log'))
    $workerRecords.Add(@{pid=$workerProcess.Id;exe=$workerPhp;started_ticks=$workerProcess.StartTime.ToUniversalTime().Ticks})
}
[IO.File]::WriteAllText($workerPidFile,($workerRecords|ConvertTo-Json),[Text.UTF8Encoding]::new($false))
'Workspace simulated workers started'
