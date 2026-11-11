# إزالة مهمة DawakTunnel وإيقاف المشرف وcloudflared الخاص به فقط (المنفذ 8081).
# لا يلمس الأنفاق الأخرى (8000، 8013). يُبقي C:\dawak-tunnel (التوكن والسجلات).
# التشغيل (كمسؤول): powershell -ExecutionPolicy Bypass -File ops\windows\uninstall-tunnel-task.ps1
$ErrorActionPreference = 'Continue'
$TaskName = 'DawakTunnel'

if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    Write-Host "[tunnel-task] task $TaskName removed"
} else {
    Write-Host "[tunnel-task] task $TaskName not registered"
}

# المشرف (أي نسخة يدوية أو من المهمة) ثم cloudflared الخاص بمنفذ 8081 فقط.
Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" |
    Where-Object { $_.CommandLine -like '*tunnel-supervisor.ps1*' -and $_.ProcessId -ne $PID } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force; Write-Host "[tunnel-task] stopped supervisor pid $($_.ProcessId)" }
Get-CimInstance Win32_Process -Filter "Name='cloudflared.exe'" |
    Where-Object { $_.CommandLine -like '*--url http://127.0.0.1:8081*' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force; Write-Host "[tunnel-task] stopped cloudflared pid $($_.ProcessId)" }
Remove-Item 'C:\dawak-tunnel\cloudflared.pid' -Force -ErrorAction SilentlyContinue
