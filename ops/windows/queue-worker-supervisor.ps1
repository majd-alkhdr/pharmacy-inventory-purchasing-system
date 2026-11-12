# مشرف عامل الطوابير لتطبيق dawak (PowerShell 5.1، بلا اعتماديات).
# - ينتظر جاهزية قاعدة البيانات (منفذ MariaDB المحلي يقبل الاتصال)، ثم يشغّل
#   php artisan queue:work --timeout=600 --sleep=3 --max-time=3600 --memory=256 ويكتب خرجه بالسجل.
# - خروج العامل بأي رمز (ومنه الخروج الهادئ كل ساعة بـ--max-time، أو بعد queue:restart من deploy.bat)
#   => انتظار 5 ثوانٍ ثم تشغيل جديد (يحمّل الكود الجديد ويحرّر الذاكرة).
# - 5 خروجات متتالية خلال أقل من 20 ثانية من كل بدء => تأخير متزايد حتى دقيقتين مع سطر ERROR (لا حلقة مجنونة).
# - نسخة واحدة فقط (قفل Global\DawakQueueWorker). يوقف فقط عامله هو (PID محفوظ + سطر أوامره يحوي
#   queue:work ومسار artisan الكامل لهذا المشروع). العامل اليدوي (php artisan queue:work) لا يُلمس أبداً.
# - العامل يقرأ الإعدادات من كاش الإعدادات (config:cache)، فلا تُمرَّر له أي أسرار.
# التشغيل اليدوي (كمسؤول):  powershell -NoProfile -ExecutionPolicy Bypass -File ops\windows\queue-worker-supervisor.ps1
# التشغيل التلقائي: install-queue-worker-task.ps1 (مهمة مجدولة DawakQueueWorker عند الإقلاع).
# خيارات الاختبار: -MaxTime (مثلاً 15)، -WorkDir/-LogPath (سجل منفصل)، -DbPort (منفذ لا يرد)، -WorkerArgs، -FastExitSec.
param(
    [string]$ProjectDir,
    [string]$PhpExe,
    [string]$WorkerArgs,          # يستبدل وسائط العامل كلها (بعد مسار artisan)
    [int]$MaxTime = 0,            # يستبدل قيمة --max-time فقط
    [string]$WorkDir,
    [string]$LogPath,
    [string]$DbHost,
    [int]$DbPort = 0,
    [int]$FastExitSec = 0,
    [string]$TestDatabase         # للاختبار فقط: dawak_perf حصراً (يتجاوز كاش الإعدادات لعملية العامل وحدها)
)

# ===================== الإعدادات =====================
$Config = @{
    ProjectDir       = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
    PhpExe           = 'C:\xampp\php\php.exe'
    # كما يُشغَّل اليوم يدوياً (--timeout=600، والطابور الافتراضي، و--tries من الـJob نفسه = 1)
    # + --sleep=3 (الافتراضي أصلاً) + --max-time=3600 (خروج هادئ كل ساعة بين مهمتين) + --memory=256.
    # لا --queue: لا يوجد أي onQueue/$queue بالكود، كل المهام على الطابور الافتراضي.
    WorkerArgs       = 'queue:work --timeout=600 --sleep=3 --max-time=3600 --memory=256'
    WorkDir          = 'C:\dawak-queue'
    LogPath          = 'C:\dawak-queue\worker.log'
    LogMaxBytes      = 5MB                     # تدوير: worker.log => worker.log.1
    DbHost           = '127.0.0.1'
    DbPort           = 3306                    # MariaDB (خدمة mysql) قد تتأخر بعد الإقلاع
    DbConnectTimeoutMs = 3000
    DbRetrySec       = 10
    RestartDelaySec  = 5                       # بعد أي خروج للعامل
    FastExitSec      = 20                      # خروج أسرع من هذا بعد البدء = خروج «سريع»
    FastExitLimit    = 5                       # عدد الخروجات السريعة المتتالية قبل التأخير المتزايد
    MaxBackoffSec    = 120
    OrphanWaitSec    = 720                     # انتظار يتيم مشغول بمهمة (12 دقيقة > --timeout=600) قبل إيقافه
}
# =====================================================

