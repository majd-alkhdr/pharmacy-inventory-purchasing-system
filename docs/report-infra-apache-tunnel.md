# تقرير التنفيذ: Apache مستقل + مشرف نفق + رابط ثابت

المواصفة: `prompt-infra-apache-tunnel.md`. وهي المرحلة A من `docs/performance-audit-report.md`. التاريخ: 2026-11-11 (مساءً، بعد دوام المستخدمين).

## الخلاصة

| المرحلة | الحالة |
|---|---|
| 0) فحص الحالة | ✅ كانت منفّذة مسبقاً: OPcache مفعّل، و`APP_DEBUG=false`، و`LOG_LEVEL=warning`. **لم أعدّل `php.ini` ولا `.env`.** |
| 1) Apache مستقل على 8081 | ✅ **منفّذة ومختبرة.** خدمة `ApacheDawak` تعمل وتبدأ تلقائياً، وكاش Laravel مفعّل. |
| 2) مشرف النفق والرابط الثابت | ⚠️ **منفّذة ومختبرة بالكامل، لكن بلا نشر.** ملف التوكن `C:\dawak-tunnel\token.txt` **غير موجود**، فتوقّفت قبل النشر كما تنص المواصفة. اختُبر النشر على خادم GitHub وهمي. |
| 3) التبديل | ⛔ **لم يُنفَّذ** لأن المرحلة 2 لم تكتمل بالنشر. النظام القديم (`artisan serve` على 8013 + نفقه) **لم يُلمس ويعمل**. |

**الرابط الثابت للموظفين** (بعد أن يكمل مجد الخطوات أدناه): **`https://majd-alkhdr.github.io/dawak-link/`**
حالياً هذا الرابط يعرض صفحة README الافتراضية: صفحة التوجيه لم تُرفع بعد.

**تنبيه حدث أثناء التنفيذ:** وجدت عامل الطوابير (`php artisan queue:work --timeout=600`، المشغّل يدوياً الساعة 16:09) متوقفاً، فأعدت تشغيله بنفس الأمر (التفاصيل بقسم «التحذيرات»). لا توجد مهام معلّقة ولا فاشلة.

---

## المرحلة 0: فحص الحالة الحالية

| البند | النتيجة |
|---|---|
| `APP_DEBUG` / `LOG_LEVEL` / `APP_ENV` بـ`.env` | `false` / `warning` / `local`. عُدّل الملف الساعة 16:07، **قبل** بدء عملي، ولم ألمسه. |
| OPcache بـ`C:\xampp\php\php.ini` | مفعّل بالقيم المطلوبة حرفياً (`zend_extension=opcache`، `enable=1`، `enable_cli=1`، `memory_consumption=256`، `max_accelerated_files=20000`، `validate_timestamps=1`، `revalidate_freq=2`). عُدّل الملف الساعة 16:09 ولم ألمسه. **لا توجد نسخة احتياطية لهذا التعديل بمجلد `C:\xampp\php`.** |
| `php -m` / `opcache.enable_cli` | `Zend OPcache` / `1`. و`artisan serve --port=8013` أُعيد تشغيله 16:09، فهو يعمل بـOPcache. |
| حدود الرفع **الفعلية** لـ`artisan serve` (قيست بخادم `php -S` مؤقت بنفس `php.ini`) | `upload_max_filesize=40M`، `post_max_size=100M`، `max_execution_time=120`، `max_input_time=60`، `memory_limit=512M`. لا يوجد `set_time_limit`/`ini_set` بكود `app/`. |
| حدود ApacheDawak (قيست من داخل Apache) | **مطابقة تماماً** (نفس القيم الخمس). |
| MariaDB | **خدمة Windows** باسم `mysql`، `START_TYPE: AUTO_START`، حساب `LocalSystem`. تعمل تلقائياً بعد الإقلاع. |

---

## المرحلة 1: ApacheDawak على 127.0.0.1:8081

### ما نُفِّذ

