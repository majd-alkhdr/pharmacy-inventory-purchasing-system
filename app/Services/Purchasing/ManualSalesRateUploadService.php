<?php

namespace App\Services\Purchasing;

use App\Models\PendingManualSalesRate;
use App\Models\Product;
use App\Models\ProductManualSalesRate;
use Illuminate\Support\Collection;

/**
 * 🎯 المسؤولية الوحيدة: تطبيق ملف المبيعات اليدوي على قاعدة البيانات —
 * مطابقة بـ(اسم المادة + اسم الشركة) لا الباركود (مصدر البيانات خارجي،
 * غالباً تقرير مبيعات بلا باركود). كل صف غير مُطابَق يُحفَظ بجدول معلَّق
 * بانتظار قرار المشرف الصريح (راجع ManualSalesRateMappingService).
 *
 * 🩹 نفس نمط PurchaseInventoryUploadService::groupDuplicateBarcodes()
 * بالضبط: نجمع الصفوف المكرَّرة (نفس الاسم+الشركة) بنفس الملف أولاً،
 * فلا نُنشئ صفَّين معلَّقين لنفس المادة لو ظهرت مرتين بالملف بالخطأ.
 */
class ManualSalesRateUploadService
{
    /**
     * @param Collection<int, array{material_name:string, company:string, quantity:int}> $rows
     * @return array{matched:int, pending:int, session_id:?string}
     */
    public function process(Collection $rows, int $periodDays): array
    {
        if ($rows->isEmpty()) {
            return ['matched' => 0, 'pending' => 0, 'session_id' => null];
        }

        $grouped = $this->groupDuplicateRows($rows);

        $names = $grouped->pluck('material_name')->unique()->values();
        $companies = $grouped->pluck('company')->unique()->values();

        // 🎯 استعلام واحد مجمَّع لكل المنتجات المرشَّحة، بمفتاح مركّب
        // بالذاكرة بعده — لا استعلام منفصل لكل صف بالملف.
        $candidateProducts = Product::whereIn('name', $names)
            ->whereIn('company', $companies)
            ->get()
            ->keyBy(fn(Product $p) => $this->matchKey($p->name, $p->company));

        $sessionId = uniqid('manual_sales_rate_');
        $now = now();
        $matched = 0;
        $pendingRows = [];

        foreach ($grouped as $row) {
            $product = $candidateProducts->get($this->matchKey($row['material_name'], $row['company']));

            if ($product) {
                $this->upsertRate($product->id, $row['quantity'], $periodDays);
                $matched++;
                continue;
            }

            $pendingRows[] = [
                'session_id'    => $sessionId,
                'material_name' => $row['material_name'],
                'company'       => $row['company'],
                'quantity'      => $row['quantity'],
                'period_days'   => $periodDays,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        if (!empty($pendingRows)) {
            PendingManualSalesRate::insert($pendingRows);
        }

        return [
            'matched'    => $matched,
            'pending'    => count($pendingRows),
            'session_id' => count($pendingRows) > 0 ? $sessionId : null,
        ];
    }

    private function groupDuplicateRows(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn(array $row) => $this->matchKey($row['material_name'], $row['company']))
            ->map(fn(Collection $group) => [
                'material_name' => $group->first()['material_name'],
                'company'       => $group->first()['company'],
                'quantity'      => $group->sum('quantity'), // 🎯 تكرار المادة بالملف = مبيعات مُجزَّأة، تُجمَع
            ])
            ->values();
    }

    private function upsertRate(int $productId, int $quantity, int $periodDays): void
    {
        ProductManualSalesRate::updateOrCreate(
            ['product_id' => $productId],
            ['sales_quantity' => $quantity, 'period_days' => $periodDays]
        );
    }

    /** 🎯 نقطة مطابقة واحدة — trim فقط، بلا تطبيع حالة أحرف إضافي (نفس حذر MinimumStockImport) */
    private function matchKey(string $name, string $company): string
    {
        return trim($name) . '|||' . trim($company);
    }
}
