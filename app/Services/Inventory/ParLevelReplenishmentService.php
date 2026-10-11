<?php
// app/Services/Inventory/ParLevelReplenishmentService.php

namespace App\Services\Inventory;

use App\Enums\ShortageSource;
use App\Enums\ShortageStatus;
use App\Models\CentralInventory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMinimumStock;
use App\Models\Shortage;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 ================= المحرك الجديد لعملية "المناقلات" =================
 * يستبدل بالكامل الاعتماد القديم على "كمية المبيعات" كأساس لتحديد ماذا
 * يُشحَن لكل صيدلية. الأساس الجديد (بقرار صريح من صاحب المشروع):
 *
 *   الحاجة = المخزون القياسي المحدَّد للصيدلية (pharmacy_minimum_stocks)
 *            − الرصيد الحالي الفعلي لهذه الصيدلية (pharmacy_inventories)
 *
 * الهدف: "الحفاظ على المخزون القياسي دون زيادة أو نقصان" — لا نشحن أكثر
 * من الفجوة (لا زيادة)، ونحاول تغطية أكبر جزء ممكن منها فوراً من المركزي
 * (لا نقصان غير مبرَّر)، وما تعذّر تغطيته الآن يبقى معلَّقاً بجدول shortages.
 *
 * 🆕 ============ إضافتان (2026-08، الجولة الثالثة) ============
 *
 * 1) min_reorder_gap (حد أدنى للفرق قبل الترميم): لو الفجوة الخام
 *    (rawGap = المخزون القياسي − الرصيد الحالي) أصغر من أو تساوي هذا
 *    الحد المحدَّد لهذا الزوج (صيدلية+مادة)، نتجاهل الفجوة تماماً هذه
 *    الدفعة — لا شحن، ولا نلمس أي نقص معلَّق سابق. الهدف: تفادي تحريك
 *    شحنات لأجل فروقات تافهة (مثلاً وحدة أو وحدتين) تتكرر يومياً.
 *
 * 2) pack_size (حجم العبوة): بعد اجتياز عتبة (1)، نُقرِّب الفجوة الناتجة
 *    للأسفل لأقرب مضاعف لحجم عبوة المادة — فلا نشحن كسر عبوة (مثال:
 *    سيرنغ يُباع مفردة، pack_size=100 → فجوة 50 تُصبح فجوة فعّالة 0،
 *    فلا شحن حتى تصل الفجوة الخام لعبوة كاملة). القيمة 0 تعني "بلا قيد
 *    تعبئة" فتبقى الفجوة كما هي بلا تقريب (السلوك القديم تماماً).
 *
 * ⚠️ نقطة أمان جوهرية: التفريق بين "الفجوة الخام = صفر" (الصيدلية عند
 * أو فوق المخزون القياسي فعلياً → نُغلق أي نقص معلَّق) وبين "الفجوة
 * الخام موجودة لكنها دون العتبة أو دون عبوة كاملة" (حاجة حقيقية، لكن
 * غير قابلة للتنفيذ الآن → نتجاهلها بصمت، بلا أي تعديل على أي سجل نقص
 * موجود مسبقاً). الخلط بين الحالتين كان سيُغلق نواقص حقيقية بالخطأ لمجرد
 * أنها أصغر من عبوة واحدة.
 *
 * 🆕 ============ إضافات (2026-09، جولة نظام الاستراتيجيات) ============
 *
 * 3) source: كل سجل نقص يُنشأ أو يُحدَّث من هنا يُوسَم صراحة
 *    ShortageSource::StandardInventory — بما في ذلك عند البحث عن نقص
 *    معلَّق سابق لنفس الزوج (existingPendingShortages مقيَّدة بالمصدر
 *    أيضاً الآن) — لأن الوضع الهجين (Hybrid) قد يُشغِّل هذه الخدمة و
 *    SalesBasedRestockingStrategy معاً؛ بلا هذا القيد، كان محرك المخزون
 *    القياسي قد "يستولي" خطأً على نقص أنشأته استراتيجية المبيعات لنفس
 *    الزوج بنفس الدفعة، ويستبدل قيمته بدل تركه لمنطق التجميع الخاص بها.
 *
 * 4) pack_size_override: حجم العبوة يُحسَب الآن عبر
 *    PharmacyMinimumStock::effectivePackSize() — تجاوز خاص بهذه الصيدلية
 *    إن وُجد بعمود pack_size_override، وإلا products.pack_size كالمعتاد.
 *
 * 5) "استبدال كامل" حقيقي: الحلقة الأساسية بالأسفل تستبدل قيمة أي نقص
 *    مُقيَّم **بهذه الدفعة تحديداً** (سلوك موجود أصلاً). لكن لو صيدلية ما
 *    فقدت معيارها القياسي بالكامل لصنف معيَّن (حُذف سطره من
 *    pharmacy_minimum_stocks) بين رفعة وأخرى، ذلك الصنف لن يظهر إطلاقاً
 *    ضمن $standards هذه المرة، فتبقى أي نقص قديم له معلَّقاً للأبد بلا
 *    داعٍ — يتعارض مع طلب "استبدال كامل لكل رفعة". الحل: بعد الحلقة
 *    الأساسية، نبحث عن أي نقص (مصدره standard_inventory) معلَّق لصيدليات
 *    هذه الدفعة **لم يظهر إطلاقاً** ضمن الأزواج المُقيَّمة هذه المرة،
 *    ونُغلقه صراحة. استعلام إضافي واحد فقط (لا حلقة استعلامات لكل صيدلية).
 *
 * 🆕 ============ إضافة (2026-09، تصالح "الأكبر لا الجمع") ============
 *
 * 6) reconcileAgainst: عند التشغيل ضمن HybridRestockingStrategy (وحصراً
 *    عندها)، تُطرَح أي كمية شحنتها استراتيجية المبيعات بالفعل لنفس الزوج
 *    بنفس هذه الدفعة، من الفجوة الخام، قبل أي تقييم. بقرار صريح من صاحب
 *    المشروع: رصيد الصيدلية المرفوع (current_stock) هو دائماً لقطة "بعد"
 *    مبيعات اليوم — أي أن الفجوة الخام (المخزون القياسي − الرصيد الحالي)
 *    **تتضمن أصلاً** أثر تلك المبيعات. لولا هذا الطرح، كانت نفس الوحدات
 *    ستُشحَن مرتين: مرة بفاتورة المبيعات، ومرة أخرى هنا كفجوة "جديدة" —
 *    ازدواج حسابي صريح، لا خطأ تافه. الناتج النهائي الآن مطابق حسابياً
 *    لـ max(الفجوة القياسية، طلب المبيعات) لنفس الزوج، لا لمجموعهما.
 *
 * 🎓 لماذا الطرح هنا (بالمخزون القياسي، الاستراتيجية الثانية) لا العكس؟
 * لأن Hybrid يُشغِّل المبيعات أولاً دائماً (ترتيب مُصمَّم صراحة — راجع
 * HybridRestockingStrategy) — بحلول وقت تشغيل هذه الخدمة، الشحن الفعلي
 * للمبيعات قد تمّ وانتهى بالفعل، فتصبح "حقيقة ماضية" يمكن البناء عليها،
 * لا تخميناً. جعل المبيعات "تعرف" عمّا ستشحنه استراتيجية لم تُشغَّل بعد
 * كان سيتطلب قلب الترتيب أو تنسيقاً أعقد بلا داعٍ حقيقي.
 *
 * 🆕 ============ إضافة (2026-09، المناقلات العكسية) ============
 *
 * 7) لو رصيد الصيدلية الفعلي **يتجاوز** مخزونها القياسي (لا مجرد يبلغه)
 *    بأكثر من min_reorder_gap — نفس عمود التسامح المُستخدَم للاتجاه
 *    الأمامي، بقرار صريح من صاحب المشروع أنه نطاق تسامح متماثل حول
 *    الهدف لا سقفاً أحادي الاتجاه — يُسجَّل "مناقلة عكسية" (من الصيدلية
 *    للمستودع)، بنفس منطق تقريب حجم العبوة المُطبَّق بالاتجاه الأمامي
 *    تماماً. 🆕 (ب.6) تُسجَّل الآن كـ"مقترحة" فقط — لا زيادة لـ central_inventories
 *    عند التسجيل (كانت قناة تسرّب الفائض لفواتير النواقص، ب.5)؛ الأثر يحدث بالتنفيذ
 *    الصريح عبر TransferExecutionService. راجع ReverseTransferRecorderService.
 *
 * ⚠️ الفائض يُحسَب من $physicalExcess (الأرقام الخام)، لا من $rawGap
 * بعد تصالح "الأكبر لا الجمع" أعلاه — الفائض حقيقة مادية بحتة، لا علاقة
 * لها بما شحنته استراتيجية المبيعات بنفس الدفعة. راجع التعليق عند تعريف
 * $physicalExcess بالحلقة أدناه للسبب الكامل.
 *
 * 🎓 لماذا لا نكتفي باستدعاء ShortageFulfillmentService الموجودة أصلاً؟
 * لأن تلك الخدمة **لا تُنشئ** نقصاً جديداً — فقط "تُرمِّم" نقصاً موجوداً
 * سلفاً بجدول shortages (وتُصدر فواتير "إلحاقية" supplementary_invoice
 * لا تُحتسَب عمداً ضمن معدل استهلاك المشتريات — راجع تعليق
 * ConsumptionRateCalculator بالضبط). هذه الخدمة هي المسؤولة عن "الخطوة
 * الناقصة": تحويل مقارنة (رصيد الصيدلية ↔ المخزون القياسي) إلى حاجة
 * فعلية لأول مرة، ومحاولة تلبيتها فوراً بفاتورة "أساسية" (processed) —
 * تماماً كما كانت فاتورة المبيعات اليومية القديمة تُصنَّف "أساسية"، لكن
 * مصدر الكمية الآن هو الفجوة، وليس المبيعات. أي جزء يتعذّر تلبيته فوراً
 * يُسجَّل بجدول shortages **بالقيمة الصحيحة الجديدة (استبدال لا جمع)**
 * — لأن هذه قراءة ميدانية جديدة تُلغي أي قيمة قديمة مخزَّنة — وتلتقطها
 * ShortageFulfillmentService لاحقاً (بفاتورة إلحاقية) فور توفّر مخزون
 * مركزي جديد، تماماً كما كانت تعمل من قبل، بلا أي تعديل عليها.
 *
 * ⚠️ شرط أساسي (نفس شرط ملف جرد المستودع المركزي تماماً): يُفترَض أن
 * ملف جرد الصيدليات المرفوع لكل صيدلية هو **لقطة كاملة** لكل موادها
 * (وليس جزئياً) — هذا هو السلوك الموثَّق أصلاً بـ PharmacyInventoryUploadService
 * ("استبدال كامل لكل الصيدليات المذكورة بالملف"). أي مادة غير مذكورة
 * لصيدلية مذكورة بالملف تُعتبر رصيدها صفراً بعد الرفع.
 * =====================================================================
 */
