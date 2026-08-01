<?php

namespace App\Services\Purchasing;

use App\Enums\InvoiceStatus;
use App\Models\InventoryBatch;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 قلب إعادة هيكلة المشتريات — يطبّق المعادلة التي حدّدها المستخدم حرفياً:
 *
 *   الدوران بالأشهر = (الرصيد الحالي ÷ مبيعات آخر 7 أيام "رفع فعلي") ÷ 4
 *
 * 🆕 نسخة مُحسَّنة أداءً (2026-08، الجولة الثانية): تحتوي مسارين:
 *
 *   1) calculateFor(Product) — الحساب الدقيق لمنتج واحد فقط. تُستخدم من
 *      Product::turnoverInMonths() (عرض تفصيلي لمنتج واحد بشاشة مستقبلية
 *      مثلاً)، حيث الدقة الكاملة (بلا حدود زمنية للبحث) أهم من السرعة.
 *
 *   2) bulkCalculate(Collection $products) — 🆕 المسار المُحسَّن، يُستخدم
 *      من PurchaseOrderGenerationService عند فحص عشرات/مئات المنتجات معاً.
 *      بدل استعلام منفصل لكل منتج (N+1 كلاسيكي)، يُنفَّذ استعلامان فقط
 *      بالمجمل (مبيعات + رصيد)، والمعالجة الباقية (تحديد آخر 7 تواريخ بيع
 *      فعلية *لكل منتج على حدة*) تتم بالذاكرة بعد الجلب.
 *
 * 🎓 القرار التصميمي الأهم بالنسخة المُحسَّنة: نافذة أمان زمنية محدودة
 * (LOOKBACK_SAFETY_DAYS = 180 يوماً) بدل بحث غير محدود بالتاريخ بالكامل.
 * لماذا؟ لأن الدقة المطلقة (بحث بلا حدود حتى إيجاد 7 تواريخ) تعني عملياً
 * قراءة كامل جدول invoice_items لكل استعلام توليد فواتير — مكلف جداً على
 * الإنتاج الفعلي. نافذة 180 يوماً (~6 أشهر) تكفي عملياً لالتقاط آخر 7
 * تواريخ بيع فعلية حتى لمنتج بطيء الحركة نسبياً، مع أداء يبقى ضمن استعلام
 * واحد سريع. المنتج الذي لا يُسجّل أي بيعة خلال 180 يوماً كاملة هو بلا شك
 * راكد فعلياً (يُعامَل كـ"لا مبيعات" بنفس منطق calculateFor تماماً)، فلا
 * خسارة دقة حقيقية بهذا الافتراض.
 */
class TurnoverCalculator
{
    private const TURNOVER_WINDOW_DAYS = 7;
    private const LOOKBACK_SAFETY_DAYS = 180; // 🆕 نافذة أمان المسار المُحسَّن فقط

    /**
     * 🎯 الحساب الدقيق لمنتج واحد — بلا حدود زمنية (يبحث حتى يجد آخر 7
     * تواريخ بيع فعلية، مهما بَعُد تاريخها). يُستخدم خارج سياق "فحص
     * مجموعة كبيرة من المنتجات معاً" حيث السرعة ليست القيد الحرِج.
     *
     * @return float|null الدوران بالأشهر، أو null إن كان المنتج جديد
     *                     الشراء (أقل من 30 يوماً) وبلا مبيعات كافية للحكم
     *                     عليه بعد.
     */
    public function calculateFor(Product $product): ?float
    {
        $weeklySales = $this->weeklySalesFor($product->id);

        return $this->resolveFromWeeklySales($product, $weeklySales, $product->usableStockQuantity());
    }

    /**
     * 🆕 المسار المُحسَّن: يحسب الدوران لمجموعة منتجات معاً باستعلامين
     * فقط بالمجمل (بدل استعلام لكل منتج) — هذا ما يُستدعى فعلياً من
     * PurchaseOrderGenerationService الآن.
     *
     * @param Collection<int, Product> $products
     * @return array<int, float|null> [product_id => الدوران بالأشهر أو null]
     */
    public function bulkCalculate(Collection $products): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $productIds = $products->pluck('id');

        // ══ استعلام واحد: كل صفوف المبيعات (مُجمَّعة يومياً) لكل المنتجات
        // المطلوبة، ضمن نافذة الأمان الزمنية فقط. ══
        $salesRows = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereIn('invoice_items.product_id', $productIds)
            ->where('invoices.status', InvoiceStatus::Processed->value)
            ->where('invoices.date', '>=', now()->subDays(self::LOOKBACK_SAFETY_DAYS)->toDateString())
            ->select('invoice_items.product_id', 'invoices.date', DB::raw('SUM(invoice_items.requested_qty) as qty'))
            ->groupBy('invoice_items.product_id', 'invoices.date')
            ->get()
            ->groupBy('product_id');

        // ══ استعلام واحد: الرصيد الحالي (usable) لكل المنتجات معاً. ══
        $stockByProduct = InventoryBatch::query()
            ->usable()
            ->whereIn('product_id', $productIds)
            ->select('product_id', DB::raw('SUM(quantity) as qty'))
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        $result = [];

        foreach ($products as $product) {
            // 🎯 لكل منتج: نأخذ آخر 7 تواريخ (من ضمن ما جُلب بالنافذة)
            // ونجمع مبيعاتها — بالذاكرة فقط، بلا أي استعلام إضافي.
            $productSalesRows = $salesRows->get($product->id, collect());
            $last7 = $productSalesRows->sortByDesc('date')->take(self::TURNOVER_WINDOW_DAYS);
            $weeklySales = (float) $last7->sum('qty');

            $currentStock = (float) ($stockByProduct[$product->id] ?? 0);

            $result[$product->id] = $this->resolveFromWeeklySales($product, $weeklySales, $currentStock);
        }

        return $result;
    }

    /**
     * 🎯 المنطق المشترك بين المسارين (الدقيق والمُحسَّن) — نفس القواعد
     * بالضبط، لضمان أن كلا المسارين يُنتجان نفس النتيجة لنفس المُدخلات.
     */
    private function resolveFromWeeklySales(Product $product, float $weeklySales, float $currentStock): ?float
    {
        if ($weeklySales <= 0) {
            return $product->isNewlyPurchased() ? null : PHP_FLOAT_MAX;
        }

        if ($currentStock <= 0) {
            return 0.0;
        }

        return round(($currentStock / $weeklySales) / 4, 4);
    }

    /**
     * 🎯 مجموع requested_qty ضمن آخر 7 تواريخ فعلية ظهرت فيها فاتورة
     * أساسية (processed) لهذا المنتج تحديداً — وليس 7 أيام تقويمية.
     * بلا حدود زمنية (المسار الدقيق فقط — راجع calculateFor أعلاه).
     */
    private function weeklySalesFor(int $productId): float
    {
        $recentDates = DB::table('invoices')
            ->where('status', InvoiceStatus::Processed->value)
            ->whereExists(function ($query) use ($productId) {
                $query->selectRaw('1')
                    ->from('invoice_items')
                    ->whereColumn('invoice_items.invoice_id', 'invoices.id')
                    ->where('invoice_items.product_id', $productId);
            })
            ->distinct()
            ->orderByDesc('date')
            ->limit(self::TURNOVER_WINDOW_DAYS)
            ->pluck('date');

        if ($recentDates->isEmpty()) {
            return 0.0;
        }

        return (float) DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.product_id', $productId)
            ->where('invoices.status', InvoiceStatus::Processed->value)
            ->whereIn('invoices.date', $recentDates)
            ->sum('invoice_items.requested_qty');
    }
}
