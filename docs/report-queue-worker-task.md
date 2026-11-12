# تقرير التنفيذ: عامل الطوابير كمهمة مجدولة ذاتية الاستعادة

المواصفة: `prompt-queue-worker-task.md`. التاريخ: 2026-11-12 (مساءً، 18:55–19:15).

## الخلاصة

| البند | الحالة |
|---|---|
| 1) المشرف `ops/windows/queue-worker-supervisor.ps1` | ✅ منفّذ ومختبر على `dawak_perf` |
| 2) `install-queue-worker-task.ps1` / `uninstall-queue-worker-task.ps1` | ✅ منفّذان ومختبران، ومنهما المهمة بحساب SYSTEM والتسليم الرشيق |
| 3) `deploy.bat`: `queue:restart` بعد أوامر الكاش | ✅ منفّذ (إنجليزي فقط). **لم أشغّل `deploy.bat` على الحيّة**: لا حاجة، والسلوك نفسه (`queue:restart`) مختبر |
| 4) `docs/ops-runbook.md` | ✅ محدّث |
| 5) التطبيق على الحيّة | ✅ **المهمة `DawakQueueWorker` تعمل الآن** بعد تسليم رشيق. العامل اليدوي القديم خرج وحده بعد ثانيتين، ولم يُقتل شيء |

**الحالة الآن:**
- المهمة `DawakQueueWorker`: Running، والحساب SYSTEM.
- المشرف PID 14352، والعامل PID 5564: `php "…\artisan" queue:work --timeout=600 --sleep=3 --max-time=3600 --memory=256`، ومتصل بـ`dawak_db_dev`.
- لا عامل قديم باقٍ، **فلا شيء يحتاج إغلاقاً يدوياً.**
- **تنبيه لمجد:** طرفية VS Code التي شغّلت فيها العامل اليدوي (PID 10720، 18:50) ستُظهر أن الأمر انتهى. هذا متوقع (خروج هادئ بـ`queue:restart`). **لا تشغّله من جديد.**

---

## الطوابير المسمّاة: لا توجد

- بحثت عن `onQueue(` و`->onQueue` و`$queue` و`onConnection` بكل المشروع (خارج `vendor`): **0 نتيجة**.
- المهام خمس، كلها `ShouldQueue` بلا طابور مسمّى:
  - `ProcessInventoryUploadJob`
  - `ProcessInventoryStockUploadJob`
  - `ProcessSaveMappingJob`
  - `ProcessShortagesImportJob`
  - `ProcessSaveShortagesMappingJob`
- كلها تُرسَل بـ`::dispatch()` من `InventoryController`، وفي كل منها `$timeout = 600` و`$tries = 1`.
- `config/queue.php`: الاتصال `database` والطابور `env('DB_QUEUE','default')`. ولا `DB_QUEUE` بـ`.env`.
- ⇒ **لم أضف `--queue=`**. الوسائط هي الحالية (`--timeout=600`، والطابور الافتراضي، و`--tries` من الـJob) مضافاً إليها `--sleep=3 --max-time=3600 --memory=256`.

---

## ما نُفِّذ

### 1) المشرف `ops/windows/queue-worker-supervisor.ps1`

- **الأسلوب:** نفس أسلوب `tunnel-supervisor.ps1`: PowerShell 5.1 بلا اعتماديات، وجدول `$Config` أعلى الملف، وسجل `C:\dawak-queue\worker.log` يُدوَّر عند 5MB إلى `.1`.
- **الحلقة:**
  1. ينتظر قبول `127.0.0.1:3306` للاتصال (TCP بمهلة 3 ثوانٍ، وإعادة كل 10 ثوانٍ، وسطر WARN كل ~دقيقة).
  2. يشغّل العامل مخفياً، ويحفظ PID بـ`C:\dawak-queue\worker.pid`.
  3. ينقل خرج العامل (stdout/stderr) سطراً سطراً إلى السجل بالبادئة `worker:` و`worker-err:`.
