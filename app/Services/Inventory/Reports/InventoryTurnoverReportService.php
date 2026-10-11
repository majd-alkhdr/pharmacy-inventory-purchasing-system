<?php
// app/Services/Inventory/Reports/InventoryTurnoverReportService.php

namespace App\Services\Inventory\Reports;

use App\Enums\TurnoverStatus;
use App\Models\CentralInventory;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 "تقرير دوران المخزون" — المستودع المركزي وكل صيدلية على حدة.
 *
 * 🎓 لماذا لا يستدعي App\Services\Purchasing\TurnoverCalculator مباشرة،
 * رغم أن الصيغة الحسابية هنا **مطابقة له عمداً**؟ سببان، الثاني أهم فعلياً:
 *
 * 1) التوجيه الأصلي لهذا المشروع: "لا تعديل على قسم المشتريات، التركيز
 *    الكامل على المناقلات".
 * 2) 🆕 الأهم: قسم المشتريات نفسه مرشَّح لإعادة هيكلة كاملة لاحقاً بنفس
 *    نهج هذا المشروع — أي اعتماد **قرائي فقط** عليه الآن (حتى بلا أي
 *    تعديل، مجرد استدعاء) يعني أن إعادة الهيكلة تلك قد تكسر تقرير
 *    المناقلات بصمت فور حدوثها. القرار الصريح من صاحب المشروع: صفر بناء
 *    على أي شيء بالمشتريات — لا كلاس، لا جدول، حتى الأعمدة "الحدودية"
 *    كـ Product.last_purchase_date (يملؤها تدفق استلام المشتريات تحديداً،
 *    راجع تصحيح buildCentralReport() أدناه لمثال فعلي حصل لهذا السبب بالذات).
 *
 * الحل: نفس **الصيغة** (شكل المعادلة، نافذة 7 أيام "رفع فعلي"، القسمة
 * على 4، بوابة "جديد جداً") مُعاد كتابتها هنا بشكل مستقل بالكامل — نفس
 * الفكر، صفر اعتماد، حتى على بيانات يملؤها تدفق مشتريات بمعزل عن جداولنا.
 *
 * 🎯 قرارات مصدر البيانات (بقرار صريح من صاحب المشروع، راجع المحادثة):
 * - "الرصيد الحالي" هنا central_inventories/pharmacy_inventories (بيانات
 *   المناقلات الخاصة)، لا InventoryBatch (جدول المشتريات) — رغم تأكيد أنهما
 *   يمثّلان نفس المخزون المادي فعلياً. الاعتماد على مصدر بياناتنا الخاص،
 *   المرفوع بانتظام تحت سيطرتنا، بدل الاعتماد على دورة رفع قسم آخر.
 *   🆕 (2026-10) لم يعد هذا مصدر اختلاف: قسم المشتريات صار يقرأ نفس central_inventories
 *   (ملف المصفوفة) بعد إلغاء جرد المشتريات، فالرصيد واحد بالقسمين.
 * - العتبات (شهران = راكد) بدأت مطابقة لعتبات المشتريات تماماً، كنقطة
 *   بداية معقولة إلى حين مراجعتها ببيانات حقيقية — راجع الثوابت أدناه،
 *   قابلة للتعديل بسطر واحد.
 *
 * 🆕 (2026-09) [تكامل المبيعات الحقيقية] الدوران على مستوى الصيدلية
 * يُفضِّل الآن PharmacySalesRecord (مبيعات حقيقية مسجَّلة) على بيانات
 * الشحن (invoice_items) حين تتوفر لزوج (صيدلية+مادة) معيَّن — راجع
 * buildPharmacyReport() للتفاصيل والسبب الكامل. المستودع المركزي عمداً
 * لا يستخدم هذا المصدر إطلاقاً؛ "بيع" مفهوم خاص بالصيدلية، لا بالمستودع.
 */
