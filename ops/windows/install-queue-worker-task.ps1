# تسجيل مهمة مجدولة DawakQueueWorker تشغّل queue-worker-supervisor.ps1 عند إقلاع الجهاز، بحساب SYSTEM
# (تعمل سواء سُجّل الدخول أم لا، بلا كلمة مرور مخزنة، وبلا نافذة)، وتُعاد عند الفشل كل دقيقة.
# آمن لإعادة التشغيل: يستبدل تعريف المهمة إن وُجدت، ولا يوقف مهمة تعمل.
# -StartNow: تسليم رشيق من أي عامل قديم (يدوي) قبل بدء المهمة:
#   1) يعرض العمال القدامى (PID وسطر الأوامر وقاعدتهم إن أمكن معرفتها).
#   2) php artisan queue:restart  => كل عامل يخرج بعد إنهاء مهمته الحالية (لا يُقتل أي عامل).
#   3) ينتظر اختفاءهم (حتى 12 دقيقة: المهمة قد تستغرق حتى --timeout=600).
#   4) يبدأ المهمة. إن بقي عامل قديم: لا يقتله ولا يبدأ المهمة (عاملان معاً خطر: retry_after=90 < 600)،
#      ويطبع ما يجب فعله يدوياً.
# التشغيل (كمسؤول): powershell -ExecutionPolicy Bypass -File ops\windows\install-queue-worker-task.ps1 [-StartNow]
# الرجوع: ops\windows\uninstall-queue-worker-task.ps1
param(
    [switch]$StartNow,
    [int]$HandoverWaitSec = 720,
    [string]$TaskName = 'DawakQueueWorker',   # للاختبار فقط يمكن تغييره
    [string]$ExtraArgs = ''                   # للاختبار فقط، مثل: -ExtraArgs '-TestDatabase dawak_perf -WorkDir C:\dawak-queue-test'
)

$ErrorActionPreference = 'Stop'
$WorkDir    = 'C:\dawak-queue'
$ProjectDir = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Artisan    = Join-Path $ProjectDir 'artisan'
$PhpExe     = 'C:\xampp\php\php.exe'
$MysqlExe   = 'C:\xampp\mysql\bin\mysql.exe'
$Supervisor = Join-Path $PSScriptRoot 'queue-worker-supervisor.ps1'

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this script from an elevated (Administrator) PowerShell.'
}

New-Item -ItemType Directory -Force -Path $WorkDir | Out-Null

$argLine = "-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$Supervisor`" $ExtraArgs".Trim()
$action   = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $argLine -WorkingDirectory $ProjectDir
$trigger  = New-ScheduledTaskTrigger -AtStartup
$trigger.Delay = 'PT60S'   # بعد خدمة mysql؛ والمشرف ينتظر منفذ القاعدة على أي حال
$taskUser = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
                -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) `
                -MultipleInstances IgnoreNew -Hidden

$existing = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
$wasRunning = $existing -and $existing.State -eq 'Running'
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $taskUser -Settings $settings `
    -Description 'dawak: php artisan queue:work (imports) under a self-restarting supervisor (ops/windows/queue-worker-supervisor.ps1). Log: C:\dawak-queue\worker.log' -Force | Out-Null
Write-Host "[queue-task] task $TaskName registered (SYSTEM, at startup +60s): powershell.exe $argLine"
if ($wasRunning) { Write-Host "[queue-task] NOTE: the task was already running; the new definition applies at its next start." }

if (-not $StartNow) { return }

# ---------------- التسليم الرشيق ----------------
$ErrorActionPreference = 'Continue'   # بـ5.1 خرج stderr لأمر خارجي مع Stop يصير خطأً قاطعاً
if ((Get-ScheduledTask -TaskName $TaskName).State -eq 'Running') {
    Write-Host "[queue-task] $TaskName is already running - nothing to start (no duplicate workers)."
    return
}