if ($ProjectDir)       { $Config.ProjectDir = (Resolve-Path $ProjectDir).Path }
if ($PhpExe)           { $Config.PhpExe = $PhpExe }
if ($WorkerArgs)       { $Config.WorkerArgs = $WorkerArgs }
if ($MaxTime -gt 0)    { $Config.WorkerArgs = $Config.WorkerArgs -replace '--max-time=\d+', "--max-time=$MaxTime" }
if ($WorkDir)          { $Config.WorkDir = $WorkDir; if (-not $LogPath) { $Config.LogPath = Join-Path $WorkDir 'worker.log' } }
if ($LogPath)          { $Config.LogPath = $LogPath }
if ($DbHost)           { $Config.DbHost = $DbHost }
if ($DbPort -gt 0)     { $Config.DbPort = $DbPort }
if ($FastExitSec -gt 0) { $Config.FastExitSec = $FastExitSec }

$ErrorActionPreference = 'Continue'
$Artisan = Join-Path $Config.ProjectDir 'artisan'
$PidFile = Join-Path $Config.WorkDir 'worker.pid'
$OutFile = Join-Path $Config.WorkDir 'worker.out.log'
$ErrFile = Join-Path $Config.WorkDir 'worker.err.log'

New-Item -ItemType Directory -Force -Path $Config.WorkDir, (Split-Path $Config.LogPath) | Out-Null

if ($TestDatabase) {
    # وضع اختبار: العامل يقرأ .env مع DB_DATABASE من البيئة بدل كاش الإعدادات (الذي يشير للقاعدة الحيّة).
    if ($TestDatabase -ne 'dawak_perf') { Write-Host "refused: -TestDatabase accepts only dawak_perf"; exit 5 }
    $env:DB_DATABASE      = $TestDatabase
    $env:APP_CONFIG_CACHE = Join-Path $Config.WorkDir 'no-config-cache.php'   # ملف غير موجود => بلا كاش إعدادات
    $env:LOG_CHANNEL      = 'stderr'                                          # لا يكتب بسجل التطبيق الحيّ
}

function Write-Log([string]$Level, [string]$Message) {
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

function Test-DbPort {
    $client = New-Object Net.Sockets.TcpClient
    try {
        $ar = $client.BeginConnect($Config.DbHost, $Config.DbPort, $null, $null)
        if (-not $ar.AsyncWaitHandle.WaitOne($Config.DbConnectTimeoutMs)) { return $false }
        $client.EndConnect($ar)
        return $client.Connected
    } catch { return $false }
    finally { $client.Close() }
}

function Wait-Database {
    $i = 0; $since = Get-Date
    while (-not (Test-DbPort)) {
        if ($i % 6 -eq 0) { Write-Log WARN "database $($Config.DbHost):$($Config.DbPort) not accepting connections (MariaDB not ready?) - waiting" }
        $i++
        Start-Sleep $Config.DbRetrySec
    }
    if ($i -gt 0) { Write-Log INFO "database ready after $([int]((Get-Date) - $since).TotalSeconds)s" }
}

function Get-OwnWorker {
    # العامل المسجَّل بملف PID، بشرط أن يكون فعلاً عاملنا: php.exe وسطر أوامره يحوي queue:work ومسار artisan الكامل.
    if (-not (Test-Path $PidFile)) { return $null }
    $old = (Get-Content $PidFile -ErrorAction SilentlyContinue | Select-Object -First 1)
    if ($old -notmatch '^\d+$') { return $null }
    $p = Get-CimInstance Win32_Process -Filter "ProcessId=$old" -ErrorAction SilentlyContinue
    if ($p -and $p.Name -eq 'php.exe' -and $p.CommandLine -like '*queue:work*' -and $p.CommandLine -like "*$Artisan*") { return $p }
    return $null
}

function Get-LastJobState([string]$Path) {
    # من خرج العامل: آخر سطر مهمة RUNNING بلا DONE/FAIL بعده = مشغول بمهمة الآن.
    if (-not (Test-Path $Path)) { return 'idle' }
    try {
        $fs = New-Object IO.FileStream($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]'ReadWrite, Delete')
        try {
            $len = [Math]::Min($fs.Length, 16384); $null = $fs.Seek(-$len, [IO.SeekOrigin]::End)
            $buf = New-Object byte[] $len; $null = $fs.Read($buf, 0, $len)
        } finally { $fs.Close() }
        $text = [Text.Encoding]::UTF8.GetString($buf)
        $last = [regex]::Matches($text, '(RUNNING|DONE|FAIL)\s*$', 'Multiline') | Select-Object -Last 1
        if ($last -and $last.Groups[1].Value -eq 'RUNNING') { return 'busy' }
    } catch { }
    return 'idle'
}