- **عند الخروج:** خروج بأي رمز ⇒ 5 ثوانٍ ثم تشغيل جديد. 5 خروجات متتالية، كل منها خلال أقل من 20 ثانية من البدء ⇒ سطر `ERROR` وتأخير 10 ← 20 ← 40 ← 80 ← 120 ثانية (الحد). أي تشغيل يدوم 20 ثانية فأكثر يصفّر العدّاد.
- **القفل:** `Global\DawakQueueWorker`. نسخة ثانية ⇒ `ERROR … already running` ورمز الخروج 3.
- **التنظيف:** يوقف فقط عامله:
  - PID من الملف، **و**`php.exe`، **و**سطر أوامره يحوي `queue:work` **ومسار `artisan` الكامل**.
  - العامل اليدوي يُشغَّل بـ`artisan` نسبي، فلا يطابق أبداً.
  - لا يلمس أي `php.exe` آخر (8000 و8012 و8020 و`artisan serve`).
- **اليتيم** (بعد `schtasks /End` أو قتل المشرف): عند البدء، إن كان عامله المسجَّل ما زال حياً **وبمنتصف مهمة** (آخر سطر بخرجه `RUNNING` بلا `DONE`/`FAIL`)، ينتظر انتهاء المهمة حتى 12 دقيقة، ثم يوقفه. لا يقطع استيراداً جارياً.
- **الأسرار:** لا يمرّر أي سر. العامل يقرأ كاش الإعدادات (`bootstrap/cache/config.php`).
- **مفاتيح الاختبار:** `-MaxTime` و`-WorkDir`/`-LogPath` و`-DbPort` و`-WorkerArgs` و`-FastExitSec` و`-TestDatabase dawak_perf`.
  - `-TestDatabase` يرفض أي قاعدة غير `dawak_perf`.
  - يضبط لعملية العامل وحدها `DB_DATABASE` و`APP_CONFIG_CACHE` (ملف غير موجود، أي بلا كاش إعدادات) و`LOG_CHANNEL=stderr`، فلا يُكتب شيء بسجل التطبيق الحيّ.

### 2) التثبيت والإزالة

**`install-queue-worker-task.ps1`:**
- مهمة `DawakQueueWorker`:
  - الحساب SYSTEM (`ServiceAccount`، `Highest`)، وتعمل عند الإقلاع بعد **60 ثانية**.
  - مخفية، وبلا حد زمني (`PT0S`)، وتُعاد عند الفشل كل دقيقة (999 مرة)، و`IgnoreNew`.
  - مجلد العمل هو مجلد المشروع، والسكربت آمن لإعادة التشغيل (`-Force`).