function Get-WorkerDb {
    # أفضل جهد: قاعدة بيانات كل عملية من اتصالها بالمنفذ 3306 وقائمة عمليات MariaDB. $null = غير معروفة.
    param($ProcessIds)
    $map = @{}
    if (-not (Test-Path $MysqlExe)) { return $map }
    try {
        $rows = & $MysqlExe -u root -h 127.0.0.1 -N -B -e "SELECT HOST, IFNULL(DB,'') FROM information_schema.PROCESSLIST" 2>$null
        if ($LASTEXITCODE -ne 0) { return $map }
        $byPort = @{}
        foreach ($r in $rows) { $c = $r -split "`t"; if ($c[0] -match ':(\d+)$') { $byPort[$Matches[1]] = $c[1] } }
        foreach ($id in $ProcessIds) {
            $dbs = @(Get-NetTCPConnection -OwningProcess $id -RemotePort 3306 -State Established -ErrorAction SilentlyContinue |
                     ForEach-Object { $byPort["$($_.LocalPort)"] } | Where-Object { $_ } | Select-Object -Unique)
            if ($dbs.Count) { $map[$id] = $dbs -join ',' }
        }
    } catch { }
    return $map
}

function Get-OldWorkers {
    # كل عامل queue:work ليس من المشرف (سطر أوامر المُدار يحوي مسار artisan الكامل).
    @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
        Where-Object { $_.CommandLine -like '*queue:work*' -and $_.CommandLine -notlike "*$Artisan*" })
}

# القاعدة التي يستعملها هذا التطبيق (من كاش الإعدادات إن وُجد، وإلا من .env/البيئة) لتمييز عمال تطبيقات أخرى.
$appDb = (& $PhpExe -r "chdir('$($ProjectDir -replace '\\','/')'); require 'vendor/autoload.php'; `$a = require 'bootstrap/app.php'; `$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config('database.connections.'.config('database.default').'.database');" 2>$null)
Write-Host "[queue-task] this app's database: $appDb"

$old = Get-OldWorkers
$dbOf = Get-WorkerDb ($old | ForEach-Object { $_.ProcessId })
$ours = @(); $foreign = @()
foreach ($w in $old) {
    $db = $dbOf[$w.ProcessId]
    if ($db -and $appDb -and $db -notmatch "(^|,)$([regex]::Escape($appDb))(,|$)") { $foreign += $w } else { $ours += $w }
    Write-Host ("[queue-task] old worker pid {0}  started {1}  db={2}  cmd: {3}" -f $w.ProcessId, $w.CreationDate, $(if ($db) { $db } else { '?' }), $w.CommandLine.Trim())
}
foreach ($w in $foreign) { Write-Host "[queue-task] pid $($w.ProcessId) belongs to another database - not affected by queue:restart, ignored (not touched)." }

if ($ours.Count) {
    Write-Host "[queue-task] graceful handover: php artisan queue:restart (each worker exits AFTER its current job; nothing is killed)"
    Push-Location $ProjectDir
    try { & $PhpExe artisan queue:restart } finally { Pop-Location }
    if ($LASTEXITCODE -ne 0) { Write-Host "[queue-task] ERROR: queue:restart failed (exit $LASTEXITCODE). Task NOT started."; exit 6 }

    $ids = $ours | ForEach-Object { $_.ProcessId }
    $start = Get-Date; $deadline = $start.AddSeconds($HandoverWaitSec); $next = $start
    while ($true) {
        $alive = @($ids | Where-Object { Get-Process -Id $_ -ErrorAction SilentlyContinue })
        if (-not $alive.Count) { break }
        if ((Get-Date) -ge $deadline) { break }
        if ((Get-Date) -ge $next) {
            Write-Host ("[queue-task] waiting for old worker(s) to finish their current job and exit: pid {0} ({1}s / {2}s)" -f ($alive -join ', '), [int]((Get-Date) - $start).TotalSeconds, $HandoverWaitSec)
            $next = (Get-Date).AddSeconds(30)
        }
        Start-Sleep 2
    }
    if ($alive.Count) {
        Write-Host "[queue-task] WARNING: old worker(s) still running after $HandoverWaitSec s: pid $($alive -join ', '). NOT killed, and the task was NOT started"
        Write-Host "             (two workers on the same queue could run a long import twice: retry_after=90s < timeout=600s)."
        Write-Host "             Close that worker's terminal (Ctrl+C) once its job is done, then run:  schtasks /Run /TN $TaskName"
        exit 5
    }
    Write-Host "[queue-task] all old workers exited after $([int]((Get-Date) - $start).TotalSeconds)s"
} else {
    Write-Host "[queue-task] no old worker of this app is running"
}

Start-ScheduledTask -TaskName $TaskName
Write-Host "[queue-task] $TaskName started. Log: $WorkDir\worker.log"
