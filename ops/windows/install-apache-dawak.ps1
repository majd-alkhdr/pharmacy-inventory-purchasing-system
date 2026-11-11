# تثبيت/تحديث خدمة ApacheDawak (Apache مستقل لهذا التطبيق على 127.0.0.1:8081).
# آمن لإعادة التشغيل: ينسخ الإعداد، يأخذ نسخة احتياطية مؤرخة من php.ini الخاص بالخدمة قبل استبداله،
# يفحص الإعداد (httpd -t)، يسجل الخدمة إن لم تكن موجودة، يضبط البدء التلقائي وإعادة التشغيل عند الفشل،
# يبني كاش Laravel (deploy.bat) ثم يعيد تشغيل الخدمة ويتحقق من GET /login = 200.
# لا يلمس خدمة Apache2.4 الحية ولا httpd.conf ولا C:\xampp\php\php.ini.
# التشغيل (PowerShell كمسؤول):  powershell -ExecutionPolicy Bypass -File ops\windows\install-apache-dawak.ps1
# الرجوع: ops\windows\uninstall-apache-dawak.ps1

$ErrorActionPreference = 'Stop'

$ServiceName = 'ApacheDawak'
$Root        = 'C:\dawak-apache'
$Httpd       = 'C:\xampp\apache\bin\httpd.exe'
$SourceIni   = 'C:\xampp\php\php.ini'
$Port        = 8081
$RepoRoot    = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$ConfSource  = Join-Path $PSScriptRoot 'apache\httpd-dawak.conf'
$ConfTarget  = Join-Path $Root 'conf\httpd-dawak.conf'
$IniTarget   = Join-Path $Root 'php.ini'
$Stamp       = Get-Date -Format 'yyyyMMdd-HHmmss'

function Step($msg) { Write-Host "[apache-dawak] $msg" }

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this script from an elevated (Administrator) PowerShell.'
}

New-Item -ItemType Directory -Force -Path (Join-Path $Root 'conf'), (Join-Path $Root 'logs') | Out-Null

# 1) الإعداد
if (Test-Path $ConfTarget) { Copy-Item $ConfTarget "$ConfTarget.bak-$Stamp" }
Copy-Item $ConfSource $ConfTarget -Force
Step "config -> $ConfTarget"

# 2) php.ini: نسخة من php.ini الحالي (نفس OPcache ونفس حدود الرفع التي يراها artisan serve).
if (Test-Path $IniTarget) { Copy-Item $IniTarget "$IniTarget.bak-$Stamp" }
Copy-Item $SourceIni $IniTarget -Force
$ini = Get-Content $IniTarget -Raw
$required = [ordered]@{
    'zend_extension'                = 'opcache'
    'opcache.enable'                = '1'
    'opcache.enable_cli'            = '1'
    'opcache.memory_consumption'    = '256'
    'opcache.max_accelerated_files' = '20000'
    'opcache.validate_timestamps'   = '1'
    'opcache.revalidate_freq'       = '2'
}
$missing = @()
foreach ($k in $required.Keys) {
    if ($ini -notmatch "(?m)^\s*$([regex]::Escape($k))\s*=\s*$([regex]::Escape($required[$k]))\s*$") { $missing += "$k=$($required[$k])" }
}
if ($missing.Count) {
    Add-Content -Path $IniTarget -Value (@('', '; ApacheDawak: OPcache (added by install-apache-dawak.ps1)') + $missing) -Encoding ASCII
    Step "php.ini: appended $($missing -join ', ')"
}
# بهذه النسخة فقط: JIT معطّل صراحةً. مع jit=tracing (الافتراضي) وjit_buffer_size=0 يكتب OPcache داخل Apache
# ~1000 سطر "VirtualProtect() failed [87]" لكل طلب بسجل الأخطاء (مقيس). JIT لم يكن مفعّلاً أصلاً، فلا أثر على الأداء.
# (السطر الفارغ الأول مهم: php.ini الحالي لا ينتهي بسطر جديد.)
Add-Content -Path $IniTarget -Value @('', '; ApacheDawak only: silence OPcache JIT VirtualProtect noise (JIT buffer is 0 anyway)', 'opcache.jit=off') -Encoding ASCII
Step "php.ini -> $IniTarget (copied from $SourceIni)"

# 3) فحص الإعداد
& cmd /c "`"$Httpd`" -t -f `"$ConfTarget`" 2>&1" | ForEach-Object { Step "httpd -t: $_" }
if ($LASTEXITCODE -ne 0) { throw 'httpd -t failed: nothing was installed or restarted.' }

# 4) الخدمة
$svc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if (-not $svc) {
    & cmd /c "`"$Httpd`" -k install -n $ServiceName -f `"$ConfTarget`" 2>&1" | ForEach-Object { Step "install: $_" }
    if ($LASTEXITCODE -ne 0) { throw "httpd -k install failed ($LASTEXITCODE)" }
}
& sc.exe config $ServiceName start= auto | Out-Null
& sc.exe failure $ServiceName reset= 86400 actions= restart/5000/restart/5000/restart/5000 | Out-Null
& sc.exe description $ServiceName "Apache for dawak-project-develop on 127.0.0.1:$Port (separate from Apache2.4)" | Out-Null

# 5) كاش Laravel (إلزامي: PHP داخل Apache متعدد الخيوط، فلا تُقرأ .env وقت الطلب).
& cmd /c "`"$RepoRoot\ops\windows\deploy.bat`" 2>&1" | ForEach-Object { Step "deploy: $_" }
if ($LASTEXITCODE -ne 0) { throw 'deploy.bat failed' }

# 6) تشغيل/إعادة تشغيل الخدمة والتحقق
$svc = Get-Service -Name $ServiceName
if ($svc.Status -eq 'Running') { Restart-Service -Name $ServiceName -Force } else { Start-Service -Name $ServiceName }
$ok = $false
for ($i = 0; $i -lt 20 -and -not $ok; $i++) {
    Start-Sleep -Milliseconds 500
    try { $ok = (Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$Port/login" -TimeoutSec 15).StatusCode -eq 200 } catch { }
}
if (-not $ok) { throw "ApacheDawak started but GET http://127.0.0.1:$Port/login is not 200. See $Root\logs\error.log" }
Step "OK: $ServiceName running, http://127.0.0.1:$Port/login = 200"
