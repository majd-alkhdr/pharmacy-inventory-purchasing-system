<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\ShortageSource;
use App\Enums\ShortageStatus;
use App\Models\CentralInventory;
use App\Models\ExcludedCompany;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMinimumStock;
use App\Models\Shortage;
use App\Models\TemporarySale;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 الوضع الرابع: **المبيعات تفتح الباب، لكن المخزون القياسي يقرر الكمية**.
 * ليست "استراتيجية مبيعات بحساب مختلف" ولا "مخزون قياسي بمُحفِّز مختلف" —
 * دمج فعلي بين الاثنين: نفس مصدر التفعيل مثل SalesBasedRestockingStrategy
 * (صفوف temporary_sales لهذه الجلسة)، ونفس الرياضيات مثل
 * ParLevelReplenishmentService (عبر ParLevelCalculator المُستخرَج، المصدر
 * الوحيد لتلك الرياضيات الآن — راجع تعليقه).
 *
 * 🎓 لماذا كلاس مستقل تماماً، لا تعديل على ParLevelReplenishmentService
 * نفسها لتقبل "قائمة أزواج محدَّدة" كمعامل بديل؟ لأن الفرق بين الوضعين ليس
 * فقط "أي الأزواج تُقيَّم" — طريقة تحديد "الرصيد الحالي" تختلف جوهرياً:
 * الوضع القياسي يقرأ pharmacy_inventories.quantity كما هي (لقطة جرد كاملة
 * مرفوعة)، بينما هذا الوضع **يحسبها بنفسه** (الرصيد المخزَّن ناقص المبيعات
 * المُسوَّاة هذه الدفعة)، **ثم يكتبها** (يُحدِّث pharmacy_inventories نفسها)
 * — أثر جانبي لا وجود له بالمحرك القياسي إطلاقاً. حشر هذا الفرق داخل نفس
 * الحلقة كان سيحوّل شرطاً واحداً بسيطاً إلى فرعين متشابكين يصعب اختبارهما
 * بمعزل عن بعضهما. كلاسان منفصلان، رياضيات مشتركة (الكلاس النقي)،
 * أرثوذكسية استراتيجيات مختلفة — بالضبط ما يوجد أصلاً بين SalesBased
 * وStandardInventory كاستراتيجيتين منفصلتين لكل منهما محرك (Engine) خاص.
 *
 * ⚖️ قرارات حاسمة (موثَّقة هنا لأنها ليست مجرد تفصيل تنفيذ، بل خيارات عمل):
 *
 * 1. **زوج بلا صف pharmacy_inventories موجود أصلاً يُتجاهَل كلياً** — لا
 *    نفترض رصيداً صفرياً من العدم. نفس فلسفة ParLevelReplenishmentService
 *    تماماً (`if (!isset($currentPharmacyStock[$key])) continue;`): "لا نعرف
 *    رصيد هذه الصيدلية لهذه المادة إطلاقاً" مختلف عن "رصيدها صفر بالفعل".
 *    الأول يعني: لم يُرفَع جرد قط لهذا الزوج — أضف مخزوناً قياسياً وارفع جرداً
 *    أولاً (كشف مستودعات أو ملف جرد صيدليات)، لا تعتمد على المبيعات وحدها
 *    لإنشاء خط أساس من فراغ.
 *
 * 2. **الرصيد يُحدَّث (يُنقَص) لكل زوج باعته صيدلية بهذه الدفعة — حتى لو لم
 *    يكن له مخزون قياسي محدَّد إطلاقاً.** إبقاء pharmacy_inventories دقيقاً
 *    مسؤولية منفصلة عن "هل نُرمِّم؟" — الأول سجل حقيقة (كم تبقّى فعلياً)،
 *    الثاني قرار عمل (هل نتصرف بناءً عليه؟). بلا هذا التحديث، كل دفعة مبيعات
 *    لاحقة كانت ستطرح من رصيد قديم متزايد الخطأ.
 *
 * 3. **لا "إغلاق نواقص يتيمة" هنا إطلاقاً** (بخلاف ParLevelReplenishmentService
 *    الذي يُغلق أي نقص معلَّق لزوج لم يظهر بدفعته الحالية). هذا الوضع يُقيِّم
 *    فقط الأزواج التي باعت شيئاً بهذه الدفعة تحديداً — مجموعة فرعية ضئيلة من
 *    كل ما له مخزون قياسي. لو أغلقنا كل نقص لم يظهر بهذه الدفعة، كنا سنُغلق
 *    نواقص حقيقية لمنتجات لم تُبَع اليوم فقط بالصدفة — خطأ جسيم. **محدودية
 *    معروفة ومقصودة**: نقص لزوج توقّف عن البيع تماماً يبقى معلَّقاً حتى يُباع
 *    مجدَّداً (فيُعاد تقييمه)، أو حتى تُشغَّل دفعة StandardInventory/Hybrid
 *    كاملة لنفس الصيدلية (تفحص كل الأزواج بلا استثناء وتُغلق ما يلزم).
 *
 * 4. **الشركات المستثناة تُفلتَر كلياً من التقييم** (لا شحن صفر + نقص كامل
 *    كما تفعل استراتيجية المبيعات القديمة) — بنفس منطق ParLevelReplenishmentService
 *    تماماً، لأن هذا الوضع يستعير حسابها لا حساب الاستراتيجية القديمة.
 *
 * 5. **shortages وinvoices تُوسَم ShortageSource::StandardInventory** — لا
 *    قيمة Enum ثالثة جديدة. السبب: دلالة الكتابة (استبدال كامل للقراءة
 *    الحالية، لا تراكم) مطابقة لِما يعنيه ذلك المصدر أصلاً بتوثيقه، وإعادة
 *    استخدامه (لا اختراع Sales تسمية ثالثة) يعني أن أي دفعة StandardInventory
 *    أو Hybrid لاحقة لنفس الصيدلية "تكتسح" (sweep) نواقص هذا الوضع ضمن فحصها
 *    الشامل تلقائياً بلا أي كود إضافي — يُخفِّف أثر المحدودية 3 أعلاه جزئياً.
 */