**1. البنية في `C:\dawak-apache\`:**
- `conf\httpd-dawak.conf`، ومصدره بالمستودع `ops/windows/apache/httpd-dawak.conf`.
- `php.ini`: نسخة من `C:\xampp\php\php.ini` بنفس OPcache وحدود الرفع، **+ `opcache.jit=off`** (انظر القرارات).
- `logs\`: سجلات `error.log` و`access.log` (مع زمن كل طلب بالميكروثانية) و`httpd.pid`.

**2. الإعداد:**
- `ServerRoot "C:/xampp/apache"` و`Listen 127.0.0.1:8081` (محلي فقط)، و`PidFile` وسجلات خاصة، و`PHPIniDir "C:/dawak-apache"`.
- `DocumentRoot` = `public` مع `AllowOverride All` و`Options -Indexes +FollowSymLinks`، وكل ما خارج `public` = `Require all denied`.
- `mod_rewrite`، و`KeepAlive On`، و`Timeout 300`، و`ThreadsPerChild 150`، و`AcceptFilter http/https none` (كما بالإعداد الحيّ).
- `mod_deflate` للـHTML/النص/CSS/JS/JSON/SVG.
- `mod_expires` + `Cache-Control: public, max-age=2592000` (30 يوماً) لـ`css|js|woff|woff2|png|jpg|jpeg|svg|ico`.
- `ServerTokens Prod`.

**3. الفحص:** `httpd.exe -t -f C:\dawak-apache\conf\httpd-dawak.conf` ⇒ `Syntax OK`.

**4. الخدمة:** `ApacheDawak`:
- `START_TYPE: AUTO_START`، والحساب `LocalSystem`.
- إجراءات الفشل: `RESTART 5000ms ×3`، وتصفير كل 86400 ثانية.
- **لا تعارض مع `Apache2.4`:** بقيت Running على 8080/443 طوال الوقت، و`http://127.0.0.1:8080/` ⇒ 302 كما كان.

**5. كاش Laravel:** `config:cache` و`route:cache` و`event:cache` و`view:cache` **نجحت كلها**، حتى `route:cache` (لا مشكلة بالـClosures).
- `storage/*` و`bootstrap/cache` قابلة للكتابة من حساب الخدمة (SYSTEM)، وتحققت من ذلك من داخل Apache.

**6. السكربتات:**
- `ops/windows/install-apache-dawak.ps1`: آمن لإعادة التشغيل، وجرّبته مرتين.
- `ops/windows/uninstall-apache-dawak.ps1` و`ops/windows/deploy.bat`.
- `docs/ops-runbook.md`.

### الاختبارات (أرقام)

**على 8081 مباشرة:**

| الاختبار | النتيجة |
|---|---|
| `GET /login` | 200 |
| `GET /js/table-tools.js?v=…` | 200، `Cache-Control: public, max-age=2592000`، و`Expires` بعد 30 يوماً، و`Content-Encoding: gzip` |
| ضغط HTML | `/login`: 4,228 ← **1,455 بايت** |
| ملفات حساسة | `/.env` ⇒ 404، و`/../.env` ⇒ 400، وفهرسة المجلدات معطّلة |
| إيقاف/تشغيل الخدمة | إيقاف 4.4 ثانية (8081 لا يرد)، وتشغيل 8.2 ثانية ثم `/login` = 200 |
| سجل الأخطاء بعد الإصلاح | 0 سطر `VirtualProtect` (كان ~1000 سطر لكل طلب قبل `opcache.jit=off`) |

**`/login` محلياً: قبل/بعد**
- بلا تسجيل دخول، وبكوكي واحد حتى لا تُنشأ جلسة لكل طلب.
- الأداة: `node ops/windows/tests/http-concurrency.js`.

| القياس | تقرير الأداء (`artisan serve` بلا OPcache) | الحيّ الآن 8013 (`artisan serve` + OPcache) | **ApacheDawak 8081** |
|---|---|---|---|
| `/login` تسلسلياً ×10 (الوسيط) | — (الصفحة الخفيفة 494ms) | 29ms | **33–36ms** |
| 8 طلبات `/login` متوازية (الزمن الكلي) | 3,912ms (صفحة بجلسة دخول) | 391ms (كل طلب ينتظر سابقه: 39 … 386) | **110–182ms** |
| صفحة خفيفة أثناء طلب ثقيل | **11,475ms** (أثناء تقرير الدوران) | لم يُقَس الآن (يحتاج دخولاً بحساب حيّ) | **25–182ms لكل طلب** أثناء طلب ثقيل مدته 8 ثوانٍ |

