# تسجيل مهمة مجدولة DawakTunnel تشغّل tunnel-supervisor.ps1 عند إقلاع الجهاز، بحساب SYSTEM
# (تعمل سواء سُجّل الدخول أم لا، بلا كلمة مرور مخزنة، وبلا نافذة)، وتُعاد عند الفشل.
# آمن لإعادة التشغيل: يستبدل المهمة إن وُجدت. يضبط صلاحيات C:\dawak-tunnel وtoken.txt (المستخدم الحالي + SYSTEM فقط).
# التشغيل (كمسؤول): powershell -ExecutionPolicy Bypass -File ops\windows\install-tunnel-task.ps1 [-StartNow]
# الرجوع: ops\windows\uninstall-tunnel-task.ps1
param(
    [switch]$StartNow,
    [string]$ExtraArgs = ''      # للاختبار فقط، مثل: -ExtraArgs '-NoPublish'
)

$ErrorActionPreference = 'Stop'
$TaskName   = 'DawakTunnel'
$WorkDir    = 'C:\dawak-tunnel'
$TokenPath  = Join-Path $WorkDir 'token.txt'
$Supervisor = Join-Path $PSScriptRoot 'tunnel-supervisor.ps1'

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this script from an elevated (Administrator) PowerShell.'
}

# صلاحيات: المجلد وما فيه للمستخدم الحالي وSYSTEM فقط (بلا وراثة من C:\).
New-Item -ItemType Directory -Force -Path $WorkDir | Out-Null
& icacls $WorkDir /inheritance:r /grant:r "$($env:USERNAME):(OI)(CI)F" "SYSTEM:(OI)(CI)F" | Out-Null
if (Test-Path $TokenPath) {
    & icacls $TokenPath /inheritance:r /grant:r "$($env:USERNAME):F" "SYSTEM:R" | Out-Null
    Write-Host "[tunnel-task] token.txt found; ACL restricted to $env:USERNAME + SYSTEM"
} else {
    Write-Host "[tunnel-task] WARNING: $TokenPath not found. The supervisor will log an error and exit until it exists."
}

$argLine = "-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$Supervisor`" $ExtraArgs".Trim()
$action   = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $argLine -WorkingDirectory $WorkDir
$trigger  = New-ScheduledTaskTrigger -AtStartup
$trigger.Delay = 'PT30S'   # مهلة صغيرة للشبكة وApache وMariaDB؛ والمشرف ينتظر الأصل المحلي على أي حال
$taskUser = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
                -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) `
                -MultipleInstances IgnoreNew -Hidden
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $taskUser -Settings $settings `
    -Description 'dawak: cloudflared quick tunnel -> ApacheDawak (127.0.0.1:8081) + publish URL to GitHub Pages (ops/windows/tunnel-supervisor.ps1)' -Force | Out-Null
Write-Host "[tunnel-task] task $TaskName registered: $argLine"

if ($StartNow) {
    Start-ScheduledTask -TaskName $TaskName
    Write-Host "[tunnel-task] started. Log: $WorkDir\supervisor.log"
}
