<?php

namespace App\Services\Inventory;

use App\Enums\ShortageSource;
use App\Models\CentralInventory;
use App\Models\ImportSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\TemporarySale;
use Illuminate\Support\Facades\DB;

/**
 * 🔄 [أُعيد تفعيلها 2026-09] كانت مُعطَّلة منذ 2026-08 (استُبدلت وقتها
 * بـ ParLevelReplenishmentService بالكامل). بقرار صريح من صاحب المشروع:
 * الآن تُستدعى مجدَّداً كإحدى استراتيجيتين قابلتين للاختيار (لا الوحيدة)
 * ضمن نظام "المناقلات" الجديد — راجع SalesBasedRestockingStrategy، وهي
 * الغلاف الذي يكسو هذه الخدمة بعقد RestockingStrategy الموحَّد. أي كود
 * قديم كان يفترض أن هذه الخدمة "ميتة تماماً" يجب ألا يعتمد على ذلك بعد
 * الآن — هي مرة أخرى جزء فعلي من مسار العمل، اختيارياً (Mode = Sales
 * أو Hybrid تحديداً، وليس StandardInventory).
 *
 * 🎯 المسؤولية الوحيدة: تحويل صفوف temporary_sales المُطابَقة إلى فواتير
 * "أساسية" حقيقية + سجلات نواقص للعجز.
 *
 * 🩹 إصلاح (2026-08): سابقاً، استثناء الشركات من الترميم (الذي اخترته
 * بشاشة "استثناء شركات من الترميم" عند رفع الجرد) كان يُطبَّق فقط على
 * الفواتير **الإلحاقية** (عبر ShortageFulfillmentService) — لم يكن
 * يُطبَّق على هذه الفواتير **الأساسية** (أول بيع من نفس الرصيد المرفوع
 * للتو). النتيجة: منتج بشركة مستثناة كان لا يزال يُشحَن فعلياً من
 * الرصيد المركزي المرفوع حديثاً بلحظة البيع الأولى نفسها — تناقض واضح
 * مع نية "تجاهل ملف الجرد لهذه الشركة تماماً بخصوص الترميم".
 *
 * الحل: قبل حساب supplied_qty لكل صنف، نتحقق أولاً هل شركة هذا المنتج
 * ضمن قائمة الاستثناء المخزَّنة بسجل الجلسة (نفس المصدر المستخدَم أصلاً
 * من ShortageFulfillmentService) — إن كانت كذلك، supplied_qty يُفرَض
 * صفراً بالكامل (بصرف النظر عن الرصيد الفعلي المتوفر)، وكل الكمية
 * المطلوبة تصبح عجزاً مسجَّلاً بجدول shortages كالمعتاد، بلا لمس رصيد
 * central_inventories لهذا الصنف إطلاقاً (يبقى محفوظاً كاملاً لاستخدام
 * لاحق بعد إلغاء الاستثناء).
 *
 * 🆕 (2026-09) generateInvoicesFromTemp() تُعيد الآن مصفوفة أغنى — أضيفت
 * أعداد (invoices_created, pairs_evaluated, fulfilled_count, partial_count,
 * shortages_recorded) لتمكين SalesBasedRestockingStrategy من بناء
 * RestockingResult حقيقي بدل قيَم صفرية وهمية. success/message بقيا
 * كما هما بالضبط — أي كود قديم يقرأ هذين المفتاحين فقط لا يتأثر إطلاقاً.
 *
 * 🩹 (2026-09) [إصلاح جوهري] الكميات المُجمَّعة هنا الآن كسرية خام (لا
 * ceil() مسبق بـ SalesImport بعد الآن) — تمر أولاً عبر
 * SalesRemainderResolverService قبل أي منطق شحن، لدمج الكسر الجديد مع
 * أي باقٍ متراكم من دفعات سابقة. راجع تعليق تلك الخدمة للخوارزمية
 * الكاملة وسبب استدعائها **داخل** المعاملة تحديداً لا قبلها.
 */
class InvoiceGenerationService
{
    public function __construct(
        private readonly ShortageRecorderService $shortageRecorder,
        private readonly SalesRemainderResolverService $remainderResolver, // 🆕
    ) {}