final class SalesGatedStandardInventoryRestockingStrategy implements RestockingStrategy
{
    public function __construct(
        private readonly SalesRemainderResolverService $remainderResolver,
        private readonly ParLevelCalculator $calculator,
        private readonly ReverseTransferRecorderService $reverseTransferRecorder,
    ) {}

    public function execute(RestockingContext $context): RestockingResult
    {
        if (is_null($context->sessionId)) {
            return RestockingResult::empty(ShortageSource::StandardInventory);
        }

        $rawAggregatedSales = TemporarySale::forSession($context->sessionId)
            ->select('pharmacy_id', 'product_id', DB::raw('SUM(quantity) as raw_quantity'))
            ->groupBy('pharmacy_id', 'product_id')
            ->get();

        if ($rawAggregatedSales->isEmpty()) {
            TemporarySale::forSession($context->sessionId)->delete();

            return RestockingResult::empty(ShortageSource::StandardInventory);
        }

        return DB::transaction(function () use ($context, $rawAggregatedSales) {
            // 🩹 تصالح الكسور المتراكمة أولاً — نفس آلية InvoiceGenerationService
            // بالضبط (SalesRemainderResolverService)، **داخل المعاملة عمداً** لنفس
            // السبب الموثَّق هناك: فشل لاحق بهذه المعاملة يجب أن يُرجِع الباقي المحدَّث معه.
            $resolvedSales = $this->remainderResolver->resolve($rawAggregatedSales)->keyBy(
                fn(object $r) => "{$r->pharmacy_id}_{$r->product_id}"
            );

            TemporarySale::forSession($context->sessionId)->delete();

            if ($resolvedSales->isEmpty()) {
                return RestockingResult::empty(ShortageSource::StandardInventory);
            }

            $pharmacyIds = $resolvedSales->pluck('pharmacy_id')->unique()->values();
            $productIds = $resolvedSales->pluck('product_id')->unique()->values();

            $standards = PharmacyMinimumStock::whereIn('pharmacy_id', $pharmacyIds)
                ->whereIn('product_id', $productIds)
                ->with('product:id,company,pack_size,min_reorder_gap')
                ->get()
                ->keyBy(fn(PharmacyMinimumStock $s) => "{$s->pharmacy_id}_{$s->product_id}");

            $excludedCompanies = ExcludedCompany::currentList();

            // 🔒 قفل صفوف الرصيد الحالي: نقرأها ونكتبها بنفس المعاملة (راجع القرار 2).
            $currentPharmacyStock = PharmacyInventory::whereIn('pharmacy_id', $pharmacyIds)
                ->whereIn('product_id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn(PharmacyInventory $r) => "{$r->pharmacy_id}_{$r->product_id}");

            $centralStocks = CentralInventory::whereIn('product_id', $productIds)
                ->lockForUpdate()->get()->keyBy('product_id');

            $existingPendingShortages = Shortage::pending()
                ->where('source', ShortageSource::StandardInventory)
                ->whereIn('pharmacy_id', $pharmacyIds)
                ->whereIn('product_id', $productIds)
                ->get()
                ->keyBy(fn(Shortage $s) => "{$s->pharmacy_id}_{$s->product_id}");

            $pairsEvaluated = 0;
            $skippedNoBaseline = 0; // 🆕 [تشخيص]
            $skippedNoStandard = 0; // 🆕 [تشخيص]
            $skippedGapBelowTolerance = 0; // 🆕 [تشخيص]
            $skippedGapBelowPackSize = 0; // 🆕 [تشخيص]
            $invoicesCreated = 0;
            $fulfilledCount = 0;
            $partialCount = 0;
            $shortagesRecorded = 0;
            $shortagesResolved = 0;
            $perPairSupplied = [];

            $pharmacyInventoryUpdates = [];
            $invoiceItemsByPharmacy = [];
            $reverseItemsByPharmacy = [];
            $shortageInserts = [];
            $shortageUpdates = [];
            $stockRunning = [];

            $today = now()->toDateString();
            $now = now();

            foreach ($resolvedSales as $key => $sale) {
                $inventoryRow = $currentPharmacyStock->get($key);

                if ($inventoryRow === null) {
                    $skippedNoBaseline++; // 🆕 [تشخيص]
                    continue; // القرار 1: لا خط أساس من فراغ
                }

                // القرار 2: الرصيد يُحدَّث لكل زوج باعته، بصرف النظر عن وجود مخزون قياسي.
                $resultingStock = max(0, (int) $inventoryRow->quantity - (int) $sale->total_quantity);
                $pharmacyInventoryUpdates[] = [
                    'pharmacy_id' => (int) $sale->pharmacy_id,
                    'product_id' => (int) $sale->product_id,
                    'quantity' => $resultingStock,
                    'updated_at' => $now,
                ];

                $standard = $standards->get($key);

                if ($standard === null) {
                    $skippedNoStandard++; // 🆕 [تشخيص]
                    continue; // لا مخزون قياسي = لا معيار نقيس عليه الفجوة، لا قرار ترميم هنا
                }

                if (in_array($standard->product->company ?? null, $excludedCompanies, true)) {
                    continue; // القرار 4
                }

                $pairsEvaluated++;
                $pharmacyId = (int) $sale->pharmacy_id;
                $productId = (int) $sale->product_id;

                $decision = $this->calculator->evaluate(
                    standardQuantity: $standard->minimum_quantity,
                    currentStock: $resultingStock,
                    minReorderGap: $standard->effectiveMinReorderGap(),
                    packSize: $standard->effectivePackSize(),
                );

                if ($decision->isAtOrAboveStandard) {
                    if ($existing = $existingPendingShortages->get($key)) {
                        $shortageUpdates[] = [
                            'id' => $existing->id,
                            // 🩹 [إصلاح جوهري] pharmacy_id/product_id/source مطلوبة هنا رغم
                            // أنها لن تتغيّر فعلياً (السطر موجود أصلاً بنفس القيم) — راجع
                            // تعليق الكلاس الكامل أسفل الملف للسبب التقني الدقيق.
                            'pharmacy_id' => $existing->pharmacy_id,
                            'product_id' => $existing->product_id,
                            'source' => $existing->source->value,
                            'quantity' => 0,
                            'status' => ShortageStatus::Fulfilled->value,
                            'notes' => $existing->appendResolutionNote(
                                "أُغلق تلقائياً: رصيد الصيدلية بلغ أو تجاوز المخزون القياسي بعد بيع بتاريخ {$today}."
                            ),
                            'updated_at' => $now,
                        ];
                        $shortagesResolved++;
                    }

                    if ($decision->needsReverseTransfer()) {
                        $reverseItemsByPharmacy[$pharmacyId][] = [
                            'product_id' => $productId,
                            'quantity' => $decision->effectiveExcess,
                            'standard_quantity_at_time' => $standard->minimum_quantity,
                            'pharmacy_quantity_at_time' => $resultingStock,
                        ];
                    }

                    continue;
                }

                // 🆕 [تشخيص] فجوة حقيقية موجودة لكنها ستُقمَع بصمت أدناه — راجع تعليق
                // RestockingResult::$skippedGapBelowTolerance للفلسفة الكاملة. نُميِّز
                // السببين هنا تحديداً (لا نفس الشرط المُدمَج) ليعرف المستخدم أيهما ينطبق.
                if ($decision->gapBelowThreshold) {
                    $skippedGapBelowTolerance++;
                    continue;
                }

                if ($decision->effectiveGap <= 0) {
                    $skippedGapBelowPackSize++;
                    continue;
                }

                $effectiveGap = $decision->effectiveGap;
                $packSize = $standard->effectivePackSize();
                $availableCentral = $stockRunning[$productId] ?? (int) ($centralStocks->get($productId)?->quantity ?? 0);

                $allocation = $this->calculator->allocate($effectiveGap, $availableCentral, $packSize);
                $stockRunning[$productId] = $availableCentral - $allocation->suppliedNow;

                if ($allocation->suppliedAnything()) {
                    $invoiceItemsByPharmacy[$pharmacyId][] = [
                        'product_id' => $productId,
                        'requested_qty' => $effectiveGap,
                        'supplied_qty' => $allocation->suppliedNow,
                        'shortage_qty' => $allocation->remainingGap,
                    ];
                    $allocation->isFullyCovered() ? $fulfilledCount++ : $partialCount++;
                    $perPairSupplied[$key] = $allocation->suppliedNow;
                }

                $existing = $existingPendingShortages->get($key);
                $note = $allocation->suppliedAnything()
                    ? "تمت تغطية {$allocation->suppliedNow} من أصل {$effectiveGap} فوراً من المخزون المركزي بعد بيع بتاريخ {$today}."
                    : "لا يوجد رصيد كافٍ (عبوة كاملة على الأقل) بالمخزون المركزي حالياً لتغطية الفجوة ({$effectiveGap} وحدة) بعد بيع بتاريخ {$today}.";

                if (!$allocation->isFullyCovered()) {
                    if ($existing) {
                        $shortageUpdates[] = [
                            'id' => $existing->id,
                            'pharmacy_id' => $existing->pharmacy_id,
                            'product_id' => $existing->product_id,
                            'source' => $existing->source->value,
                            'quantity' => $allocation->remainingGap,
                            'status' => ShortageStatus::Pending->value,
                            'notes' => $existing->appendResolutionNote($note),
                            'updated_at' => $now,
                        ];
                    } else {
                        $shortageInserts[] = [
                            'pharmacy_id' => $pharmacyId,
                            'product_id' => $productId,
                            'quantity' => $allocation->remainingGap,
                            'status' => ShortageStatus::Pending->value,
                            'source' => ShortageSource::StandardInventory->value,
                            'notes' => 'نشأ تلقائياً من فجوة مخزون قياسي فتحها بيع بتاريخ ' . $today . '. ' . $note,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    $shortagesRecorded++;
                } elseif ($existing) {
                    $shortageUpdates[] = [
                        'id' => $existing->id,
                        'pharmacy_id' => $existing->pharmacy_id,
                        'product_id' => $existing->product_id,
                        'source' => $existing->source->value,
                        'quantity' => 0,
                        'status' => ShortageStatus::Fulfilled->value,
                        'notes' => $existing->appendResolutionNote($note),
                        'updated_at' => $now,
                    ];
                }
            }

            // 🩹 [إصلاح N+1] كان هذا استعلام UPDATE منفصلاً لكل زوج باع شيئاً —
            // بدفعة 5,000 زوج، يعني 5,000 استعلام لمجرد تحديث الرصيد. نفس نمط
            // upsert() المُجمَّع المُتَّبع بكل كاتب آخر بهذا المشروع (راجع
            // WarehouseMatrixImporter وParLevelReplenishmentService). المفتاح
            // الطبيعي (pharmacy_id, product_id) — نفس القيد الفريد بالجدول
            // نفسه — لا id السطحي، توافقاً مع نفس أسلوب بقية الكتّاب بالمشروع.
            foreach (array_chunk($pharmacyInventoryUpdates, 500) as $chunk) {
                DB::table('pharmacy_inventories')->upsert($chunk, ['pharmacy_id', 'product_id'], ['quantity', 'updated_at']);
            }

            // 🩹 [إصلاح N+1، نفس النمط المُصلَح أعلاه لـ pharmacy_inventories] كان
            // استعلام UPDATE منفصلاً لكل منتج مركزي تأثَّر — بدفعة تلمس آلاف
            // المنتجات المختلفة، يعني آلاف الاستعلامات. راجع أيضاً الإصلاح المطابق
            // بـ ParLevelReplenishmentService (نفس النمط بالضبط، نفس الحل).
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
                    'date' => $today,
                    'status' => 'processed',
                    'source' => ShortageSource::StandardInventory,
                ]);

                InvoiceItem::insert(array_map(fn($item) => [
                    'invoice_id' => $invoice->id,
                    'product_id' => $item['product_id'],
                    'requested_qty' => $item['requested_qty'],
                    'supplied_qty' => $item['supplied_qty'],
                    'shortage_qty' => $item['shortage_qty'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $items));

                $invoicesCreated++;
            }

            foreach (array_chunk($shortageInserts, 500) as $chunk) {
                DB::table('shortages')->insert($chunk);
            }
            foreach (array_chunk($shortageUpdates, 500) as $chunk) {
                DB::table('shortages')->upsert($chunk, ['id'], ['quantity', 'status', 'notes', 'updated_at']);
            }

            $reverseTransferSourcesByProduct = $this->reverseTransferRecorder->recordMany($reverseItemsByPharmacy);

            return new RestockingResult(
                source: ShortageSource::StandardInventory,
                pairsEvaluated: $pairsEvaluated,
                invoicesCreated: $invoicesCreated,
                fulfilledCount: $fulfilledCount,
                partialCount: $partialCount,
                shortagesRecorded: $shortagesRecorded,
                shortagesResolved: $shortagesResolved,
                reverseTransfersCreated: count($reverseItemsByPharmacy),
                skippedNoBaseline: $skippedNoBaseline, // 🆕
                skippedNoStandard: $skippedNoStandard, // 🆕
                skippedGapBelowTolerance: $skippedGapBelowTolerance, // 🆕
                skippedGapBelowPackSize: $skippedGapBelowPackSize, // 🆕
                perPairSupplied: $perPairSupplied,
                reverseTransferSources: $reverseTransferSourcesByProduct,
            );
        });
    }

    public function source(): ShortageSource
    {
        return ShortageSource::StandardInventory;
    }
}
