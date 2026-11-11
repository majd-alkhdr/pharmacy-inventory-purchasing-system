# مشرف نفق Cloudflare السريع لتطبيق dawak (PowerShell 5.1، بلا اعتماديات).
# - يشغّل cloudflared نحو Apache المحلي (ApacheDawak على 127.0.0.1:8081) ويلتقط رابط trycloudflare.
# - يتحقق أن الرابط يعمل فعلاً (GET /login = 200 عبر الرابط العام) وعندها فقط ينشره بملف url.json
#   بمستودع GitHub (REST API، لا git) لتقرأه صفحة التوجيه الثابتة على GitHub Pages.
# - فحص صحة كل 60 ثانية: 3 إخفاقات متتالية => يقتل cloudflared ويبدأ من جديد (رابط جديد ونشر جديد).
# - سقوط cloudflared => انتظار 5 ثوانٍ وإعادة التشغيل. التوكن لا يُكتب بأي سجل.
# التشغيل اليدوي (كمسؤول):  powershell -NoProfile -ExecutionPolicy Bypass -File ops\windows\tunnel-supervisor.ps1
# التشغيل التلقائي: install-tunnel-task.ps1 (مهمة مجدولة DawakTunnel عند الإقلاع).
# خيارات الاختبار: -NoPublish (لا ينشر)، -Once (يخرج بعد أول رابط سليم)، -ApiBase (خادم GitHub وهمي).
param(
    [switch]$NoPublish,
    [switch]$Once,
    [string]$ApiBase,
    [string]$TokenPath,
    [string]$LogPath,
    [int]$HealthIntervalSec = 0,
    [int]$LocalPort = 0,
    [string]$Protocol
)

# ===================== الإعدادات =====================
$Config = @{
    LocalPort          = 8081                                   # ApacheDawak
    Protocol           = 'http2'                                # quic | http2 | auto (فحص cloudflared المسبق: QUIC يفشل لبعض المناطق من هذه الشبكة)
    Cloudflared        = 'C:\cloudflared\cloudflared.exe'
    GitHubRepo         = 'majd-alkhdr/dawak-link'               # مستودع صفحة التوجيه (عام، GitHub Pages)
    GitHubBranch       = 'main'
    GitHubFile         = 'url.json'
    GitHubApi          = 'https://api.github.com'
    TokenPath          = 'C:\dawak-tunnel\token.txt'            # Fine-grained token: dawak-link فقط، Contents: Read and write
    WorkDir            = 'C:\dawak-tunnel'
    LogPath            = 'C:\dawak-tunnel\supervisor.log'
    LogMaxBytes        = 5MB                                    # تدوير: supervisor.log => supervisor.log.1
    HealthIntervalSec  = 60
    HealthMaxFailures  = 3
    RestartDelaySec    = 5
    UrlWaitSec         = 60                                     # مهلة ظهور الرابط بخرج cloudflared
    VerifyWaitSec      = 180                                    # مهلة جاهزية الرابط الجديد (DNS)
}
# =====================================================

if ($ApiBase)                { $Config.GitHubApi = $ApiBase.TrimEnd('/') }
if ($TokenPath)              { $Config.TokenPath = $TokenPath }
if ($LogPath)                { $Config.LogPath = $LogPath }
if ($HealthIntervalSec -gt 0) { $Config.HealthIntervalSec = $HealthIntervalSec }
if ($LocalPort -gt 0)        { $Config.LocalPort = $LocalPort }
if ($Protocol)               { $Config.Protocol = $Protocol }

$ErrorActionPreference = 'Continue'
$ProgressPreference = 'SilentlyContinue'   # بدونه Invoke-WebRequest أبطأ بعشرات المرات بـPowerShell 5.1
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$UrlPattern  = 'https://[a-z0-9-]+\.trycloudflare\.com'
$LocalOrigin = "http://127.0.0.1:$($Config.LocalPort)"
$PidFile     = Join-Path $Config.WorkDir 'cloudflared.pid'
$CfOut       = Join-Path $Config.WorkDir 'cloudflared.out.log'
$CfErr       = Join-Path $Config.WorkDir 'cloudflared.log'
$UrlFile     = Join-Path $Config.WorkDir 'current-url.txt'
$CookieJar   = Join-Path $Config.WorkDir 'health-cookies.txt'
$Curl        = Join-Path $env:SystemRoot 'System32\curl.exe'
$script:Token = $null
$script:PublicSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession

New-Item -ItemType Directory -Force -Path $Config.WorkDir, (Split-Path $Config.LogPath) | Out-Null