class InventoryTurnoverReportService
{
    private const TURNOVER_WINDOW_DAYS = 7;
    private const LOOKBACK_SAFETY_DAYS = 180; // 🎯 نفس نافذة الأمان المُستخدَمة بـ TurnoverCalculator، لنفس سبب الأداء بالضبط

    // 🎯 قابلة للتعديل بسهولة لاحقاً — راجع تعليق الكلاس أعلاه لسبب البدء
    // بنفس عتبة المشتريات لكلا المستويين (مركزي وصيدليات) مبدئياً
    public const STAGNANT_THRESHOLD_MONTHS = 2.0;
    public const HIGH_TURNOVER_THRESHOLD_MONTHS = 2.0;

    /**
     * @return object{central: object, pharmacies: Collection}
     */
    public function build(): object
    {
        $lookbackDate = now()->subDays(self::LOOKBACK_SAFETY_DAYS)->toDateString();

        // ══ استعلام واحد: كل صفوف الشحن (لكل الصيدليات معاً)، مُجمَّعة
        // (صيدلية، منتج، تاريخ) ضمن نافذة الأمان فقط — لا استعلام منفصل
        // لكل موقع أو منتج. ══
        $shipmentRows = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.status', 'processed')
            ->where('invoices.date', '>=', $lookbackDate)
            ->select(
                'invoices.pharmacy_id',
                'invoice_items.product_id',
                'invoices.date',
                DB::raw('SUM(invoice_items.requested_qty) as qty'),
            )
            ->groupBy('invoices.pharmacy_id', 'invoice_items.product_id', 'invoices.date')
            ->get();

        // 🆕 مبيعات حقيقية مسجَّلة (PharmacySalesRecord) — مصدر أدق من
        // الشحن حين تُوجد، لأنها تعكس "ما بِيع فعلياً" لا "ما شُحن". الفرق
        // جوهري تحديداً حين لا يُرفَق ملف جرد مركزي (وضع مبيعات وحدها) —
        // بيع حقيقي حدث، لكن لا شحن يحدث مطلقاً بتصميم متعمَّد (راجع
        // InventoryUploadService)، فلولا هذا المصدر المنفصل لبقي دوران
        // هذه الصيدلية بلا أي تحديث إطلاقاً رغم رفع ملفات مبيعات متكررة —
        // بالضبط ما لوحظ عملياً قبل هذا الإصلاح.
        $realSalesRows = DB::table('pharmacy_sales_records')
            ->where('sale_date', '>=', $lookbackDate)
            ->select(
                'pharmacy_id',
                'product_id',
                'sale_date as date',
                DB::raw('SUM(quantity) as qty'),
            )
            ->groupBy('pharmacy_id', 'product_id', 'sale_date')
            ->get();

        // 🩹 [مُصحَّح] نجلب created_at أيضاً، لا الكمية فقط — راجع بوابة
        // "جديد جداً على المستودع المركزي" بـ buildCentralReport() للسبب.
        $centralStockRows = CentralInventory::where('quantity', '>', 0)
            ->select('product_id', 'quantity', 'created_at')->get()->keyBy('product_id');
        $pharmacyStockRows = PharmacyInventory::where('quantity', '>', 0)
            ->select('pharmacy_id', 'product_id', 'quantity')->get();

        $allProductIds = $shipmentRows->pluck('product_id')
            ->merge($centralStockRows->keys())
            ->merge($pharmacyStockRows->pluck('product_id'))
            ->merge($realSalesRows->pluck('product_id')) // 🆕
            ->unique();
        $products = Product::whereIn('id', $allProductIds)
            ->get(['id', 'name', 'company'])
            ->keyBy('id');

        // 🎯 أول تاريخ شحن فعلي لكل زوج (صيدلية+مادة) — بوابة "جديد جداً
        // لهذه الصيدلية تحديداً" (راجع تعليق buildPharmacyReport())
        $firstShipmentByPair = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.status', 'processed')
            ->select('invoices.pharmacy_id', 'invoice_items.product_id', DB::raw('MIN(invoices.date) as first_date'))
            ->groupBy('invoices.pharmacy_id', 'invoice_items.product_id')
            ->get()
            ->keyBy(fn($r) => "{$r->pharmacy_id}_{$r->product_id}");

        // 🩹 المستودع المركزي عمداً لا يستخدم PharmacySalesRecord — "بيع"
        // مفهوم خاص بالصيدلية (من باعت لمَن)، لا بالمستودع (الذي "يشحن"،
        // لا "يبيع"). buildCentralReport() يبقى بلا تغيير هنا.
        $central = $this->buildCentralReport($centralStockRows, $shipmentRows, $products);

        $pharmacies = Pharmacy::orderBy('name')->get(['id', 'name'])
            ->map(fn(Pharmacy $pharmacy) => $this->buildPharmacyReport(
                $pharmacy,
                $pharmacyStockRows,
                $shipmentRows,
                $realSalesRows, // 🆕
                $products,
                $firstShipmentByPair
            ));

        return (object) ['central' => $central, 'pharmacies' => $pharmacies];
    }

