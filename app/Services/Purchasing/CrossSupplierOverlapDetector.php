<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 يطبّق قرار المستخدم: "تحذير فقط، وليس استبعاداً" — لو مسودة معلّقة
 * لمورد آخر تحتوي أصلاً منتجاً من نفس الشركة المصنّعة، نُظهر تحذيراً
 * واضحاً على السطر المقابل بالفاتورة الجديدة، لكن لا نمنع توليدها.
 * القرار النهائي (هل هذا ازدواجية فعلية أم مقصودة؟) يبقى بيد المشرف.
 *
 * 🎓 لماذا نعتمد على products.company مباشرة (عبر بيانات المسودات
 * الفعلية) بدل الاعتماد فقط على suppliers.distributed_companies؟ لأن
 * الأخير قد لا يكون مُحدَّثاً بدقة لكل مورد (حقل وصفي يُدخله المستخدم
 * يدوياً)، بينما "هل يوجد فعلاً سطر بمسودة أخرى لنفس الشركة؟" هو واقع
 * البيانات الحقيقي ولا يعتمد على دقة إدخال حقل وصفي منفصل.
 *
 * 🩹 قرار أداء: استعلام واحد فقط يجلب كل (مورد+شركة) الظاهرة حالياً
 * بأي مسودة معلّقة بالنظام كله، ثم كل الفحص التالي بالذاكرة — بدل
 * استعلام منفصل لكل منتج (N+1) أثناء توليد فواتير قد تضم مئات الأصناف.
 */
class CrossSupplierOverlapDetector
{
    private ?Collection $draftCompanyRows = null;

    public function warningFor(string $company, int $targetSupplierId): ?string
    {
        if (!$company) {
            return null;
        }

        $conflicting = $this->loadDraftCompanyRows()
            ->where('company', $company)
            ->where('supplier_id', '!=', $targetSupplierId);

        if ($conflicting->isEmpty()) {
            return null;
        }

        $names = $conflicting->pluck('supplier_name')->unique()->implode('، ');

        return "⚠️ هذا المنتج (شركة \"{$company}\") مطروح أيضاً بمسودة معلّقة لمورد آخر: {$names}. "
            . 'راجع قبل الاعتماد لتفادي طلب نفس المادة من موردين اثنين بنفس الوقت.';
    }

    private function loadDraftCompanyRows(): Collection
    {
        return $this->draftCompanyRows ??= DB::table('purchase_orders as po')
            ->join('purchase_order_items as poi', 'poi.purchase_order_id', '=', 'po.id')
            ->join('products as p', 'p.id', '=', 'poi.product_id')
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->where('po.status', PurchaseOrderStatus::Draft->value)
            ->select('po.supplier_id', 's.name as supplier_name', 'p.company')
            ->distinct()
            ->get();
    }
}
