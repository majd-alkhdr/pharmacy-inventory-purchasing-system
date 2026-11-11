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
| عامل الطوابير | `php artisan queue:work --timeout=600` (الاستيرادات) | **لا** — يُشغَّل يدوياً | — |

إعدادات ApacheDawak: `C:\dawak-apache\conf\httpd-dawak.conf` (مصدره بالمستودع `ops/windows/apache/httpd-dawak.conf`) و`C:\dawak-apache\php.ini` (نسخة من `C:\xampp\php\php.ini` + `opcache.jit=off`).

## بعد كل سحب كود (git pull) أو تعديل `.env`

```bat
ops\windows\deploy.bat
```

- يمسح الكاش ثم يبني: `config:cache` و`route:cache` و`event:cache` و`view:cache`.
- **مهم:** بعد `config:cache` لا يقرأ التطبيق `.env` إطلاقاً. أي تعديل على `.env` لا يسري حتى تعيد تشغيل `deploy.bat`.
- لا حاجة لإعادة تشغيل `ApacheDawak`: OPcache يلتقط الملفات المعدّلة خلال ثانيتين.
- إن تغيّر كود الاستيراد/الـJobs: أوقف عامل الطوابير وشغّله من جديد (انظر أدناه).
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

## عامل الطوابير (الاستيرادات)

```powershell
# هل يعمل؟
Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object CommandLine -like '*queue:work*' | Select-Object ProcessId,CommandLine
# تشغيله (من مجلد المشروع)
php artisan queue:work --timeout=600
```

لا يبدأ تلقائياً بعد إعادة إقلاع الجهاز. بعد كل إقلاع شغّله يدوياً (أو اجعله مهمة مجدولة، انظر التقرير).

## بعد إعادة إقلاع الجهاز — قائمة تحقق

1. `Get-Service mysql, ApacheDawak` ⇒ Running.
2. `schtasks /Query /TN DawakTunnel /V /FO LIST` ⇒ Running، و`supervisor.log` فيه `published`.
3. افتح `https://majd-alkhdr.github.io/dawak-link/` ⇒ صفحة الدخول.
4. شغّل عامل الطوابير.