    /**
     * 🩹 [مُصحَّح] كانت تستخدم Product::isNewlyPurchased() (يعتمد
     * last_purchase_date — عمود يملؤه تدفق استلام المشتريات تحديداً).
     * بقرار صريح من صاحب المشروع: لا اعتماد على أي شيء يخص قسم المشتريات،
     * لأنه سيُعاد بناؤه بالكامل لاحقاً بنفس نهج هذا المشروع — أي اعتماد
     * عليه الآن قد ينكسر بصمت فور تلك الإعادة. البديل هنا مصدره بياناتنا
     * الخاصة بالكامل: created_at لصف central_inventories نفسه — وقت أول
     * ظهور فعلي لهذا المنتج بجدول المناقلات الخاص بنا، لا وقت آخر شراء له.
     */
    private function buildCentralReport(Collection $centralStockRows, Collection $shipmentRows, Collection $products): object
    {
        // 🎯 كل شحنة من أي صيدلية تُستهلَك من نفس المستودع المركزي —
        // نجمع حسب (المنتج، التاريخ) بصرف النظر عن الصيدلية المستقبِلة.
        $byProductDate = $shipmentRows->groupBy('product_id')
            ->map(fn(Collection $rows) => $rows->groupBy('date')->map(fn($g) => (float) $g->sum('qty')));

        $relevantProductIds = $centralStockRows->keys()->merge($byProductDate->keys())->unique();

        $rows = $relevantProductIds->map(function ($productId) use ($centralStockRows, $byProductDate, $products) {
            $product = $products->get($productId);
            $centralRow = $centralStockRows->get($productId);
            $weeklyOutflow = $this->lastNActualDaysSum($byProductDate->get($productId, collect()));
            $currentStock = (float) ($centralRow->quantity ?? 0);

            $isNewToCentral = $centralRow
                && Carbon::parse($centralRow->created_at)->greaterThanOrEqualTo(now()->subDays(30));
            $months = $this->resolveMonths($weeklyOutflow, $currentStock, $isNewToCentral);

            return $this->makeRow($productId, $product, $currentStock, $weeklyOutflow, $months, 'shipment_estimate');
        });

        return $this->summarize('المستودع المركزي', $rows, null);
    }

