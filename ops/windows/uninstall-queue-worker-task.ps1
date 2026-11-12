# إيقاف/إزالة مهمة DawakQueueWorker برشاقة: يوقف المشرف (فلا يعيد التشغيل)، ثم ينتظر أن ينهي العامل المُدار
# مهمته الجارية (حتى 12 دقيقة) قبل إيقافه. لا يلمس أي عامل يدوي (php artisan queue:work من طرفية).
# التشغيل (كمسؤول):
#   powershell -ExecutionPolicy Bypass -File ops\windows\uninstall-queue-worker-task.ps1             # إزالة المهمة
#   powershell -ExecutionPolicy Bypass -File ops\windows\uninstall-queue-worker-task.ps1 -StopOnly   # إيقاف فقط (تبقى مسجّلة وتبدأ مع الإقلاع)
#   -Force: لا ينتظر انتهاء المهمة الجارية (يقطعها!). يُبقي C:\dawak-queue (السجلات).
param(
    [switch]$StopOnly,
    [switch]$Force,
    [int]$WaitSec = 720,
    [string]$TaskName = 'DawakQueueWorker',   # للاختبار فقط
    [string]$WorkDir = 'C:\dawak-queue'       # للاختبار فقط
)
$ErrorActionPreference = 'Continue'
$ProjectDir = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Artisan    = Join-Path $ProjectDir 'artisan'
$OutFile    = Join-Path $WorkDir 'worker.out.log'

function Test-Busy {
    # آخر سطر مهمة بخرج العامل: RUNNING بلا DONE/FAIL بعده = بمنتصف مهمة.
    if (-not (Test-Path $OutFile)) { return $false }
    try {
        $fs = New-Object IO.FileStream($OutFile, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]'ReadWrite, Delete')
        try {
            $len = [Math]::Min($fs.Length, 16384); $null = $fs.Seek(-$len, [IO.SeekOrigin]::End)
            $buf = New-Object byte[] $len; $null = $fs.Read($buf, 0, $len)
        } finally { $fs.Close() }
        $last = [regex]::Matches([Text.Encoding]::UTF8.GetString($buf), '(RUNNING|DONE|FAIL)\s*$', 'Multiline') | Select-Object -Last 1
        return [bool]($last -and $last.Groups[1].Value -eq 'RUNNING')
    } catch { return $false }
}

# 1) المهمة والمشرف (فلا يُعاد تشغيل العامل). schtasks /End يقتل المشرف فقط؛ العامل يبقى ونعالجه أدناه.
$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($task) {
    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if (-not $StopOnly) { Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false; Write-Host "[queue-task] task $TaskName removed" }
    else { Write-Host "[queue-task] task $TaskName stopped (still registered: starts again at boot, or: schtasks /Run /TN $TaskName)" }
} else {
    Write-Host "[queue-task] task $TaskName not registered"
}
Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" |
    Where-Object { $_.CommandLine -like '*queue-worker-supervisor.ps1*' -and $_.ProcessId -ne $PID } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force; Write-Host "[queue-task] stopped supervisor pid $($_.ProcessId)" }

# 2) العامل المُدار فقط (سطر أوامره يحوي مسار artisan الكامل). العامل اليدوي ("artisan" نسبي) لا يُلمس.
$managed = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
    Where-Object { $_.CommandLine -like '*queue:work*' -and $_.CommandLine -like "*$Artisan*" })
if (-not $managed.Count) { Write-Host "[queue-task] no managed worker running" }
$deadline = (Get-Date).AddSeconds($WaitSec); $said = $false
while (-not $Force -and $managed.Count -and (Test-Busy) -and (Get-Date) -lt $deadline) {
    if (-not $said) { Write-Host "[queue-task] managed worker is in the middle of a job - waiting for it to finish (max $WaitSec s). Ctrl+C to abort."; $said = $true }
    Start-Sleep 2
}
if (-not $Force -and $managed.Count -and (Test-Busy)) {
    Write-Host "[queue-task] WARNING: worker pid $(($managed | ForEach-Object ProcessId) -join ', ') still busy after $WaitSec s - NOT stopped. Run again later, or with -Force."
    exit 5
}
foreach ($w in $managed) {
    Stop-Process -Id $w.ProcessId -Force -ErrorAction SilentlyContinue
    Write-Host "[queue-task] stopped managed worker pid $($w.ProcessId)"
}
Remove-Item (Join-Path $WorkDir 'worker.pid') -Force -ErrorAction SilentlyContinue
