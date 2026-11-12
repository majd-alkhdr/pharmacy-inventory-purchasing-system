# دليل التشغيل (Windows) — dawak

كل الأوامر من PowerShell **كمسؤول**، داخل مجلد المشروع `C:\Users\Administrator\Desktop\dawak-project-develop` ما لم يُذكر غير ذلك.
السكربتات في `ops/windows/`. تقرير التنفيذ والأرقام: `docs/report-infra-apache-tunnel.md`.

## المكوّنات

| المكوّن | ما هو | يبدأ تلقائياً عند الإقلاع؟ | السجل |
|---|---|---|---|
| `ApacheDawak` | خدمة Windows: Apache مستقل لهذا التطبيق على `127.0.0.1:8081` (محلي فقط) | نعم (Automatic، وإعادة تشغيل عند الفشل) | `C:\dawak-apache\logs\error.log` و`access.log` |
| `DawakTunnel` | مهمة مجدولة (SYSTEM) تشغّل `tunnel-supervisor.ps1`: نفق Cloudflare السريع إلى 8081 + نشر الرابط | نعم بعد تثبيتها (30 ثانية بعد الإقلاع) | `C:\dawak-tunnel\supervisor.log` |
| صفحة التوجيه | `https://majd-alkhdr.github.io/dawak-link/` تقرأ `url.json` وتحوّل المستخدم للرابط الحالي | — | — |
| `mysql` (MariaDB) | خدمة Windows مشتركة مع تطبيقات أخرى | نعم (AUTO_START) | — |
| `DawakQueueWorker` | مهمة مجدولة (SYSTEM) تشغّل `queue-worker-supervisor.ps1`: عامل الطوابير (الاستيرادات) `queue:work --timeout=600 --sleep=3 --max-time=3600 --memory=256`، ويُعاد تشغيله تلقائياً عند أي خروج | نعم (60 ثانية بعد الإقلاع، وينتظر MariaDB) | `C:\dawak-queue\worker.log` |

إعدادات ApacheDawak: `C:\dawak-apache\conf\httpd-dawak.conf` (مصدره بالمستودع `ops/windows/apache/httpd-dawak.conf`) و`C:\dawak-apache\php.ini` (نسخة من `C:\xampp\php\php.ini` + `opcache.jit=off`).

## بعد كل سحب كود (git pull) أو تعديل `.env`

```bat
ops\windows\deploy.bat
```

- يمسح الكاش ثم يبني: `config:cache` و`route:cache` و`event:cache` و`view:cache`.
- **مهم:** بعد `config:cache` لا يقرأ التطبيق `.env` إطلاقاً. أي تعديل على `.env` لا يسري حتى تعيد تشغيل `deploy.bat`.
- لا حاجة لإعادة تشغيل `ApacheDawak`: OPcache يلتقط الملفات المعدّلة خلال ثانيتين.
- آخر خطوة فيه `php artisan queue:restart` (بعد `optimize:clear` لا قبله): العامل ينهي مهمته الحالية ويخرج، والمشرف يعيده خلال ~5 ثوانٍ بالكود الجديد. لا خطوة يدوية.
- الرجوع لوضع بلا كاش: `php artisan optimize:clear`.

## ApacheDawak

```powershell
Get-Service ApacheDawak                       # الحالة
Restart-Service ApacheDawak                   # إعادة تشغيل (لا يمس Apache2.4 على 8080/443)
Stop-Service ApacheDawak; Start-Service ApacheDawak
C:\xampp\apache\bin\httpd.exe -t -f C:\dawak-apache\conf\httpd-dawak.conf   # فحص الإعداد قبل أي إعادة تشغيل
Invoke-WebRequest -UseBasicParsing http://127.0.0.1:8081/login | Select-Object StatusCode   # 200
```