    /**
     * 🎯 بوابة "جديد جداً" هنا مقصودة لتكون خاصة بهذا الزوج (صيدلية+مادة)
     * تحديداً، لا بالمنتج عموماً — وليست نفس بوابة المستودع المركزي
     * (created_at بجدول central_inventories). السبب: منتج قديم جداً
     * بالمستودع المركزي قد يكون بدأ التوريد لصيدلية معيَّنة توّاً — فالحكم
     * عليه بـ"راكد" بهذه الصيدلية تحديداً بعد يوم واحد فقط من أول شحنة
     * له إليها كان سيكون خاطئاً وظالماً. المعيار الصحيح هنا: أول شحنة
     * فعلية **لهذا الزوج تحديداً** (من invoice_items/invoices — بياناتنا
     * الخاصة بالكامل)، لا لحظة دخول المنتج للمستودع المركزي أصلاً.
     *
     * 🆕 (2026-09) [التفضيل الجوهري] لكل زوج (صيدلية+مادة) على حدة: إن
     * وُجدت مبيعات حقيقية (PharmacySalesRecord) ضمن نافذة الأمان، تُستخدم
     * **حصراً** — لا مزيجاً مع تقدير الشحن. دقة كاملة أو تقدير كامل، لا
     * خلط مصدرين مختلفين جوهرياً بنفس الرقم. غياب المبيعات الحقيقية لهذا
     * الزوج تحديداً (حتى لو وُجدت لزوج آخر بنفس الصيدلية) يعني العودة
     * التلقائية لتقدير الشحن كما كان قبل هذا التكامل تماماً.
     */
    private function buildPharmacyReport(
        Pharmacy $pharmacy,
        Collection $pharmacyStockRows,
        Collection $shipmentRows,
        Collection $realSalesRows, // 🆕
        Collection $products,
        Collection $firstShipmentByPair,
    ): object {
        $stockForThisPharmacy = $pharmacyStockRows->where('pharmacy_id', $pharmacy->id)->keyBy('product_id');
        $byProductDate = $shipmentRows->where('pharmacy_id', $pharmacy->id)->groupBy('product_id')
            ->map(fn(Collection $rows) => $rows->groupBy('date')->map(fn($g) => (float) $g->sum('qty')));

        // 🆕 نفس التجميع بالضبط، من المبيعات الحقيقية لهذه الصيدلية تحديداً
        $realSalesByProductDate = $realSalesRows->where('pharmacy_id', $pharmacy->id)->groupBy('product_id')
            ->map(fn(Collection $rows) => $rows->groupBy('date')->map(fn($g) => (float) $g->sum('qty')));

        $relevantProductIds = $stockForThisPharmacy->keys()
            ->merge($byProductDate->keys())
            ->merge($realSalesByProductDate->keys()) // 🆕
            ->unique();

        $rows = $relevantProductIds->map(function ($productId) use (
            $pharmacy, $stockForThisPharmacy, $byProductDate, $realSalesByProductDate, $products, $firstShipmentByPair
        ) {
            $product = $products->get($productId);

            // 🆕 مبيعات حقيقية لهذا الزوج تحديداً؟ استخدمها حصراً.
            $hasRealSales = $realSalesByProductDate->has($productId);
            $weeklyOutflow = $hasRealSales
                ? $this->lastNActualDaysSum($realSalesByProductDate->get($productId))
                : $this->lastNActualDaysSum($byProductDate->get($productId, collect()));

            $currentStock = (float) ($stockForThisPharmacy->get($productId)->quantity ?? 0);

            $firstShip = $firstShipmentByPair->get("{$pharmacy->id}_{$productId}");
            $isNewToThisPharmacy = $firstShip
                && Carbon::parse($firstShip->first_date)->greaterThanOrEqualTo(now()->subDays(30));

            // 🆕 مبيعات حقيقية موجودة = دليل كافٍ وحده على أن هذا الزوج
            // ليس "جديداً جداً" — حتى لو لم تحدث أي شحنة إليه مطلقاً بعد
            // (بالضبط حالة "مبيعات فقط، بلا جرد مركزي أُرفِق قط").
            if ($hasRealSales) {
                $isNewToThisPharmacy = false;
            }

            $months = $this->resolveMonths($weeklyOutflow, $currentStock, $isNewToThisPharmacy);

            return $this->makeRow(
                $productId, $product, $currentStock, $weeklyOutflow, $months,
                $hasRealSales ? 'real_sales' : 'shipment_estimate' // 🆕
            );
        });

        $lastStockUpdate = PharmacyInventory::where('pharmacy_id', $pharmacy->id)->max('updated_at');

        return $this->summarize($pharmacy->name, $rows, $pharmacy->id, $lastStockUpdate);
    }