- **الطلب الثقيل في الاختبار:** سكربت مؤقت خارج التطبيق يشغّل المعالج 8 ثوانٍ، على مسار `/__dawak-test/`.
  - كان مسموحاً محلياً فقط، وممنوعاً عبر النفق (ترويسة `CF-Connecting-IP` ⇒ 403).
  - **حُذف السكربت والإعداد بعد الاختبار** (المسار يرد الآن 404).
- الطلبات الخفيفة الخمسة انتهت كلها خلال 1.2–1.4 ثانية من بدء الطلب الثقيل، فلم تنتظره. **هذا يثبت حل التجمّد.**
- الاختبار الوظيفي الكامل (دخول، فواتير، تصدير) جرى سابقاً على `dawak_perf` بتقرير الأداء. لم أدخل بأي حساب حيّ.

**عبر الرابط العام (Cloudflare):**
- القياس من السيرفر نفسه، فطرفا الرحلة على نفس الشبكة.
- على الجهاز واجهة ProtonVPN، والشبكة الليلة أبطأ من وقت تقرير الأداء.

| القياس | النفق القديم ← 8013 (QUIC) | النفق الجديد ← 8081 (http2) |
|---|---|---|
| `/login` تسلسلياً ×10 (الوسيط، 3 جولات) | 442 / 671 / 771ms | 558 / 563 / 705ms |
| 8 طلبات `/login` متوازية | 1,369 / 1,584 / 1,796ms | 1,058 / 1,233 / 1,657ms |
| `/login` عبر النفق أثناء طلب ثقيل 8 ثوانٍ | لم يُقَس | 269–845ms (لم تنتظر) |
| ملف ثابت `robots.txt` (أول بايت ×5) | 0.62–1.17s | 0.72–1.02s |

- **الخلاصة:** زمن الطلب المفرد عبر النفق تحكمه الشبكة الليلة (~0.6–0.8s للملف الثابت، مقابل 290ms بتقرير الأداء). فالفرق بين الخادمين صغير بالطلب المفرد.
- الكسب الحقيقي يظهر بالتزامن وبالطلبات الثقيلة.
- **جولة أولى إضافية فشلت (`fetch failed`) على النفقين معاً**، فالشبكة متذبذبة. لم أحسبها.

### القرارات الصغيرة (المرحلة 1)

**1. لم أنطلق من `httpd-perf.conf` حرفياً.** أخذت بنيته المجرّبة، وحذفت ما يخص القياس:
- `SetEnv DB_DATABASE dawak_perf` وتحويل مسارات الكاش.
- وأضفت ما طلبته المواصفة.

**2. `opcache.jit=off` بنسخة ApacheDawak فقط** (لا بـ`php.ini` الحيّ):
- **السبب:** مع القيمة الافتراضية `jit=tracing` و`jit_buffer_size=0` يكتب OPcache داخل Apache نحو **1,000 سطر `VirtualProtect() failed [87]` لكل طلب**.
- **كيف تحققت:**
  - نسختان مؤقتتان: `pcre.jit=0` لم يُصلحها، وتعطيل OPcache أزالها.
  - مصدر الرسالة `php_opcache.dll`.
- JIT لم يكن يعمل أصلاً (الذاكرة المخصّصة له 0)، فلا أثر على الأداء. المقيس بعده: `/login` 34ms.

**3. `php.ini` الحيّ لا ينتهي بسطر جديد.**
- اكتشفته حين أُلصق سطر أضفته لنسخة اختبار بآخر سطر في الملف، فتعطّلت تلك النسخة.
- سكربت التثبيت يضيف سطراً فارغاً قبل أي إضافة.

**4. Arabic في `deploy.bat` كسر `cmd`** (تفسير UTF-8)، فأصبح الملف بالإنجليزية فقط.
- **الأثر:** أول تشغيل طبع أخطاء «not recognized» بلا ضرر، ثم نجح.

**5. `IncludeOptional C:/dawak-apache/conf/test-only*.conf`** بقي بالإعداد كنقطة اختبار مستقبلية.
- لا ملف الآن، فلا أثر له.