- تعديل الإعداد: عدّل `ops/windows/apache/httpd-dawak.conf` ثم `powershell -ExecutionPolicy Bypass -File ops\windows\install-apache-dawak.ps1`. السكربت آمن لإعادة التشغيل ويأخذ نسخة مؤرخة `*.bak-YYYYMMDD-HHMMSS` قبل الاستبدال.
- إن عدّلت `C:\xampp\php\php.ini` (حدود الرفع مثلاً) أعد `install-apache-dawak.ps1` لينسخه للخدمة.
- الإزالة: `powershell -ExecutionPolicy Bypass -File ops\windows\uninstall-apache-dawak.ps1` (أضف `-ClearLaravelCache` للعودة لوضع بلا كاش).

## النفق والرابط الثابت

الرابط الذي يُعطى للموظفين: **`https://majd-alkhdr.github.io/dawak-link/`** (لا يتغير).
الرابط الفعلي الحالي (يتغير مع كل تشغيل للنفق): `C:\dawak-tunnel\current-url.txt`.

```powershell
# تثبيت/تحديث المهمة وتشغيلها الآن (يتطلب C:\dawak-tunnel\token.txt)
powershell -ExecutionPolicy Bypass -File ops\windows\install-tunnel-task.ps1 -StartNow
schtasks /Run /TN DawakTunnel                 # تشغيل
schtasks /End /TN DawakTunnel                 # إيقاف (cloudflared الخاص به يُنظَّف عند التشغيل التالي، أو بسكربت الإزالة)
schtasks /Query /TN DawakTunnel /V /FO LIST   # الحالة وآخر نتيجة (2 = التوكن مفقود)
Get-Content C:\dawak-tunnel\supervisor.log -Tail 30
powershell -ExecutionPolicy Bypass -File ops\windows\uninstall-tunnel-task.ps1   # إزالة المهمة + إيقاف المشرف وcloudflared (8081 فقط)
```

سلوك المشرف:
- ينتظر حتى يرد `http://127.0.0.1:8081/login` بـ200.
- يشغّل `cloudflared tunnel --url http://127.0.0.1:8081 --no-autoupdate --protocol http2`، ويلتقط الرابط.
- يتحقق أن `/login` = 200 **عبر الرابط العام**، ثم يكتب `url.json` بمستودع `majd-alkhdr/dawak-link` عبر GitHub API.
- يفحص الرابط كل 60 ثانية. 3 إخفاقات متتالية ⇒ رابط جديد. سقوط cloudflared ⇒ إعادة تشغيل بعد 5 ثوانٍ.
- لا يلمس الأنفاق الأخرى: يوقف فقط cloudflared الذي شغّله (PID محفوظ بـ`C:\dawak-tunnel\cloudflared.pid` + سطر أوامره يحوي `127.0.0.1:8081`).

التوكن: `C:\dawak-tunnel\token.txt` (سطر واحد). Fine-grained token، المستودع `dawak-link` فقط، صلاحية **Contents: Read and write**. صلاحيات المجلد: المستخدم الحالي وSYSTEM فقط. **لا يوضع بالمستودع أبداً.** عند تجديده: استبدل الملف فقط (يُقرأ عند كل نشر).

صفحة التوجيه: مصدرها `ops/windows/dawak-link/index.html`، وتُرفع لجذر مستودع `dawak-link` (فرع `main`). عند تعديلها ارفعها من جديد.

## عامل الطوابير (الاستيرادات): DawakQueueWorker

لم يعد يُشغَّل يدوياً. المهمة المجدولة `DawakQueueWorker` (حساب SYSTEM، مخفية) تشغّل المشرف `ops\windows\queue-worker-supervisor.ps1`، وهو يشغّل:
`php "<المشروع>\artisan" queue:work --timeout=600 --sleep=3 --max-time=3600 --memory=256`
تقرير التنفيذ: `docs/report-queue-worker-task.md`.