/**
 * 🎯 (نفس التوثيق الكامل الأصلي بالضبط — لم يتغيّر شيء منه، محذوف هنا
 * فقط لتوفير المساحة، أعِد لصقه من نسختك الحالية كما هو حرفياً).
 *
 * 🆕 ============ تحسين أداء (بلا أي تغيير بمنطق القرار) ============
 * كل كتابة لجدول shortages (إنشاء/تحديث) كانت تُنفَّذ كاستعلام منفصل
 * فور اتخاذ القرار داخل الحلقة — مع آلاف الأزواج المُقيَّمة (6 صيدليات ×
 * آلاف المواد)، هذا يعني آلاف استعلامات الكتابة الفردية. الحل: تأجيل كل
 * قرار كتابة لمصفوفة بالذاكرة أثناء الحلقة (بلا أي كلفة إضافية)، وتنفيذ
 * الكتابة الفعلية دفعة واحدة (INSERT مجمَّع، وUPSERT مجمَّع بمعرِّفات
 * معروفة) بعد اكتمال كل القرارات — القرار نفسه لم يتغيّر حرفاً واحداً،
 * فقط توقيت تنفيذه على القرص.
 */
class ParLevelReplenishmentService
{
    public function __construct(
        private readonly ReverseTransferRecorderService $reverseTransferRecorder,
        // 🩹 [استخراج] كانت هذه الرياضيات (الفجوة، الفائض، تقريب العبوة، تسامح
        // min_reorder_gap) مكتوبة مباشرة داخل الحلقة أدناه. أُخرجت إلى
        // ParLevelCalculator (كلاس نقي، بلا Eloquent ولا قاعدة بيانات) لأن الوضع
        // الرابع القادم يحتاج **نفس الحساب بالضبط** لمجموعة فرعية مختلفة من
        // الأزواج — راجع تعليق ParLevelCalculator نفسه للسبب الكامل. لا تغيير
        // بأي قرار هنا، فقط بمكان كتابته.
        private readonly ParLevelCalculator $calculator = new ParLevelCalculator(),
    ) {}

