<?php
// app/Services/Purchasing/Reports/PurchaseValueReportService.php
namespace App\Services\Purchasing\Reports;

use App\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;

/**
 * 🎯 "تقرير قيمة المشتريات" — يُقيِّم فقط الكميات المستلَمة فعلياً
 * (received_qty)، لا المقترحة أو المعتمَدة — فاتورة لم يبدأ استلامها
 * إطلاقاً (received_qty = 0 دائماً بحالتي Draft/Approved/Sent) لا تظهر
 * هنا مهما كانت قديمة.
 *
 * التجميع الرئيسي "سبب الشراء" — مقروء من explanation_factors->source
 * المُخزَّن أصلاً بكل سطر وقت التوليد (راجع RequiredQuantityProvider
 * ومشتقّاته)، لا عمود مستقل جديد.
 */
class PurchaseValueReportService
{
    private const REASON_LABELS = [
        'shortages'      => 'نواقص المناقلات',
        'standard_stock' => 'المخزون القياسي (مبيعات)',
        'combined'       => 'كلاهما معاً',
    ];

    public function build(?string $dateFrom = null, ?string $dateTo = null): object
    {
        $rows = PurchaseOrderItem::query()
            ->join('products', 'products.id', '=', 'purchase_order_items.product_id')
            ->where('purchase_order_items.received_qty', '>', 0)
            ->when($dateFrom, fn($q) => $q->whereDate('purchase_order_items.updated_at', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->whereDate('purchase_order_items.updated_at', '<=', $dateTo))
            ->select(
                'purchase_order_items.received_qty',
                'purchase_order_items.unit_cost',
                'purchase_order_items.explanation_factors',
                'products.id as product_id',
                'products.name as product_name',
                'products.company',
            )
            ->get()
            ->map(function ($row) {
                $factors = $row->explanation_factors ?? [];
                $row->reason = $factors['source'] ?? 'unknown';
                $row->line_value = round($row->received_qty * $row->unit_cost, 2);
                return $row;
            });

        $grouped = $rows->groupBy('reason')->map(function (Collection $items, string $reason) {
            $products  = $items->pluck('product_name', 'product_id')->unique();
            $companies = $items->pluck('company')->filter()->unique()->sort()->values();

            return (object) [
                'reason_key'    => $reason,
                'reason_label'  => self::REASON_LABELS[$reason] ?? 'غير محدَّد',
                'total_value'   => round($items->sum('line_value'), 2),
                'product_count' => $products->count(),
                'product_names' => $products->values()->all(),
                'company_count' => $companies->count(),
                'company_names' => $companies->all(),
            ];
        })->sortByDesc('total_value')->values();

        $totals = (object) [
            'total_value'   => round($rows->sum('line_value'), 2),
            'product_count' => $rows->pluck('product_id')->unique()->count(),
            'company_count' => $rows->pluck('company')->filter()->unique()->count(),
        ];

        return (object) ['rows' => $grouped, 'totals' => $totals];
    }
}