function Write-Log([string]$Level, [string]$Message) {
    # لا يُكتب التوكن أبداً: أي ظهور له (لو حدث بخطأ ما) يُستبدل قبل الكتابة.
    if ($script:Token) { $Message = $Message.Replace($script:Token, '***') }
    $Message = $Message -replace '(github_pat_|ghp_|gho_|ghs_)[A-Za-z0-9_]+', '$1***'
    $line = '{0} [{1}] {2}' -f (Get-Date -Format 'yyyy-MM-ddTHH:mm:ss.fffzzz'), $Level, $Message
    try {
        if ((Test-Path $Config.LogPath) -and (Get-Item $Config.LogPath).Length -gt $Config.LogMaxBytes) {
            Move-Item -Force $Config.LogPath "$($Config.LogPath).1"
        }
        for ($i = 0; $i -lt 5; $i++) {
            try { Add-Content -Path $Config.LogPath -Value $line -Encoding UTF8 -ErrorAction Stop; break }
            catch { Start-Sleep -Milliseconds 100 }   # الملف مفتوح للقراءة من برنامج آخر
        }
    } catch { }
    Write-Host $line
}

function Get-StatusCode($ErrorRecord) {
    $resp = $ErrorRecord.Exception.Response
    if ($resp -and $resp.StatusCode) { return [int]$resp.StatusCode }
    return 0
}

function Test-Login([string]$BaseUrl, $Session) {
    try {
        $r = Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/login" -WebSession $Session -TimeoutSec 20 -MaximumRedirection 0 -Headers @{ 'Cache-Control' = 'no-cache' }
        return [int]$r.StatusCode
    } catch {
        $code = Get-StatusCode $_
        if ($code) { return $code }
        return -1
    }
}