---

## المرحلة 2: المشرف والرابط الثابت

### المتطلبات المسبقة

| المتطلب | الحالة |
|---|---|
| `C:\dawak-tunnel\token.txt` | ❌ **غير موجود** (المجلد نفسه لم يكن موجوداً) |
| المستودع `majd-alkhdr/dawak-link` | ✅ عام، و`has_pages: true`، والفرع `main`، ولا يحوي إلا `README.md` |
| GitHub Pages | ✅ `https://majd-alkhdr.github.io/dawak-link/` = 200 (صفحة Jekyll افتراضية) |

لهذا نُفِّذ 2-أ و2-ب والاختبار فقط، **دون أي نشر**: لم يُدفع شيء لمستودع `dawak-link`، ولم يُكتب `url.json`.

### 2-أ صفحة التوجيه: `ops/windows/dawak-link/index.html`

- عربية RTL، بلا أي مكتبة خارجية (≈5KB).
- تجلب الرابط من API GitHub (`Accept: application/vnd.github.raw`). عند الفشل أو تجاوز الحد تجلبه من `raw.githubusercontent.com/...url.json?t=<الوقت>`.
- ثم تنفّذ `location.replace(url)`.
- عند غياب رابط صالح: «التطبيق قيد الإقلاع، أعد المحاولة بعد دقيقة» مع زر، وإعادة محاولة تلقائية كل 10 ثوانٍ.
- **قرار:** لا تقبل إلا رابطاً بصيغة `https://*.trycloudflare.com` (حماية من التوجيه لموقع آخر إن عُبث بالملف). إن صار للتطبيق نطاق ثابت يُعدَّل سطر `ALLOWED`.

**اختبار بمتصفح حقيقي** (Chrome عبر Playwright):
- الصفحة قُدِّمت كأنها من `majd-alkhdr.github.io`، وGitHub مُحاكى.
- الانتقال للتطبيق تمّ **عبر رابط نفق حقيقي إلى ApacheDawak**.

| السيناريو | النتيجة |
|---|---|
| A) API يعيد الرابط | ✅ وصل إلى `https://…trycloudflare.com/login` وفيها نموذج الدخول (5.0s) |
| B) API محدود (403) ⇒ raw | ✅ نفس النتيجة (4.2s) |
| C) الملف غير موجود (404) | ✅ رسالة الإقلاع + زر + إعادة محاولة تلقائية (طلبان خلال 11 ثانية) |
| D) رابط لموقع آخر / `javascript:` | ✅ رُفض ⇒ رسالة الإقلاع |
| E) فشل مرتين ثم ظهر الرابط | ✅ انتقل تلقائياً للتطبيق (27s) |
| F) GitHub الحقيقي الآن (لا `url.json`) | ✅ رسالة الإقلاع (السلوك الصحيح حالياً) |

### 2-ب المشرف: `ops/windows/tunnel-supervisor.ps1`

**البنية:**
- PowerShell 5.1 بلا اعتماديات. الإعدادات بجدول `$Config` أعلى الملف: المنفذ 8081، ومسار `cloudflared`، والمستودع، والتوكن، والسجل مع تدوير عند 5MB، وفترات الفحص.
- مفاتيح للاختبار: `-NoPublish` و`-Once` و`-ApiBase` و`-TokenPath` و`-LogPath` و`-HealthIntervalSec` و`-Protocol`.

**الحلقة:**
1. ينتظر الأصل المحلي (`/login` = 200).
2. يشغّل `cloudflared tunnel --url http://127.0.0.1:8081 --no-autoupdate --protocol http2`، ويلتقط الرابط بالتعبير المطلوب.
3. يتحقق أن `/login` = 200 **عبر الرابط العام**.
4. ينشر `{"url": "...", "updated": "<ISO>"}`: `GET` لمعرفة `sha` الحالي ثم `PUT` بالتوكن.
   - يعالج تعارض `sha` (409/422) بإعادة القراءة والمحاولة.
   - وفشل الشبكة بتأخير متزايد 5 ← 60 ثانية.
   - ولا يعيد النشر إن كان `url.json` يحوي الرابط نفسه.