    public function replenish(array $pharmacyIds, array $excludedCompanies = [], array $reconcileAgainst = []): RestockingResult
    {
        if (empty($pharmacyIds)) {
            return RestockingResult::empty(ShortageSource::StandardInventory);
        }

        $standards = PharmacyMinimumStock::whereIn('pharmacy_id', $pharmacyIds)
            // 🩹 min_reorder_gap مُضاف للتحديد هنا أيضاً — بدونه effectiveMinReorderGap()
            // كان سيُطلق استعلاماً إضافياً لكل زوج (N+1) لجلب $this->product من جديد،
            // رغم أن العلاقة محمَّلة أصلاً؛ Eloquent لا يُعيد تحميل عمود غائب عن select تلقائياً.
            ->with('product:id,company,pack_size,min_reorder_gap')
            ->get();

        if (!empty($excludedCompanies)) {
            $standards = $standards->reject(
                fn(PharmacyMinimumStock $s) => in_array($s->product->company ?? null, $excludedCompanies, true)
            );
        }

        if ($standards->isEmpty()) {
            return RestockingResult::empty(ShortageSource::StandardInventory);
        }

        $pairsEvaluated = $standards->count();
        $invoicesCreated = 0;
        $fulfilledCount = 0;
        $partialCount = 0;
        $shortagesRecorded = 0;
        $shortagesResolved = 0;
        $reverseTransfersCreated = 0;
        $perPairSupplied = [];
        $reverseTransferSourcesByProduct = []; // 🆕 أضِف هذا السطر هنا
        // 🆕 [تشخيص] فجوة حقيقية موجودة لكنها ستُقمَع بصمت (تسامح أو حجم عبوة) —
        // راجع تعليق RestockingResult::$skippedGapBelowTolerance للفلسفة الكاملة.
        $skippedGapBelowTolerance = 0;
        $skippedGapBelowPackSize = 0;
        DB::transaction(function () use (
            $standards,
            $pharmacyIds,
            $reconcileAgainst,
            &$invoicesCreated,
            &$fulfilledCount,
            &$partialCount,
            &$shortagesRecorded,
            &$shortagesResolved,
            &$reverseTransfersCreated,
            &$perPairSupplied,
            &$reverseTransferSourcesByProduct,
            &$skippedGapBelowTolerance,
            &$skippedGapBelowPackSize
        ) {
            $productIds = $standards->pluck('product_id')->unique()->values();

            $centralStocks = CentralInventory::whereIn('product_id', $productIds)
                ->lockForUpdate()->get()->keyBy('product_id');

            $currentPharmacyStock = PharmacyInventory::whereIn('pharmacy_id', $pharmacyIds)
                ->whereIn('product_id', $productIds)
                ->get()
                ->keyBy(fn(PharmacyInventory $r) => "{$r->pharmacy_id}_{$r->product_id}")
                ->map(fn(PharmacyInventory $r) => $r->quantity);

            $existingPendingShortages = Shortage::pending()
                ->where('source', ShortageSource::StandardInventory)
                ->whereIn('pharmacy_id', $pharmacyIds)
                ->whereIn('product_id', $productIds)
                ->get()
                ->keyBy(fn(Shortage $s) => "{$s->pharmacy_id}_{$s->product_id}");

            $stockRunning = [];
            $invoiceItemsByPharmacy = [];
            $reverseItemsByPharmacy = [];
            $today = now()->toDateString();
            $now = now();

            // 🆕 تأجيل الكتابة — نفس محتوى كل قرار بالضبط، فقط بمصفوفة بدل استعلام فوري
            $shortageInserts = [];
            $shortageUpdates = [];

            foreach ($standards as $standard) {
                $pharmacyId = $standard->pharmacy_id;
                $productId  = $standard->product_id;
                $key        = "{$pharmacyId}_{$productId}";

                if (!isset($currentPharmacyStock[$key])) {
                    continue;
                }
                $current = (int) ($currentPharmacyStock[$key] ?? 0);

                // 🆕 كان الحساب هنا مباشرة (rawGap, physicalExcess, minReorderGap, تقريب
                // العبوة) — انتقل بالكامل إلى ParLevelCalculator::evaluate() بلا أي تغيير
                // بالمنطق نفسه، حرفاً بحرف. راجع تعليق ذلك الكلاس للسبب.
                $decision = $this->calculator->evaluate(
                    standardQuantity: $standard->minimum_quantity,
                    currentStock: $current,
                    minReorderGap: $standard->effectiveMinReorderGap(),
                    packSize: $standard->effectivePackSize(),
                    alreadyShipped: $reconcileAgainst[$key] ?? 0,
                );

                if ($decision->isAtOrAboveStandard) {
                    if ($existing = $existingPendingShortages->get($key)) {
                        $shortageUpdates[] = [
                            'id'         => $existing->id,
                            // 🩹 [إصلاح جوهري] pharmacy_id/product_id/source مطلوبة هنا رغم
                            // أنها لن تتغيّر فعلياً — راجع تعليق الملف الكامل للسبب التقني الدقيق
                            // (نفس الإصلاح المطابق بـ SalesGatedStandardInventoryRestockingStrategy).
                            'pharmacy_id' => $existing->pharmacy_id,
                            'product_id' => $existing->product_id,
                            'source'     => $existing->source->value,
                            'quantity'   => 0,
                            'status'     => ShortageStatus::Fulfilled->value,
                            'notes'      => $existing->appendResolutionNote(
                                "أُغلق تلقائياً: رصيد الصيدلية بلغ أو تجاوز المخزون القياسي عند جرد {$today}."
                            ),
                            'updated_at' => $now,
                        ];
                        $shortagesResolved++;
                    }

                    if ($decision->needsReverseTransfer()) {
                        $reverseItemsByPharmacy[$pharmacyId][] = [
                            'product_id'                 => $productId,
                            'quantity'                    => $decision->effectiveExcess,
                            'standard_quantity_at_time' => $standard->minimum_quantity,
                            'pharmacy_quantity_at_time' => $current,
                        ];
                    }

                    continue;
                }

                if ($decision->gapBelowThreshold) {
                    $skippedGapBelowTolerance++;
                    continue;
                }

                $effectiveGap = $decision->effectiveGap;

                if ($effectiveGap <= 0) {
                    $skippedGapBelowPackSize++;
                    continue;
                }

                $availableCentral = $stockRunning[$productId] ?? (int) ($centralStocks->get($productId)?->quantity ?? 0);

                $allocation = $this->calculator->allocate($effectiveGap, $availableCentral, $standard->effectivePackSize());
                $suppliedNow = $allocation->suppliedNow;
                $remainingGap = $allocation->remainingGap;
                $stockRunning[$productId] = $availableCentral - $suppliedNow;

                if ($suppliedNow > 0) {
                    $invoiceItemsByPharmacy[$pharmacyId][] = [
                        'product_id'    => $productId,
                        'requested_qty' => $effectiveGap,
                        'supplied_qty'  => $suppliedNow,
                        'shortage_qty'  => $remainingGap,
                    ];
                    $remainingGap > 0 ? $partialCount++ : $fulfilledCount++;
                    $perPairSupplied[$key] = $suppliedNow;
                }

                $existing = $existingPendingShortages->get($key);
                $note = $suppliedNow > 0
                    ? "تمت تغطية {$suppliedNow} من أصل {$effectiveGap} فوراً من المخزون المركزي بتاريخ {$today}."
                    : "لا يوجد رصيد كافٍ (بما يكفي عبوة كاملة على الأقل) بالمخزون المركزي حالياً لتغطية أي جزء من الفجوة ({$effectiveGap} وحدة) بتاريخ {$today}.";

                if ($remainingGap > 0) {
                    if ($existing) {
                        $shortageUpdates[] = [
                            'id'         => $existing->id,
                            'pharmacy_id' => $existing->pharmacy_id,
                            'product_id' => $existing->product_id,
                            'source'     => $existing->source->value,
                            'quantity'   => $remainingGap,
                            'status'     => ShortageStatus::Pending->value,
                            'notes'      => $existing->appendResolutionNote($note),
                            'updated_at' => $now,
                        ];
                    } else {
                        $shortageInserts[] = [
                            'pharmacy_id' => $pharmacyId,
                            'product_id'  => $productId,
                            'quantity'    => $remainingGap,
                            'status'      => ShortageStatus::Pending->value,
                            'source'      => ShortageSource::StandardInventory->value,
                            'notes'       => 'نشأ تلقائياً من مقارنة رصيد الصيدلية بالمخزون القياسي. ' . $note,
                            'created_at'  => $now,
                            'updated_at'  => $now,
                        ];
                    }
                    $shortagesRecorded++;
                } elseif ($existing) {
                    $shortageUpdates[] = [
                        'id'         => $existing->id,
                        'pharmacy_id' => $existing->pharmacy_id,
                        'product_id' => $existing->product_id,
                        'source'     => $existing->source->value,
                        'quantity'   => 0,
                        'status'     => ShortageStatus::Fulfilled->value,
                        'notes'      => $existing->appendResolutionNote($note),
                        'updated_at' => $now,
                    ];
                }
            }

            // 🩹 [إصلاح N+1] كان استعلام UPDATE منفصلاً لكل منتج مركزي تأثَّر —
            // بدفعة تلمس آلاف المنتجات المختلفة (كل صيدلية × كل منتج له نقص أو
            // فائض)، يعني آلاف الاستعلامات لمجرد تحديث الأرصدة. نفس نمط upsert()
            // المُجمَّع المُتَّبع بكل كاتب آخر بهذا المشروع (راجع WarehouseMatrixImporter).
            if ($stockRunning !== []) {
                $centralUpdates = [];
                foreach ($stockRunning as $productId => $newQty) {
                    $centralUpdates[] = ['product_id' => $productId, 'quantity' => max(0, $newQty), 'updated_at' => $now];
                }
                foreach (array_chunk($centralUpdates, 500) as $chunk) {
                    DB::table('central_inventories')->upsert($chunk, ['product_id'], ['quantity', 'updated_at']);
                }
            }

            foreach ($invoiceItemsByPharmacy as $pharmacyId => $items) {
                $invoice = Invoice::create([
                    'pharmacy_id' => $pharmacyId,
                    'date'        => $today,
                    'status'      => 'processed',
                    'source'      => ShortageSource::StandardInventory,
                ]);

                InvoiceItem::insert(array_map(fn($item) => [
                    'invoice_id'    => $invoice->id,
                    'product_id'    => $item['product_id'],
                    'requested_qty' => $item['requested_qty'],
                    'supplied_qty'  => $item['supplied_qty'],
                    'shortage_qty'  => $item['shortage_qty'],
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ], $items));

                $invoicesCreated++;
            }

            $evaluatedKeys = $standards
                ->map(fn(PharmacyMinimumStock $s) => "{$s->pharmacy_id}_{$s->product_id}")
                ->flip();

            $orphanedShortages = Shortage::pending()
                ->where('source', ShortageSource::StandardInventory)
                ->whereIn('pharmacy_id', $pharmacyIds)
                ->get()
                ->reject(fn(Shortage $s) => $evaluatedKeys->has("{$s->pharmacy_id}_{$s->product_id}"));

            foreach ($orphanedShortages as $shortage) {
                $shortageUpdates[] = [
                    'id'         => $shortage->id,
                    'pharmacy_id' => $shortage->pharmacy_id,
                    'product_id' => $shortage->product_id,
                    'source'     => $shortage->source->value,
                    'quantity'   => 0,
                    'status'     => ShortageStatus::Fulfilled->value,
                    'notes'      => $shortage->appendResolutionNote(
                        "أُغلق تلقائياً: لم يعد لهذا الصنف معيار مخزون قياسي محدَّد لهذه الصيدلية عند جرد {$today}."
                    ),
                    'updated_at' => $now,
                ];
                $shortagesResolved++;
            }

            // 🆕 ══ تنفيذ الكتابة الفعلية دفعة واحدة، بعد اكتمال كل القرارات ══
            foreach (array_chunk($shortageInserts, 500) as $chunk) {
                DB::table('shortages')->insert($chunk);
            }
            foreach (array_chunk($shortageUpdates, 500) as $chunk) {
                DB::table('shortages')->upsert($chunk, ['id'], ['quantity', 'status', 'notes', 'updated_at']);
            }

            // 🆕 recordMany() تُعيد التفاصيل الآن لا العدد — العدد يُحسَب هنا مباشرة
            // (مطابق تماماً للعدّاد القديم: عدد الصيدليات التي أُضيف لها فائض فعلي)
            $reverseTransferSourcesByProduct = $this->reverseTransferRecorder->recordMany($reverseItemsByPharmacy);
            $reverseTransfersCreated = count($reverseItemsByPharmacy);
        });

        return new RestockingResult(
            source: ShortageSource::StandardInventory,
            pairsEvaluated: $pairsEvaluated,
            invoicesCreated: $invoicesCreated,
            fulfilledCount: $fulfilledCount,
            partialCount: $partialCount,
            shortagesRecorded: $shortagesRecorded,
            shortagesResolved: $shortagesResolved,
            skippedGapBelowTolerance: $skippedGapBelowTolerance, // 🆕
            skippedGapBelowPackSize: $skippedGapBelowPackSize, // 🆕
            reverseTransfersCreated: $reverseTransfersCreated,
            perPairSupplied: $perPairSupplied,
            reverseTransferSources: $reverseTransferSourcesByProduct, // 🆕
        );
    }
}