- ينشئ `C:\dawak-queue\`.

**`-StartNow` (التسليم الرشيق):**
1. إن كانت المهمة تعمل فلا يفعل شيئاً (لا عمال مكررون).
2. يطبع كل عامل `queue:work` غير مُدار: PID ووقت البدء وسطر الأوامر و**قاعدة بياناته**.
   - القاعدة تُعرف من اتصاله بالمنفذ 3306 مقابل `information_schema.PROCESSLIST`، بأفضل جهد.
   - العامل الذي يثبت أنه على قاعدة أخرى (تطبيق آخر) يُستثنى: `queue:restart` لا يؤثر فيه، ولا يُلمس.
3. `php artisan queue:restart`.
4. ينتظر اختفاء العمال القدامى حتى **720 ثانية**، ويطبع ما ينتظره كل 30 ثانية.
5. إن بقي عامل قديم: **لا يقتله ولا يبدأ المهمة** (رمز الخروج 5)، ويطبع الأمر الذي يُنفَّذ بعد إغلاقه يدوياً.

**`uninstall-queue-worker-task.ps1 [-StopOnly] [-Force]`:**
1. يوقف المهمة (ويزيل تسجيلها إلا مع `-StopOnly`) ويوقف المشرف.
2. ثم **ينتظر** أن ينهي العامل المُدار مهمته الجارية (حتى 12 دقيقة)، ثم يوقفه.
3. لا يلمس العامل اليدوي.
4. `-Force` يتخطى الانتظار.

### 3) `deploy.bat`
- أُضيف `php artisan queue:restart` **بعد** `view:cache`، أي بعد `optimize:clear` الذي يمسح الكاش ومعه إشارة إعادة التشغيل.
- عند فشله يطبع تحذيراً فقط ولا يفشل النشر.
- الملف ما زال ASCII فقط (`file`: `ASCII text`).

### 4) `docs/ops-runbook.md`
- صف `DawakQueueWorker` بجدول المكوّنات.
- قسم العامل كاملاً: الحالة والتشغيل والإيقاف والإزالة والسجل والسلوك، مع تحذير «لا تشغّله يدوياً».
- `deploy.bat` يعيد تشغيله.
- بند قائمة «بعد إعادة الإقلاع» صار فحصاً لا تشغيلاً يدوياً.

---

## الاختبارات (على `dawak_perf` فقط)

**العزل:**
- كل عمليات الاختبار بمتغيرات بيئة لها وحدها: `DB_DATABASE=dawak_perf`، و`APP_CONFIG_CACHE` لملف غير موجود (وإلا لتجاهل Laravel `DB_DATABASE` بسبب كاش الإعدادات)، و`LOG_CHANNEL=stderr`.
- تحققت قبل البدء: `tinker` ⇒ `dawak_perf database nocache`.
- `queue:restart` على هذه البيئة يكتب بجدول `cache` في `dawak_perf`، فلم يتأثر العامل الحيّ 10720 بأي اختبار (بقي حياً حتى التسليم).

**مهمة الاختبار:**
- سكربت مؤقت خارج المستودع، بمجلد scratchpad، يرسل مهمة closure تكتب ملف علامة فيه اسم القاعدة وPID العامل.
- يرفض الإرسال إن لم تكن القاعدة `dawak_perf`.
- **لم يُضَف أي ملف لكود التطبيق.**

| # | الاختبار | النتيجة |
|---|---|---|
| 1 | المشرف بـ`--max-time=15` | ✅ العامل يتجدّد تلقائياً: `worker exited (code 0) after 16.6s; restarting in 5s` ثم `worker started` بـPID جديد، 3 دورات متطابقة خلال 50 ثانية |
| 2 | مهمة تجريبية | ✅ `processed on dawak_perf … pid 16120` (PID العامل المُدار). بالسجل `RUNNING` ثم `2s DONE`. بعدها `jobs = 0` و`failed_jobs = 0` |
| 3 | قتل العامل بالقوة (`Stop-Process -Force`) | ✅ `worker exited (code -1)` ثم `worker started` بعد **6.4 ثانية** من القتل |
| 4أ | منفذ قاعدة لا يرد (3399) | ✅ ينتظر: سطر `WARN … not accepting connections` كل ~72 ثانية، و**0 عامل**، والمعالج 0.7 ثانية فقط خلال 75 ثانية |
| 4ب | المنفذ يصير متاحاً بعد ~15 ثانية | ✅ `database ready after 24s` ثم `worker started` |
| 4ج | عامل ينهار فوراً (وسيط خاطئ عمداً `--no-such-option`) | ✅ 4 إعادات كل 5 ثوانٍ، ثم `ERROR … 5 fast exits in a row (< 20s each): backing off 10s`، ثم 20 ← 40 ← 80 (الحد 120). سبب الانهيار ظاهر بالسجل (`worker-err:`) |
| 5أ | نسخة ثانية من المشرف | ✅ `ERROR another queue worker supervisor instance is already running - exiting`، رمز 3، ولم يُنشأ عامل إضافي |
| 5ب | إيقاف المشرف بالسكربت (`uninstall … -StopOnly`) | ✅ `stopped supervisor` ثم `stopped managed worker`، ولا عامل يتيم (بقي فقط الحيّ 10720) |
| 5ج | قتل المشرف بالقوة **أثناء مهمة 25 ثانية** ثم تشغيله | ✅ اليتيم أكمل مهمته (`processed … pid 11892`). المشرف الجديد كتب `orphan worker pid 11892 is in the middle of a job - waiting`، ثم بدأ عاملاً جديداً بعد انتهائها. لا عاملان معاً |
| 6 | `queue:restart` (كما يفعل `deploy.bat`) | ✅ `worker exited (code 0)` ثم `worker started (pid 12720)` بعد 5 ثوانٍ. مهمة جديدة عولجت بالعامل الجديد. **العامل الحيّ 10720 لم يتأثر** |
| 7أ | المهمة المجدولة بحساب SYSTEM (اسم اختبار `DawakQueueWorkerTest` + `-TestDatabase dawak_perf`) | ✅ المشرف والعامل يعملان كـ`NT AUTHORITY\SYSTEM` (بالسجل `user PURCHASE10$`)، ومهمة عولجت `30ms DONE` |
| 7ب | تسليم رشيق `-StartNow` من عامل «يدوي» على `dawak_perf` مشغول بمهمة 40 ثانية | ✅ الحيّ 10720 عُرف بأنه `db=dawak_db_dev` فاستُثني ولم يُلمس. أما عامل الاختبار: `queue:restart` ثم `waiting … pid 3260`، **فأكمل مهمته `40s DONE`** وخرج وحده، و`all old workers exited after 28s` ثم بدأت المهمة |
| 7ج | `schtasks /End` أثناء مهمة 30 ثانية ثم `/Run` | ✅ `/End` قتل المشرف فقط، والعامل أكمل المهمة. `/Run` انتظر انتهاءها ثم `stopped own worker` و`worker started` |
| 7د | `uninstall` أثناء مهمة 20 ثانية | ✅ `waiting for it to finish` ثم `stopped managed worker` بعد 18 ثانية، والمهمة اكتملت. أُزيلت المهمة التجريبية |

**بنهاية الاختبارات** على `dawak_perf`: `jobs = 0` و`failed_jobs = 0`.

### الخطوة الأخيرة: الحيّة

1. **تثبيت بلا `-StartNow`:**
   - المهمة `DawakQueueWorker`: `Ready`، و`Run As User: SYSTEM`، و`Logon Mode: Interactive/Background`.
   - Trigger delay `PT1M`، و`IgnoreNew`، و`999 × PT1M`، و`PT0S`، و`Hidden`.
   - `Start In` = مجلد المشروع.
2. **الفحص المسبق على `dawak_db_dev` (19:13:33):**
   - `jobs = 0`، و`reserved = 0`، و`failed_jobs = 0`.
   - `import_logs` بحالة `processing` = 0، و`import_sessions` بحالة `processing` خلال 24 ساعة = 0.
   - آخر نشاط استيراد: 07:51 صباحاً، وآخر سجل (#97) بحالة `awaiting_action` (ينتظر إجراءً من المستخدم، لا معالجة).
   - ⇒ الشروط متحققة.
3. **`-StartNow`:** أعاد السكربت التحقق من `jobs = 0` لحظة التنفيذ، ثم:
   ```
   [queue-task] this app's database: dawak_db_dev
   [queue-task] old worker pid 10720  started 12/11/2026 6:50:24 PM  db=dawak_db_dev  cmd: "C:\xampp\php\php.exe" artisan queue:work --timeout=600
   [queue-task] graceful handover: php artisan queue:restart (each worker exits AFTER its current job; nothing is killed)
   [queue-task] all old workers exited after 2s
   [queue-task] DawakQueueWorker started. Log: C:\dawak-queue\worker.log
   ```
4. **التحقق:**
   - المهمة Running بحساب SYSTEM.
   - `worker.log` فيه `supervisor start (… user PURCHASE10$ …)` ثم `worker started (pid 5564)`.
   - العامل متصل بـ`dawak_db_dev`.
   - لا يوجد غير عامل `queue:work` واحد.
   - `DawakTunnel` وApacheDawak وApache2.4 ما زالت Running، ولم ألمسها.
5. **لم أرسل أي مهمة للحيّة.** أول استيراد حقيقي سيظهر بالسجل بسطري `RUNNING`/`DONE`.

**حالة العمال القدامى:**
- 10720 (اليدوي من VS Code) خرج برشاقة: كان خاملاً، فخرج خلال ثانيتين من `queue:restart`.
- لا عامل مخفي آخر لهذا التطبيق، و**لا شيء يحتاج إغلاقاً يدوياً**.

---

## القرارات الصغيرة

1. **تمييز العامل المُدار بمسار `artisan` الكامل:** المشرف يشغّل `php "C:\…\artisan" queue:work …`، فيفرّقه أي سكربت عن العامل اليدوي (`php artisan …`) دون أي حالة إضافية.
2. **اليتيم المشغول لا يُقتل فوراً:** قراءة آخر سطر `RUNNING`/`DONE`/`FAIL` من خرجه (مقيس: Laravel يكتبهما فوراً للملف). هذا يحقق «لا تقطع استيراداً جارياً» حتى بعد `schtasks /End`.
3. **عند بقاء عامل قديم بعد 12 دقيقة لا تبدأ المهمة:** عاملان معاً على طابور `database` مع `retry_after=90` و`--timeout=600` قد يجعلان الثاني يلتقط استيراداً ما زال يعمل منذ أكثر من 90 ثانية، فيعلّمه فاشلاً (`tries=1`). انظر التحذير 1.
4. **تمييز عمال التطبيقات الأخرى بقاعدة بياناتهم** (اتصال 3306 ⇐ `PROCESSLIST`)، كي لا ينتظر التسليم 12 دقيقة عاملاً لا يخصنا. إن تعذّر ذلك (لا `mysql.exe` أو رفض الاتصال) يُعامَل كل عامل غير مُدار كعامل لنا، أي ينتظره، وهو الأحوط.
5. **`-StopOnly` بسكربت الإزالة** بدل سكربت ثالث: الإيقاف الآمن يحتاج نفس منطق الانتظار.
6. **تنبيه العامل الأجنبي** يُكتب مرة عند كل تغيّر، لا كل دورة.
7. **التأخير المتزايد** يبدأ من 10 ثوانٍ عند الخروج السريع الخامس ويتضاعف حتى 120. الخروجات الأربعة الأولى بـ5 ثوانٍ كالمعتاد.
8. **مهلة الإقلاع 60 ثانية** (لا 30 كالنفق) كما طلبت المواصفة، والمشرف ينتظر منفذ القاعدة على أي حال.

---

## التحذيرات

1. **`retry_after=90` أقل من `--timeout=600`:** قيمة Laravel الافتراضية بـ`config/queue.php`.
   - مع عامل واحد لا خطر.
   - مع عاملين (مثلاً لو شغّل أحد `queue:work` يدوياً والمهمة تعمل) قد تُعالَج مهمة استيراد طويلة مرتين، أو تُعلَّم فاشلة.
   - المشرف يكتب `WARN other queue:work process(es) running` إن رأى عاملاً يدوياً.
   - **التوصية** (قرار مجد، فتعديل `.env` ممنوع عليّ): `DB_QUEUE_RETRY_AFTER=660` بـ`.env` ثم `ops\windows\deploy.bat`.
2. **`--timeout=600` لا يُطبَّق فعلياً على Windows:** يحتاج امتداد `pcntl`، وهو غير متاح على Windows. هذا كان قائماً أصلاً مع العامل اليدوي، ولم أغيّر شيئاً. فمهمة عالقة لن تُقتل بعد 10 دقائق.
   - المشرف ينتظر يتيماً مشغولاً 12 دقيقة ثم يوقفه. فإن استغرق استيراد أكثر من 12 دقيقة **و**أُوقف المشرف بالقوة أثناءه، فسيُقطع.
   - `uninstall` لا يقطعه بلا `-Force`.
3. **نافذة ميلي ثانية عند الإيقاف:** إن أُوقف العامل الخامل لحظة حجزه مهمة جديدة، قبل أن يطبع `RUNNING`، تبقى المهمة محجوزة. ستعود بعد 90 ثانية، وتُعلَّم فاشلة لأن `tries=1`. احتمال ضئيل جداً، وهو موجود أيضاً عند إغلاق أي عامل يدوي.
4. **`deploy.bat` الآن يعيد تشغيل العامل** بعد مهمته الجارية. إن شُغّل أثناء استيراد فلن يُقطع: يكمل ثم يعود بالكود الجديد.
5. **`C:\dawak-queue-test\`** (سجلات المهمة التجريبية) **بقي**: منع نظام الأذونات حذفه من جلستي. لا أثر له، ويمكن حذفه يدوياً.

---

## الأوامر

(PowerShell كمسؤول بمجلد المشروع، والتفاصيل بـ`docs/ops-runbook.md`.)

| الغرض | الأمر |
|---|---|
| الحالة | `schtasks /Query /TN DawakQueueWorker /V /FO LIST` |
| السجل | `Get-Content C:\dawak-queue\worker.log -Tail 30` (أو `-Wait`) |
| تشغيل | `schtasks /Run /TN DawakQueueWorker` |
| إيقاف برشاقة (تبقى مسجّلة) | `powershell -ExecutionPolicy Bypass -File ops\windows\uninstall-queue-worker-task.ps1 -StopOnly` |
| إعادة تشغيل العامل | `php artisan queue:restart` (أو `ops\windows\deploy.bat`) |
| إزالة | `powershell -ExecutionPolicy Bypass -File ops\windows\uninstall-queue-worker-task.ps1` |
| تثبيت/تحديث + تسليم رشيق | `powershell -ExecutionPolicy Bypass -File ops\windows\install-queue-worker-task.ps1 -StartNow` |

**الرجوع للوضع اليدوي:** سكربت الإزالة، ثم `php artisan queue:work --timeout=600` من طرفية كما كان.

## اختبار الإقلاع (لمجد، لم أُعِد تشغيل الجهاز)

1. بوقت مناسب (لا استيراد جارياً): أعد تشغيل الجهاز، ولا داعي لتسجيل الدخول.
2. بعد ~2 دقيقة سجّل الدخول ونفّذ:
   ```powershell
   Get-Service mysql, ApacheDawak                          # Running
   schtasks /Query /TN DawakQueueWorker /V /FO LIST        # Status: Running, Run As User: SYSTEM
   Get-Content C:\dawak-queue\worker.log -Tail 10          # supervisor start … ثم worker started بوقت بعد الإقلاع
   Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object CommandLine -like '*queue:work*' | Select-Object ProcessId,CommandLine   # عامل واحد، سطره يحوي …\artisan
   ```
   - إن تأخرت MariaDB فسترى `database … not accepting connections - waiting` ثم `database ready after Ns`. هذا طبيعي.
3. ارفع ملف استيراد صغيراً من التطبيق، وتأكد أنه اكتمل، وأن السجل فيه `RUNNING` ثم `DONE`.
4. **لا تشغّل `php artisan queue:work` يدوياً بعد الآن.**

## ملفات المستودع (هذا الـcommit)

- `ops/windows/queue-worker-supervisor.ps1` (جديد)
- `ops/windows/install-queue-worker-task.ps1`، `ops/windows/uninstall-queue-worker-task.ps1` (جديدان)
- `ops/windows/deploy.bat`
- `docs/ops-runbook.md`
- `docs/report-queue-worker-task.md`

لا كود تطبيق، ولا `.env`، ولا `php.ini`، ولا أسرار.
**خارج المستودع:** `C:\dawak-queue\` (السجل وPID) والمهمة `DawakQueueWorker`.