5. فحص كل 60 ثانية: 3 إخفاقات متتالية ⇒ يقتل cloudflared ويبدأ من جديد. سقوط cloudflared ⇒ 5 ثوانٍ ثم إعادة.

**الحماية:**
- قفل `Global\DawakTunnelSupervisor` يمنع تشغيل نسختين.
- يوقف **فقط** cloudflared الخاص به: PID محفوظ + سطر أوامره يحوي `127.0.0.1:8081`. نفقا 8000 و8013 لم يُلمسا بأي اختبار.
- عند البدء ينظّف cloudflared يتيماً من تشغيل سابق أُوقف بالقوة.

**التوكن:**
- يُقرأ من الملف عند كل نشر، فاستبدال الملف يكفي لتجديده.
- لا يُكتب بأي سجل، وتوجد طبقة إخفاء إضافية لأي نص يشبه `github_pat_…`.

**قرارات بعد القياس:**
- **`--protocol http2`:** فحص cloudflared المسبق على هذه الشبكة: `UDP Connectivity: QUIC connection failed` لإحدى المنطقتين و`suggested_protocol=http2`. والنفق القديم (QUIC) سجّل 49 إعادة اتصال بتقرير الأداء. القيمة قابلة للتغيير بـ`$Config.Protocol`.
- **التحقق عبر الرابط العام بـ`curl.exe --doh-url`** (DNS-over-HTTPS إلى 1.1.1.1 ثم dns.google) بدل DNS الجهاز:
  - بالـDNS العادي (عبر واجهة ProtonVPN) استغرق تحقق رابط جديد **70–86 ثانية**، لأن الاسم الجديد لا يُعرف فوراً.
  - بعد التغيير: **5–18 ثانية**.
  - `--max-time 15` يحدّ كل محاولة. وفحص الصحة يستعمل كوكي ثابتاً، فلا يُنشئ صف جلسة جديداً بالقاعدة كل دقيقة.
- **`$ProgressPreference='SilentlyContinue'`:** بدونه كان `Invoke-WebRequest` يأخذ ~2.2 ثانية لطلب مدته 30ms، وتأخّر بدء المشرف 38 ثانية.

### نتائج اختبارات المشرف

- **رابط حقيقي:** كل الاختبارات أنشأت أنفاقاً حقيقية إلى 8081 وتحققت عبر الرابط العام.
- **خادم GitHub وهمي:** `ops/windows/tests/mock-github.cjs` يحاكي `GET`/`PUT contents` و`sha` و409 وقطع الاتصال، ويتحقق من ترويسة التوكن.

| الاختبار | النتيجة |
|---|---|
| غياب التوكن | ✅ رسالة واضحة: `token file missing or empty: C:\dawak-tunnel\token.txt. Create it (…) and start again. Exiting.`، و**خروج بالرمز 2**، ولا يُشغَّل أي نفق. وعبر المهمة المجدولة: `Last Result: 2`. |
| التقاط الرابط + التحقق + كتابة `url.json` | ✅ الرابط بعد ~12s، والتحقق 6–14s، والنشر بعدها. وصل `url.json` بالصيغة المطلوبة وبالفرع `main`، والتوكن صحيح (`badAuth=0`). |
| فشل شبكة مؤقت (قطع أول اتصالين) | ✅ لم ينهر: `WARN GitHub GET failed (attempt 1…)` ثم أكمل. |
| تعارض `sha` (409 بعد تعديل «طرف آخر») | ✅ `WARN sha conflict (409) on attempt 2: re-reading url.json and retrying` ثم `published`. |
| توكن مرفوض (401) | ✅ `ERROR GitHub GET refused (401): check token permissions…`، ولم ينهر، ويعيد المحاولة مع فحص الصحة التالي. |
| GitHub غير متاح كلياً (8 محاولات) | ✅ بقي النفق يعمل، و`publish failed after retries; will retry at next health check`. |
| الاسترداد: قتل cloudflared المُدار | ✅ أُعيد تشغيله بعد **6 ثوانٍ**، ونُشر الرابط الجديد بعد **27 ثانية** من القتل. (قبل إصلاح DNS: 86 ثانية.) |
| فحص الصحة: إيقاف ApacheDawak 80 ثانية | ✅ ثلاثة 502 متتالية ⇒ قتل النفق ⇒ انتظار الأصل المحلي ⇒ نفق ورابط جديدان نُشرا بعد **19 ثانية** من عودة Apache. |
| تشغيل نسخة ثانية | ✅ القفل يمنعها (`another supervisor instance is already running`، رمز 3). |
| نفق يتيم بعد إيقاف المشرف بالقوة | ✅ نُظِّف عند التشغيل التالي (`stopped cloudflared pid …`). |
| تسرّب التوكن بالسجلات | ✅ بحثت عن **كل مقطع من 8 أحرف** من توكن الاختبار (39 حرفاً) بكل السجلات: **0 نتيجة**. ولا يظهر أي `github_pat_` إطلاقاً. |