function Test-PublicLogin([string]$BaseUrl) {
    # فحص الرابط العام عبر شبكة Cloudflare نفسها. الاسم يُحلّ بـDNS-over-HTTPS (1.1.1.1 ثم dns.google) لا بـDNS
    # الجهاز/VPN الذي قد يحفظ «الاسم غير موجود» لدقائق لرابط جديد (مقيس: تحقق استغرق 70–86 ثانية بدونه).
    # --max-time يحدّ المحاولة كلها بما فيها DNS. كوكي ثابت فلا تُنشأ جلسة جديدة بكل فحص.
    if (-not (Test-Path $Curl)) { return Test-Login $BaseUrl $script:PublicSession }
    $code = -1
    foreach ($doh in 'https://1.1.1.1/dns-query', 'https://dns.google/dns-query') {
        $out = & $Curl -s -o NUL -w '%{http_code} %{time_namelookup} %{time_total}' --doh-url $doh --connect-timeout 8 --max-time 15 `
                   -b $CookieJar -c $CookieJar -H 'Cache-Control: no-cache' "$BaseUrl/login" 2>$null
        $exit = $LASTEXITCODE
        $parts = "$out".Split(' ')
        if ($parts[0] -match '^\d{3}$' -and $parts[0] -ne '000') { return [int]$parts[0] }
        Write-Log DEBUG "probe via $doh failed: curl exit $exit, dns $($parts[1])s, total $($parts[2])s"
    }
    return $code
}

function Read-Token {
    if (-not (Test-Path $Config.TokenPath)) { return $null }
    $t = (Get-Content -Path $Config.TokenPath -Raw -ErrorAction SilentlyContinue)
    if ($t) { $t = $t.Trim() }
    if (-not $t) { return $null }
    return $t
}

function Stop-OwnCloudflared {
    # يوقف فقط cloudflared الذي شغّله هذا المشرف (PID محفوظ + سطر أوامر يشير لمنفذنا). لا يلمس الأنفاق الأخرى.
    if (-not (Test-Path $PidFile)) { return }
    $old = (Get-Content $PidFile -ErrorAction SilentlyContinue | Select-Object -First 1)
    if ($old -match '^\d+$') {
        $p = Get-CimInstance Win32_Process -Filter "ProcessId=$old" -ErrorAction SilentlyContinue
        if ($p -and $p.Name -eq 'cloudflared.exe' -and $p.CommandLine -like "*127.0.0.1:$($Config.LocalPort)*") {
            Stop-Process -Id $old -Force -ErrorAction SilentlyContinue
            Write-Log INFO "stopped cloudflared pid $old"
        }
    }
    Remove-Item $PidFile -Force -ErrorAction SilentlyContinue
}

function Invoke-GitHub([string]$Method, [string]$Path, $Body) {
    $headers = @{
        'Authorization'        = "Bearer $script:Token"
        'Accept'               = 'application/vnd.github+json'
        'X-GitHub-Api-Version' = '2022-11-28'
        'User-Agent'           = 'dawak-tunnel-supervisor'
    }
    $params = @{ Method = $Method; Uri = "$($Config.GitHubApi)$Path"; Headers = $headers; TimeoutSec = 30; UseBasicParsing = $true }
    if ($null -ne $Body) {
        $params.Body = [Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Compress))
        $params.ContentType = 'application/json; charset=utf-8'
    }
    return Invoke-RestMethod @params
}

function Publish-Url([string]$Url) {
    # يحدّث url.json عبر PUT /repos/{repo}/contents/url.json مع sha الحالي. يعالج تعارض sha وفشل الشبكة بإعادة المحاولة.
    $script:Token = Read-Token
    if (-not $script:Token) { Write-Log ERROR "token missing at $($Config.TokenPath): cannot publish"; return $false }
    $path = "/repos/$($Config.GitHubRepo)/contents/$($Config.GitHubFile)"
    $json = [ordered]@{ url = $Url; updated = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ') } | ConvertTo-Json -Compress
    $content = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($json))
    $delay = 5
    for ($attempt = 1; $attempt -le 8; $attempt++) {
        $sha = $null
        try {
            $current = Invoke-GitHub GET "$path`?ref=$($Config.GitHubBranch)" $null
            $sha = $current.sha
            if ($current.content) {
                $existing = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String(($current.content -replace '\s', '')))
                if ($existing -match [regex]::Escape("`"$Url`"")) { Write-Log INFO "url.json already points to $Url"; return $true }
            }
        } catch {
            $code = Get-StatusCode $_
            if ($code -eq 404) { $sha = $null }
            elseif ($code -eq 401 -or $code -eq 403) { Write-Log ERROR "GitHub GET refused ($code): check token permissions (Contents: Read and write on $($Config.GitHubRepo))"; return $false }
            else { Write-Log WARN "GitHub GET failed (attempt $attempt, code $code): $($_.Exception.Message)"; Start-Sleep $delay; $delay = [Math]::Min($delay * 2, 60); continue }
        }
        $body = [ordered]@{ message = "Update app URL $(Get-Date -Format 'yyyy-MM-dd HH:mm')"; content = $content; branch = $Config.GitHubBranch }
        if ($sha) { $body.sha = $sha }
        try {
            $null = Invoke-GitHub PUT $path $body
            Write-Log INFO "published $Url to $($Config.GitHubRepo)/$($Config.GitHubFile)"
            return $true
        } catch {
            $code = Get-StatusCode $_
            if ($code -eq 409 -or $code -eq 422) { Write-Log WARN "sha conflict ($code) on attempt ${attempt}: re-reading url.json and retrying"; Start-Sleep 1; continue }
            if ($code -eq 401 -or $code -eq 403) { Write-Log ERROR "GitHub PUT refused ($code): check token permissions"; return $false }
            Write-Log WARN "GitHub PUT failed (attempt $attempt, code $code): $($_.Exception.Message)"
            Start-Sleep $delay; $delay = [Math]::Min($delay * 2, 60)
        }
    }
    Write-Log ERROR "publish failed after retries; will retry at next health check"
    return $false
}

function Wait-LocalOrigin {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $i = 0
    while ($true) {
        $code = Test-Login $LocalOrigin $session
        if ($code -eq 200) { return }
        if ($i % 6 -eq 0) { Write-Log WARN "local origin $LocalOrigin/login = $code (ApacheDawak/MariaDB not ready?) - waiting" }
        $i++
        Start-Sleep 10
    }
}

# ---------- بدء ----------
$mutex = New-Object Threading.Mutex($false, 'Global\DawakTunnelSupervisor')
if (-not $mutex.WaitOne(0)) { Write-Log ERROR 'another supervisor instance is already running - exiting'; exit 3 }

try {
    Write-Log INFO "supervisor start (pid $PID, user $env:USERNAME, port $($Config.LocalPort), protocol $($Config.Protocol), publish=$(-not $NoPublish), api=$($Config.GitHubApi))"
    if (-not $NoPublish) {
        $script:Token = Read-Token
        if (-not $script:Token) {
            Write-Log ERROR "token file missing or empty: $($Config.TokenPath). Create it (fine-grained token for $($Config.GitHubRepo), Contents: Read and write) and start again. Exiting."
            exit 2
        }
    }
    if (-not (Test-Path $Config.Cloudflared)) { Write-Log ERROR "cloudflared not found: $($Config.Cloudflared)"; exit 4 }

    Stop-OwnCloudflared   # بقايا تشغيل سابق (مثلاً بعد إيقاف المهمة بالقوة)

    while ($true) {
        Wait-LocalOrigin
        foreach ($f in $CfOut, $CfErr) { if (Test-Path $f) { Move-Item -Force $f "$f.prev" } }
        $proc = Start-Process -FilePath $Config.Cloudflared -ArgumentList @('tunnel', '--url', $LocalOrigin, '--no-autoupdate', '--protocol', $Config.Protocol) `
                    -RedirectStandardOutput $CfOut -RedirectStandardError $CfErr -WindowStyle Hidden -PassThru
        $null = $proc.Handle   # يُبقي المقبض لقراءة ExitCode لاحقاً
        Set-Content -Path $PidFile -Value $proc.Id -Encoding ASCII
        Write-Log INFO "cloudflared started (pid $($proc.Id))"

        # 1) التقاط الرابط
        $url = $null
        $deadline = (Get-Date).AddSeconds($Config.UrlWaitSec)
        while (-not $url -and (Get-Date) -lt $deadline -and -not $proc.HasExited) {
            Start-Sleep 1
            $text = (Get-Content $CfErr, $CfOut -Raw -ErrorAction SilentlyContinue) -join "`n"
            $m = [regex]::Match($text, $UrlPattern)
            if ($m.Success) { $url = $m.Value }
        }
        if (-not $url) {
            Write-Log ERROR "no tunnel URL within $($Config.UrlWaitSec)s (exited=$($proc.HasExited)); restarting in $($Config.RestartDelaySec)s"
            Stop-OwnCloudflared; Start-Sleep $Config.RestartDelaySec; continue
        }
        Write-Log INFO "tunnel URL: $url"

        # 2) التحقق عبر الرابط العام (الرابط الجديد يحتاج ثوانٍ ليصير جاهزاً)
        Remove-Item $CookieJar -Force -ErrorAction SilentlyContinue
        $script:PublicSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
        $ok = $false; $code = 0; $tries = 0
        $verifyStart = Get-Date
        $deadline = $verifyStart.AddSeconds($Config.VerifyWaitSec)
        Start-Sleep 3
        while (-not $ok -and (Get-Date) -lt $deadline -and -not $proc.HasExited) {
            $tries++
            $code = Test-PublicLogin $url
            if ($code -eq 200) { $ok = $true } else { Start-Sleep 5 }
        }
        if (-not $ok) {
            Write-Log ERROR "public URL not healthy within $($Config.VerifyWaitSec)s (last /login = $code); restarting"
            Stop-OwnCloudflared; Start-Sleep $Config.RestartDelaySec; continue
        }
        Write-Log INFO "verified: $url/login = 200 (after $tries tries, $([int]((Get-Date) - $verifyStart).TotalSeconds)s)"
        Set-Content -Path $UrlFile -Value $url -Encoding ASCII

        # 3) النشر
        $published = $NoPublish.IsPresent
        if ($NoPublish) { Write-Log INFO 'NoPublish: url.json not updated (test mode)' }
        else { $published = Publish-Url $url }
        if ($Once) { Write-Log INFO "Once: exiting (published=$published), stopping cloudflared"; Stop-OwnCloudflared; exit 0 }

        # 4) فحص الصحة
        $failures = 0
        while ($true) {
            $waited = 0
            while ($waited -lt $Config.HealthIntervalSec -and -not $proc.HasExited) { Start-Sleep 1; $waited++ }
            if ($proc.HasExited) { Write-Log WARN "cloudflared exited (code $($proc.ExitCode)); restarting in $($Config.RestartDelaySec)s"; break }
            $code = Test-PublicLogin $url
            if ($code -eq 200) {
                if ($failures) { Write-Log INFO "health OK again after $failures failure(s)" }
                $failures = 0
                if (-not $published) { $published = Publish-Url $url }
            } else {
                $failures++
                Write-Log WARN "health check failed ($failures/$($Config.HealthMaxFailures)): $url/login = $code"
                if ($failures -ge $Config.HealthMaxFailures) { Write-Log ERROR 'tunnel unhealthy: killing cloudflared for a new URL'; break }
            }
        }
        Stop-OwnCloudflared
        Start-Sleep $Config.RestartDelaySec
    }
} finally {
    Stop-OwnCloudflared
    $mutex.ReleaseMutex()
}