    public function generateInvoicesFromTemp(string $sessionId): array
    {
        $rawAggregatedSales = TemporarySale::forSession($sessionId)
            ->select('pharmacy_id', 'product_id', DB::raw('SUM(quantity) as raw_quantity'))
            ->groupBy('pharmacy_id', 'product_id')
            ->get();

        if ($rawAggregatedSales->isEmpty()) {
            TemporarySale::forSession($sessionId)->delete();
            // 🆕 reason:'no_data' — يفرّق هذا عن فشل حقيقي (استثناء غير
            // متوقَّع بالمصفوفة catch أدناه، بلا reason). السبب: ملف
            // المبيعات اختياري بالكامل بطلب المشروع الأصلي — "لا بيانات"
            // ليس خطأً يستدعي إيقاف باقي العملية (خصوصاً بالوضع الهجين،
            // حيث يجب أن تكمل استراتيجية المخزون القياسي عملها بصرف النظر).
            // راجع SalesBasedRestockingStrategy لكيفية استهلاك هذا الحقل.
            return ['success' => false, 'message' => 'لم يتم العثور على بيانات مبيعات صالحة.', 'reason' => 'no_data'];
        }

        // 🆕 (2026-09) قائمة دائمة الآن، لا مصفوفة خاصة بهذه الجلسة —
        // راجع ExcludedCompany. عملياً، سطور شركة مستثناة لن تصل هنا
        // أصلاً (تُفلتَر عند SalesImport قبل حتى دخول temporary_sales)،
        // فهذا الفحص أصبح خط دفاع احتياطي، لا الآلية الأساسية.
        $excludedCompanies = \App\Models\ExcludedCompany::currentList();

        // 🆕 عدّادات لبناء RestockingResult لاحقاً — راجع تعليق الكلاس أعلاه
        $pairsEvaluated = 0;
        $invoicesCreated = 0;
        $fulfilledCount = 0;
        $partialCount = 0;

        DB::beginTransaction();
        try {
            // 🆕 تصالح الكسور المتراكمة أولاً — راجع تعليق SalesRemainderResolverService
            // للخوارزمية الكاملة. **داخل المعاملة عمداً**: لو فشل أي شيء لاحقاً
            // هنا وتراجعت المعاملة، يجب أن يتراجع تحديث الباقي المخزَّن معه
            // أيضاً بالضبط — وإلا "يُستهلَك" الكسر منطقياً بلا أي شحن فعلي
            // يقابله، نقص صامت لن يظهر إلا بفحص يدوي لاحق.
            $aggregatedSales = $this->remainderResolver->resolve($rawAggregatedSales)->groupBy('pharmacy_id');

            // 🩹 استُخرج كل ما نحتاجه من temporary_sales بالفعل (SUM أعلاه) —
            // آمن حذفها الآن، حتى لو تبيَّن أن لا شيء وصل لعدد صحيح هذه الدفعة.
            TemporarySale::forSession($sessionId)->delete();

            if ($aggregatedSales->isEmpty()) {
                // 🎯 كل الأزواج هذه الدفعة ما زالت تتراكم (لم تصل بعد لعدد
                // صحيح قابل للشحن) — هذا ليس فشلاً، الهدف الوحيد لهذه الدفعة
                // كان تحديث الباقي المخزَّن، وقد تمّ بالفعل ضمن resolve() أعلاه.
                DB::commit();
                return [
                    'success' => false,
                    'message' => 'كل كميات المبيعات هذه الدفعة كسرية ولم تصل بعد لعدد صحيح قابل للشحن — تم حفظ الباقي لدفعة لاحقة.',
                    'reason'  => 'no_data',
                ];
            }

            $allProductIds = $aggregatedSales->flatten()->pluck('product_id')->unique()->values();
            $allStocks     = CentralInventory::whereIn('product_id', $allProductIds)->lockForUpdate()->get()->keyBy('product_id');

            // 🆕 خريطة شركة كل منتج — استعلام واحد فقط، خارج الحلقة تماماً
            $companyByProductId = Product::whereIn('id', $allProductIds)->pluck('company', 'id');

            $stockUpdates  = [];
            $invoiceItems  = [];
            $newShortages  = collect();
            $perPairSupplied = []; // 🆕 راجع تعليق RestockingResult::perPairSupplied

            foreach ($aggregatedSales as $pharmacyId => $items) {
                $invoice = Invoice::create([
                    'pharmacy_id' => $pharmacyId,
                    'date'        => now()->toDateString(),
                    'status'      => 'processed',
                    'source'      => ShortageSource::Sales, // 🆕 لتقرير كشف تجهيز الطلبيات (PreparationExport)
                ]);
                $invoicesCreated++;

                foreach ($items as $item) {
                    $pairsEvaluated++;

                    $productId     = $item->product_id;
                    $totalRequired = $item->total_quantity;
                    $currentQty    = $stockUpdates[$productId] ?? ($allStocks->get($productId)?->quantity ?? 0);

                    // 🆕 الفحص الجديد — شركة مستثناة = عدم الشحن إطلاقاً بهذه الدفعة
                    $isExcludedCompany = in_array($companyByProductId[$productId] ?? null, $excludedCompanies, true);

                    $suppliedQty = $isExcludedCompany ? 0 : min($totalRequired, $currentQty);
                    $shortageQty = $totalRequired - $suppliedQty;
                    $stockUpdates[$productId] = $currentQty - $suppliedQty; // 🩹 لو مستثناة: suppliedQty=0 → الرصيد يبقى كما هو تماماً

                    // 🆕 يُستهلَك حصراً من HybridRestockingStrategy لتصالح
                    // "الأكبر لا الجمع" مع استراتيجية المخزون القياسي
                    if ($suppliedQty > 0) {
                        $perPairSupplied["{$pharmacyId}_{$productId}"] = $suppliedQty;
                    }

                    $invoiceItems[] = [
                        'invoice_id'    => $invoice->id,
                        'product_id'    => $productId,
                        'requested_qty' => $totalRequired,
                        'supplied_qty'  => $suppliedQty,
                        'shortage_qty'  => $shortageQty,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ];

                    // 🆕 نفس منطق العدّ المُتَّبع بـ ParLevelReplenishmentService
                    // بالضبط: لا يُحتسَب "مُرمَّم" أو "جزئي" إلا لو شُحن شيء
                    // فعلاً؛ "لم يُشحن شيء إطلاقاً" لا يُحتسَب بأي منهما،
                    // رغم تسجيله كنقص كامل كالمعتاد.
                    if ($suppliedQty > 0) {
                        $shortageQty > 0 ? $partialCount++ : $fulfilledCount++;
                    }

                    if ($shortageQty > 0) {
                        $newShortages->push([
                            'pharmacy_id' => $pharmacyId,
                            'product_id'  => $productId,
                            'quantity'    => $shortageQty,
                            'occurred_at' => now(),
                        ]);
                    }
                }
            }

            foreach (array_chunk($invoiceItems, 500) as $chunk) {
                InvoiceItem::insert($chunk);
            }

            $this->shortageRecorder->recordMany($newShortages);

            foreach ($stockUpdates as $productId => $newQty) {
                CentralInventory::where('product_id', $productId)->update(['quantity' => max(0, $newQty)]);
            }

            // 🩹 لا حذف ثانٍ لـ temporary_sales هنا — تم بالفعل أعلى الدالة
            // مباشرة بعد resolve()، لا هنا بنهايتها كما كان سابقاً. الحذف
            // المبكر آمن تماماً (كل ما نحتاجه استُخرج أصلاً بـ SUM())، ولا
            // داعٍ للانتظار حتى نجاح إنشاء الفواتير لحذف بيانات لم تعد تُستهلَك.
            DB::commit();

            $msg = '🎉 تم توليد فواتير المناقلة بنجاح.';
            if (!empty($excludedCompanies)) {
                $msg .= ' ⚠️ الشركات التالية استُثنيت من الشحن الفوري بهذه الدفعة (عجز كامل مسجَّل بدلاً منه): ' . implode('، ', $excludedCompanies) . '.';
            }

            return [
                'success' => true,
                'message' => $msg,
                // 🆕 يستهلكها SalesBasedRestockingStrategy فقط — أي كود قديم
                // يقرأ success/message فقط لا يرى أي فرق.
                'pairs_evaluated'    => $pairsEvaluated,
                'invoices_created'   => $invoicesCreated,
                'fulfilled_count'    => $fulfilledCount,
                'partial_count'      => $partialCount,
                'shortages_recorded' => $newShortages->count(),
                'per_pair_supplied'  => $perPairSupplied, // 🆕
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            return ['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()];
        }
    }
}