### المهمة المجدولة: `install-tunnel-task.ps1` / `uninstall-tunnel-task.ps1`

**الإعداد:**
- `DawakTunnel` بحساب **SYSTEM**: «تعمل سواء سُجّل الدخول أم لا» دون تخزين كلمة مرور.
- عند الإقلاع بعد 30 ثانية، ونافذة مخفية، بلا حد زمني.
- إعادة التشغيل عند الفشل كل دقيقة (حتى 999 مرة)، و`IgnoreNew` للنسخ المتزامنة.

**الصلاحيات:**
- السكربت يضبط `C:\dawak-tunnel` بلا وراثة: المستخدم الحالي + SYSTEM فقط.
- و`token.txt` إن وُجد: المستخدم (كامل) + SYSTEM (قراءة).
- السكربت آمن لإعادة التشغيل (`-Force`).

**الاختبار** (بوضع `-NoPublish` لعدم وجود التوكن):

| الاختبار | النتيجة |
|---|---|
| `schtasks /Run` | المشرف يعمل كـ`PURCHASE10$` (SYSTEM)، وcloudflared كذلك، ولا نافذة. التحقق عبر الرابط العام بعد 5–10s من التقاط الرابط، و24–31s من بدء المشرف. |
| `/End` ثم `/Run` (4 جولات) | كل مرة نظّف النفق السابق وأنشأ رابطاً جديداً متحققاً. |
| ملاحظة | الزمن من أمر `/Run` حتى بدء المشرف فعلياً تراوح بين ثوانٍ و~60 ثانية بعد `/End` مباشرة (جدولة Windows). |

**الحالة النهائية:**
- **أزلتُ المهمة** بـ`uninstall-tunnel-task.ps1`، فلا توجد مهمة `DawakTunnel` الآن.
- **السبب:** لا توكن، فستفشل عند كل إقلاع. وتثبيتها مع التوكن يعني بدء النشر، وهو قرار مجد (أمر واحد أدناه).

---

## المرحلة 3: التبديل — لم يُنفَّذ

ملف التوكن غائب، فلا نشر، والقاعدة الذهبية تمنع أي خطوة تبديل قبل أن يثبت النظام الجديد عبر الرابط العام **المنشور**.

**الوضع الآن:**
- `artisan serve` على 8013 والنفق القديم **يعملان ولم يُلمسا**.
  - `https://phentermine-month-counseling-queens.trycloudflare.com/login` = 200 بنهاية العمل.
- `ApacheDawak` يعمل محلياً على 8081 وجاهز، ولا يصل إليه أحد من الخارج (لا نفق إليه الآن).
- **اختبار إعادة الإقلاع الفعلي متروك لمجد** (لم أُعِد تشغيل الجهاز).

---

## خطوات مجد المتبقية (بالترتيب)

1. **التوكن:** أنشئ Fine-grained token على GitHub:
   - Repository access: **Only select repositories ← `dawak-link`**.
   - Permissions ← Repository ← **Contents: Read and write**.
   - احفظه سطراً واحداً بـ`C:\dawak-tunnel\token.txt`. المجلد موجود، وصلاحياته للمستخدم وSYSTEM فقط.