```powershell
schtasks /Query /TN DawakQueueWorker /V /FO LIST       # الحالة (Status: Running) وحساب التشغيل (Run As User: SYSTEM)
Get-Content C:\dawak-queue\worker.log -Tail 30          # السجل: بدء/خروج العامل + خرجه (RUNNING/DONE لكل مهمة)
Get-Content C:\dawak-queue\worker.log -Wait             # متابعة حيّة
# العمال الحاليون (المُدار سطره يحوي مسار artisan الكامل)
Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object CommandLine -like '*queue:work*' | Select-Object ProcessId,CommandLine
schtasks /Run /TN DawakQueueWorker                     # تشغيل (إن كانت تعمل لا يحدث شيء: لا عمال مكررون)
powershell -ExecutionPolicy Bypass -File ops\windows\uninstall-queue-worker-task.ps1 -StopOnly   # إيقاف برشاقة (تبقى مسجّلة وتبدأ مع الإقلاع)
powershell -ExecutionPolicy Bypass -File ops\windows\uninstall-queue-worker-task.ps1             # إزالة المهمة (برشاقة)
powershell -ExecutionPolicy Bypass -File ops\windows\install-queue-worker-task.ps1 -StartNow     # تثبيت/تحديث + تسليم رشيق من أي عامل يدوي ثم تشغيل
php artisan queue:restart                              # إعادة تشغيل العامل بعد مهمته الحالية (deploy.bat يفعلها)
```

سلوك المشرف:
- ينتظر حتى يقبل منفذ MariaDB `127.0.0.1:3306` الاتصال (بعد الإقلاع قد تتأخر الخدمة).
- خروج العامل لأي سبب ⇒ تشغيل جديد بعد 5 ثوانٍ.
  - `--max-time=3600` يجعله يخرج بهدوء كل ساعة **بين مهمتين** (ذاكرة نظيفة وكود جديد)، و`--memory=256` كذلك عند تجاوز الذاكرة.
- 5 خروجات متتالية كل منها خلال أقل من 20 ثانية ⇒ سطر `ERROR` وتأخير متزايد 10 ← 20 ← 40 ← 80 ← 120 ثانية (لا حلقة مجنونة). السبب عادة بـ`C:\dawak-queue\worker.err.log` أو `storage\logs\laravel.log`.
- نسخة واحدة فقط (قفل `Global\DawakQueueWorker`). يوقف فقط عامله (PID بـ`C:\dawak-queue\worker.pid` + سطر أوامره يحوي `queue:work` ومسار `artisan` الكامل)، ولا يلمس أي `php.exe` آخر.
- `schtasks /End` يقتل المشرف فقط ويترك العامل يكمل مهمته. عند التشغيل التالي ينتظر المشرف انتهاء مهمة ذلك العامل الجارية (حتى 12 دقيقة) ثم يوقفه ويبدأ عاملاً جديداً. **للإيقاف استعمل `-StopOnly` لا `/End`.**
- ملفات `C:\dawak-queue\`:
  - `worker.log`: يُدوَّر عند 5MB إلى `worker.log.1`.
  - `worker.out.log` و`worker.err.log`: خرج العامل الحالي، والسابق باللاحقة `.prev`.
  - `worker.pid`.

**لا تشغّل `php artisan queue:work` يدوياً بعد الآن.** عاملان معاً على نفس الطابور خطر مع الاستيرادات الطويلة: `retry_after=90` ثانية أقل من `--timeout=600`، فقد يلتقط العامل الثاني مهمة ما زالت تعمل منذ أكثر من 90 ثانية ويعلّمها فاشلة (`tries=1`). المشرف يكتب `WARN other queue:work process(es) running` إن رأى عاملاً يدوياً.

## بعد إعادة إقلاع الجهاز — قائمة تحقق

1. `Get-Service mysql, ApacheDawak` ⇒ Running.
2. `schtasks /Query /TN DawakTunnel /V /FO LIST` ⇒ Running، و`supervisor.log` فيه `published`.
3. افتح `https://majd-alkhdr.github.io/dawak-link/` ⇒ صفحة الدخول.
4. `schtasks /Query /TN DawakQueueWorker /V /FO LIST` ⇒ Running، و`C:\dawak-queue\worker.log` فيه `worker started` بعد وقت الإقلاع. (لم يعد يُشغَّل يدوياً.)
