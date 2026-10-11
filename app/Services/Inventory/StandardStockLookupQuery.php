<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\PharmacyInventory;
use App\Models\PharmacyMinimumStock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 🎯 (ب.1) "ما قيمة المخزون القياسي للمادة X بالصيدلية Y؟" — مع الرصيد الحالي المرفوع بجانبه،
 * لأن السؤال الحقيقي خلف السؤال غالباً "لماذا رُمِّمت/لم تُرمَّم؟" والجواب يحتاج الرقمين معاً.
 *
 * يُعيد صفوف PharmacyMinimumStock مع خاصيتين وقت التشغيل:
 *   current_stock (null = لا صف رصيد مرفوع لهذا الزوج — المحرك يتجاهله ولا يفترضه صفراً)
 *   effective_pack_size / effective_min_gap (القيم الفعّالة بعد تجاوزات الزوج — ما يستخدمه المحرك فعلاً)
 */
final class StandardStockLookupQuery
{
    public function search(?int $productId, ?int $pharmacyId, int $perPage = 50): LengthAwarePaginator
    {
        $page = PharmacyMinimumStock::query()
            ->with(['product:id,name,company,barcode,pack_size,min_reorder_gap', 'pharmacy:id,name'])
            ->when($productId, fn ($q) => $q->where('product_id', $productId))
            ->when($pharmacyId, fn ($q) => $q->where('pharmacy_id', $pharmacyId))
            ->orderBy('pharmacy_id')
            ->orderBy('product_id')
            ->paginate($perPage)
            ->withQueryString();

        // استعلام واحد للأرصدة الحالية لكل صفوف الصفحة (لا N+1).
        $stock = PharmacyInventory::query()
            ->whereIn('pharmacy_id', $page->getCollection()->pluck('pharmacy_id')->unique())
            ->whereIn('product_id', $page->getCollection()->pluck('product_id')->unique())
            ->get(['pharmacy_id', 'product_id', 'quantity'])
            ->mapWithKeys(fn (PharmacyInventory $r) => ["{$r->pharmacy_id}_{$r->product_id}" => (int) $r->quantity]);

        $page->getCollection()->each(function (PharmacyMinimumStock $row) use ($stock): void {
            $row->current_stock = $stock->get("{$row->pharmacy_id}_{$row->product_id}");
            $row->effective_pack_size = $row->effectivePackSize();
            $row->effective_min_gap = $row->effectiveMinReorderGap();
        });

        return $page;
    }
}