2. **صفحة التوجيه:** ارفع `ops/windows/dawak-link/index.html` لجذر مستودع `dawak-link` (فرع `main`)، مثلاً من واجهة GitHub: Add file ← Upload files. اختياري: ملف فارغ `.nojekyll` بجانبه.
3. **ثبّت المهمة وشغّلها** (PowerShell كمسؤول بمجلد المشروع):
   ```powershell
   powershell -ExecutionPolicy Bypass -File ops\windows\install-tunnel-task.ps1 -StartNow
   Get-Content C:\dawak-tunnel\supervisor.log -Wait     # انتظر: verified … ثم published …
   ```
4. **تحقّق:** افتح `https://majd-alkhdr.github.io/dawak-link/`، يجب أن يوصلك لصفحة الدخول. سجّل دخولك وجرّب رفع ملف وتصديراً.
5. **بعد اقتناعك:** أعطِ الموظفين الرابط الثابت، ثم أوقف النظام القديم:
   ```powershell
   # النفق القديم (8013 فقط — لا يلمس نفق 8000)
   Get-CimInstance Win32_Process -Filter "Name='cloudflared.exe'" | Where-Object CommandLine -like '*--url http://127.0.0.1:8013*' | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
   # artisan serve على 8013 فقط (لا يلمس 8000/8012/8020)
   Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -like '*artisan serve --port=8013*' -or $_.CommandLine -like '*-S 127.0.0.1:8013 *' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
   ```
   (أو أغلق النافذتين إن كانتا ظاهرتين.)
6. **اختبار الإقلاع:** أعد تشغيل الجهاز بوقت مناسب. تحقق:
   - `Get-Service mysql, ApacheDawak` ⇒ Running.
   - `supervisor.log` فيه `published`.
   - الرابط الثابت يعمل.
   - شغّل عامل الطوابير (التحذير 1).

## الرجوع للوضع القديم بالكامل

النظام القديم لم يُمس، فالرجوع الآن يعني فقط إزالة الجديد:
```powershell
powershell -ExecutionPolicy Bypass -File ops\windows\uninstall-tunnel-task.ps1                       # إن ثُبّتت المهمة
powershell -ExecutionPolicy Bypass -File ops\windows\uninstall-apache-dawak.ps1 -ClearLaravelCache    # يزيل الخدمة ويعيد التطبيق بلا كاش
```

إن كان النظام القديم قد أُوقف (بعد الخطوة 5)، فشغّله من جديد من نافذتين بمجلد المشروع:
```bat
php artisan serve --port=8013
C:\cloudflared\cloudflared.exe tunnel --url http://127.0.0.1:8013
```
النفق القديم سيعطي رابطاً جديداً يظهر بخرجه.

- `php.ini` و`.env`: لم أعدّلهما، فلا شيء يُرجَع منهما.
- ملفات `C:\dawak-apache` و`C:\dawak-tunnel` يمكن حذفها يدوياً بعد الإزالة.

| المكوّن | تشغيل | إيقاف | إزالة/رجوع |
|---|---|---|---|
| ApacheDawak | `Start-Service ApacheDawak` | `Stop-Service ApacheDawak` | `uninstall-apache-dawak.ps1 [-ClearLaravelCache]` |
| كاش Laravel | `ops\windows\deploy.bat` | — | `php artisan optimize:clear` |
| DawakTunnel | `schtasks /Run /TN DawakTunnel` | `schtasks /End /TN DawakTunnel` | `uninstall-tunnel-task.ps1` |
| المشرف يدوياً (للتجربة) | `powershell -ExecutionPolicy Bypass -File ops\windows\tunnel-supervisor.ps1 [-NoPublish]` | Ctrl+C | — |
| النظام القديم | الأمران أعلاه | أوامر الخطوة 5 | — |

---

## التحذيرات