    /** 🎯 نفس صيغة TurnoverCalculator::resolveFromWeeklySales() بالضبط — راجع تعليق الكلاس للسبب */
    private function resolveMonths(float $weeklyOutflow, float $currentStock, bool $isNew): ?float
    {
        if ($weeklyOutflow <= 0) {
            return $isNew ? null : PHP_FLOAT_MAX;
        }
        if ($currentStock <= 0) {
            return 0.0;
        }

        return round(($currentStock / $weeklyOutflow) / 4, 4);
    }

    private function classify(?float $months): TurnoverStatus
    {
        if ($months === null) {
            return TurnoverStatus::TooNewToJudge;
        }
        if ($months > self::STAGNANT_THRESHOLD_MONTHS) {
            return TurnoverStatus::Stagnant;
        }
        if ($months < self::HIGH_TURNOVER_THRESHOLD_MONTHS) {
            return TurnoverStatus::HighTurnover;
        }

        return TurnoverStatus::Normal;
    }

    /** آخر 7 تواريخ "رفع فعلي" ظهرت فعلاً بالبيانات — لا آخر 7 أيام تقويمية */
    private function lastNActualDaysSum(Collection $byDate): float
    {
        return (float) $byDate->sortKeysDesc()->take(self::TURNOVER_WINDOW_DAYS)->sum();
    }

    /**
     * @param string $dataSource 🆕 'real_sales' أو 'shipment_estimate' —
     *        يُعرَض بالواجهة كشارة صغيرة توضِّح دقة الرقم لكل صف على حدة.
     */
    private function makeRow(
        int $productId, ?Product $product, float $currentStock, float $weeklyOutflow, ?float $months,
        string $dataSource = 'shipment_estimate'
    ): object {
        return (object) [
            'product_id'      => $productId,
            'product_name'   => $product->name ?? '؟',
            'company'         => $product->company ?? '؟',
            'current_stock'  => $currentStock,
            'weekly_outflow' => $weeklyOutflow,
            'turnover_months' => $months,
            'status'          => $this->classify($months),
            'data_source'     => $dataSource, // 🆕
        ];
    }

    /**
     * 🎯 ترتيب الصفوف: الراكد أولاً (الأكثر استحقاقاً للانتباه)، ثم
     * العادي، ثم الدوران العالي، ثم "جديد جداً" أخيراً — لا ترتيب رقمي
     * خام (كان سيضع PHP_FLOAT_MAX وnull بمواقع غريبة غير مفيدة عملياً).
     */
    private function summarize(string $label, Collection $rows, ?int $pharmacyId, $lastStockUpdate = null): object
    {
        $priority = [
            TurnoverStatus::Stagnant->value      => 0,
            TurnoverStatus::Normal->value         => 1,
            TurnoverStatus::HighTurnover->value   => 2,
            TurnoverStatus::TooNewToJudge->value => 3,
        ];
        $sorted = $rows->sortBy(fn($row) => $priority[$row->status->value] ?? 99)->values();

        $statusCounts = collect(TurnoverStatus::cases())
            ->mapWithKeys(fn($s) => [$s->value => $sorted->where('status', $s)->count()]);

        return (object) [
            'label'             => $label,
            'pharmacy_id'       => $pharmacyId,
            'is_central'        => $pharmacyId === null,
            'last_stock_update' => $lastStockUpdate,
            'rows'              => $sorted,
            'status_counts'     => $statusCounts,
            'product_count'     => $sorted->count(),
        ];
    }
}