function Stop-OwnWorker([switch]$Graceful) {
    # يوقف فقط عاملنا. مع -Graceful: إن كان بمنتصف مهمة ينتظر انتهاءها (حتى OrphanWaitSec) قبل الإيقاف.
    $p = Get-OwnWorker
    if ($p) {
        if ($Graceful) {
            $deadline = (Get-Date).AddSeconds($Config.OrphanWaitSec); $logged = $false
            while ((Get-LastJobState $OutFile) -eq 'busy' -and (Get-Date) -lt $deadline -and (Get-Process -Id $p.ProcessId -ErrorAction SilentlyContinue)) {
                if (-not $logged) { Write-Log WARN "orphan worker pid $($p.ProcessId) is in the middle of a job - waiting for it to finish (max $($Config.OrphanWaitSec)s)"; $logged = $true }
                Start-Sleep 2
            }
        }
        if (Get-Process -Id $p.ProcessId -ErrorAction SilentlyContinue) {
            Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
            Write-Log INFO "stopped own worker pid $($p.ProcessId)"
        }
    }
    Remove-Item $PidFile -Force -ErrorAction SilentlyContinue
}

function Get-ForeignWorkers {
    # عمال queue:work ليسوا عاملنا (يدويون/من تطبيقات أخرى). للتنبيه فقط: لا يُلمسون أبداً.
    $own = (Get-Content $PidFile -ErrorAction SilentlyContinue | Select-Object -First 1)
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -like '*queue:work*' -and "$($_.ProcessId)" -ne "$own" -and $_.CommandLine -notlike "*$Artisan*" }
}

# قراءة الخرج الجديد لملفات العامل وكتابته بالسجل (ملفات مفتوحة للكتابة من العامل => FileShare.ReadWrite).
$script:Tail = @{}
function Reset-Tail { $script:Tail = @{ $OutFile = @{ Pos = 0L; Rest = '' }; $ErrFile = @{ Pos = 0L; Rest = '' } } }
function Read-WorkerOutput([switch]$Flush) {
    foreach ($f in @($OutFile, $ErrFile)) {
        if (-not (Test-Path $f)) { continue }
        $st = $script:Tail[$f]
        try {
            $fs = New-Object IO.FileStream($f, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]'ReadWrite, Delete')
            try {
                if ($fs.Length -lt $st.Pos) { $st.Pos = 0L }
                if ($fs.Length -eq $st.Pos) { continue }
                $null = $fs.Seek($st.Pos, [IO.SeekOrigin]::Begin)
                $n = [int][Math]::Min($fs.Length - $st.Pos, 1MB)
                $buf = New-Object byte[] $n; $read = $fs.Read($buf, 0, $n)
                $st.Pos += $read
            } finally { $fs.Close() }
            $text = $st.Rest + [Text.Encoding]::UTF8.GetString($buf, 0, $read)
            $lines = $text -split "`r?`n"
            $st.Rest = $lines[-1]
            $tag = if ($f -eq $ErrFile) { 'worker-err' } else { 'worker' }
            if ($lines.Count -gt 1) {
                foreach ($l in $lines[0..($lines.Count - 2)]) { if ($l.Trim()) { Write-Log INFO "${tag}: $($l.Trim())" } }
            }
        } catch { }
        if ($Flush -and $st.Rest.Trim()) { Write-Log INFO "worker: $($st.Rest.Trim())"; $st.Rest = '' }
    }
}

