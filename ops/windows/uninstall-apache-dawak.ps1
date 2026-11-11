# إزالة خدمة ApacheDawak فقط (لا يلمس Apache2.4 الحية). يُبقي C:\dawak-apache (السجلات والإعداد) للمراجعة.
# اختياري: -ClearLaravelCache يعيد التطبيق لوضع بلا كاش (php artisan optimize:clear) كما كان قبل التثبيت.
# التشغيل (كمسؤول): powershell -ExecutionPolicy Bypass -File ops\windows\uninstall-apache-dawak.ps1 [-ClearLaravelCache]
param([switch]$ClearLaravelCache)

$ErrorActionPreference = 'Stop'
$ServiceName = 'ApacheDawak'
$Httpd       = 'C:\xampp\apache\bin\httpd.exe'
$RepoRoot    = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

$svc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if ($svc) {
    if ($svc.Status -ne 'Stopped') { Stop-Service -Name $ServiceName -Force }
    & cmd /c "`"$Httpd`" -k uninstall -n $ServiceName 2>&1" | ForEach-Object { Write-Host "[apache-dawak] $_" }
    if ($LASTEXITCODE -ne 0) { & sc.exe delete $ServiceName | Out-Null }
    Write-Host "[apache-dawak] service $ServiceName removed"
} else {
    Write-Host "[apache-dawak] service $ServiceName not installed"
}

if ($ClearLaravelCache) {
    Push-Location $RepoRoot
    try { & php artisan optimize:clear } finally { Pop-Location }
}