1. **عامل الطوابير (`queue:work`) ليس تلقائياً، ووجدته متوقفاً:**
   - كان يعمل منذ 16:09 من نافذة VS Code. وجدته متوقفاً قرب نهاية العمل، **فأعدت تشغيله بنفس الأمر** (PID 18532، مخفياً).
   - خرجه في `C:\dawak-apache\logs\queue-worker.*.log`.
   - **السبب المرجّح:** `deploy.bat` يبدأ بـ`optimize:clear`، وهو يمسح الكاش (`cache:clear`). إن كان مفتاح `illuminate:queue:restart` موجوداً من `queue:restart` سابق، فمسحه يجعل العامل يخرج بهدوء. **لم أستطع إثبات ذلك:** المفتاح مُسح، ولا سجل لخروج العامل.
   - الآن لا مفتاح، فتشغيل `deploy.bat` مستقبلاً لن يوقف العامل الجديد إلا إن نُفّذ `queue:restart`.
   - لا مهام معلّقة (`jobs = 0`) ولا فاشلة (`failed_jobs = 0`).
   - **بعد أي إعادة إقلاع لن يعمل العامل** (والاستيرادات تعتمد عليه) حتى يُشغَّل يدوياً. أنصح بجعله مهمة مجدولة مستقبلاً (خارج هذه المواصفة).
2. **كاش Laravel مفعّل الآن للتطبيق كله، بما فيه الحيّ على 8013** (نفس المجلد):
   - أي تعديل على `.env` أو سحب كود فيه مسارات/أحداث جديدة **يتطلب `ops\windows\deploy.bat`**.
   - تحققت أن 8013 يعمل بعده: `/login` = 200.
3. **رابط trycloudflare السريع بلا ضمان تشغيل:** قد يتغير أو يسقط، والمشرف يعالج ذلك بإعادة النشر. الحل الدائم نفق مسمّى بحساب Cloudflare (البند 6 بتقرير الأداء).
4. **حد GitHub API بلا توكن 60 طلباً/ساعة لكل IP:**
   - إن فتح موظفون كثيرون من نفس الشبكة صفحة التوجيه أكثر من ذلك، تنتقل الصفحة لـ`raw.githubusercontent.com`.
   - قد يتأخر تحديثه حتى ~5 دقائق بعد تغيّر الرابط (لم يُقَس).
5. **`url.json` بمستودع عام:** رابط التطبيق الحالي مكشوف لمن يعرف المستودع. هذا كأي رابط يُشارك، والتطبيق محمي بتسجيل الدخول.
6. **تطبيق آخر على 8012 (`STK_DLG`) لم يرد** على `GET /` خلال 40 ثانية عند فحص الختام:
   - عمليته `php -S` تعمل منذ 10/10، ولا اتصالات مفتوحة إليها، واستهلاكها للمعالج طبيعي.
   - **لم ألمسه** (ممنوع بالقيود) ولم أرسل إليه أي طلب قبل هذا الفحص.
   - 8020 و8000 و8080 ترد طبيعياً.
7. **`VirtualProtect() failed`** ظهر أيضاً بسجل Apache التجريبي بتقرير الأداء. إن فُعّل OPcache يوماً لخدمة `Apache2.4` الحيّة فسيظهر هناك أيضاً، والحل نفسه (`opcache.jit=off`).
8. ترويسة `X-Powered-By: PHP/8.2.12` ما زالت تظهر (لم أغيّر `expose_php`)، وأمرها بسيط.

## ما بقي خارج المستودع

- `C:\dawak-apache\`:
  - `conf\httpd-dawak.conf` و`php.ini`، مع نسختيهما الاحتياطيتين `*.bak-20261111-200423` من إعادة التثبيت الثانية.
  - `logs\`، وفيه خرج عامل الطوابير.
- `C:\dawak-tunnel\`: سجلات الاختبار مؤرشفة باسم `supervisor-tests-20261111.log` و`cloudflared-tests-20261111.log`. توكنات الاختبار المزيفة حُذفت.
- خدمة `ApacheDawak` (Automatic، Running).

## ملفات المستودع (هذا الـcommit)

- `ops/windows/apache/httpd-dawak.conf`
- `ops/windows/install-apache-dawak.ps1`، `ops/windows/uninstall-apache-dawak.ps1`
- `ops/windows/deploy.bat`
- `ops/windows/tunnel-supervisor.ps1`
- `ops/windows/install-tunnel-task.ps1`، `ops/windows/uninstall-tunnel-task.ps1`
- `ops/windows/dawak-link/index.html`
- `ops/windows/tests/http-concurrency.js`، `ops/windows/tests/mock-github.cjs`
- `docs/ops-runbook.md`، `docs/report-infra-apache-tunnel.md`

لا كود تطبيق ولا أسرار: لا توكن، ولا `.env`، ولا `php.ini`.