# ---------- بدء ----------
$mutex = New-Object Threading.Mutex($false, 'Global\DawakQueueWorker')
$owned = $false
try { $owned = $mutex.WaitOne(0) } catch [Threading.AbandonedMutexException] { $owned = $true }   # مالك سابق قُتل
if (-not $owned) { Write-Log ERROR 'another queue worker supervisor instance is already running - exiting'; exit 3 }

try {
    Write-Log INFO "supervisor start (pid $PID, user $env:USERNAME, project $($Config.ProjectDir), args: $($Config.WorkerArgs))"
    if (-not (Test-Path $Config.PhpExe)) { Write-Log ERROR "php not found: $($Config.PhpExe)"; exit 4 }
    if (-not (Test-Path $Artisan))       { Write-Log ERROR "artisan not found: $Artisan"; exit 4 }
    if ($env:DB_DATABASE) { Write-Log WARN "DB_DATABASE overridden by environment: $env:DB_DATABASE (test mode?)" }

    # بقايا تشغيل سابق أُوقف بالقوة (schtasks /End): عاملنا فقط، وبعد انتهاء مهمته الجارية إن وُجدت.
    Stop-OwnWorker -Graceful

    $fastExits = 0
    $lastForeign = ''
    while ($true) {
        Wait-Database
        # تنبيه (مرة عند كل تغيّر): عامل يدوي مع عاملنا = عاملان على نفس الطابور؛ أغلق اليدوي بعد انتهاء مهمته.
        $foreign = (@(Get-ForeignWorkers) | ForEach-Object { "pid $($_.ProcessId)" }) -join ', '
        if ($foreign -and $foreign -ne $lastForeign) { Write-Log WARN "other queue:work process(es) running (not managed, not touched): $foreign" }
        $lastForeign = $foreign

        foreach ($f in $OutFile, $ErrFile) { if (Test-Path $f) { Move-Item -Force $f "$f.prev" -ErrorAction SilentlyContinue } }
        Reset-Tail
        $argList = "`"$Artisan`" $($Config.WorkerArgs)"
        $started = Get-Date
        $proc = Start-Process -FilePath $Config.PhpExe -ArgumentList $argList -WorkingDirectory $Config.ProjectDir `
                    -RedirectStandardOutput $OutFile -RedirectStandardError $ErrFile -WindowStyle Hidden -PassThru
        $null = $proc.Handle   # يُبقي المقبض لقراءة ExitCode لاحقاً
        Set-Content -Path $PidFile -Value $proc.Id -Encoding ASCII
        Write-Log INFO "worker started (pid $($proc.Id))"

        while (-not $proc.HasExited) { Start-Sleep 1; Read-WorkerOutput }
        $proc.WaitForExit()
        Start-Sleep -Milliseconds 300
        Read-WorkerOutput -Flush
        Remove-Item $PidFile -Force -ErrorAction SilentlyContinue
        $ran = ((Get-Date) - $started).TotalSeconds

        if ($ran -lt $Config.FastExitSec) { $fastExits++ } else { $fastExits = 0 }
        $delay = $Config.RestartDelaySec
        if ($fastExits -ge $Config.FastExitLimit) {
            $delay = [Math]::Min($Config.RestartDelaySec * [Math]::Pow(2, $fastExits - $Config.FastExitLimit + 1), $Config.MaxBackoffSec)
            Write-Log ERROR ("worker exited (code {0}) after {1:N1}s - {2} fast exits in a row (< {3}s each): backing off {4}s. Check {5} and storage\logs\laravel.log" -f $proc.ExitCode, $ran, $fastExits, $Config.FastExitSec, $delay, $ErrFile)
        } else {
            Write-Log INFO ("worker exited (code {0}) after {1:N1}s; restarting in {2}s" -f $proc.ExitCode, $ran, $delay)
        }
        Start-Sleep $delay
    }
} finally {
    Read-WorkerOutput -Flush
    Stop-OwnWorker -Graceful   # Ctrl+C: لا يقطع مهمة جارية (schtasks /End لا يصل هنا؛ يُعالَج اليتيم بالتشغيل التالي)
    Write-Log INFO "supervisor stop (pid $PID)"
    $mutex.ReleaseMutex()
}
