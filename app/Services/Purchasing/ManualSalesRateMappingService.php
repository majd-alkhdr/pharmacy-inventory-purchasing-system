<?php

namespace App\Services\Purchasing;

use App\Models\PendingManualSalesRate;
use App\Models\Product;
use App\Models\ProductManualSalesRate;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 يُستدعى من شاشة المطابقة اليدوية بعد رفع ملف مبيعات لم يُطابِق كل
 * صفوفه تلقائياً — لكل صف معلَّق، المشرف يختار أحد مسارين:
 *   1) 'new'       → إنشاء منتج جديد فعلاً بنفس (الاسم، الشركة) المرفوعَين.
 *   2) <productId> → ربط الصف بمنتج موجود اختاره المشرف (مثال شائع:
 *      فرق تشكيل بسيط بالاسم يمنع المطابقة التلقائية).
 *   3) 'skip' أو غياب القرار → تجاهل هذا الصف نهائياً، بلا أي أثر.
 */
class ManualSalesRateMappingService
{
    /**
     * @param array<int,string> $decisions [pending_id => 'new'|product_id|'skip']
     * @return array{resolved:int, created:int}
     */
    public function resolve(string $sessionId, array $decisions, array $barcodes = []): array
    {
        $pendingRows = PendingManualSalesRate::forSession($sessionId)->get()->keyBy('id');

        $resolved = 0;
        $created = 0;

        DB::transaction(function () use ($pendingRows, $decisions, $barcodes, &$resolved, &$created) {
            foreach ($decisions as $pendingId => $choice) {
                $pending = $pendingRows->get((int) $pendingId);

                if (!$pending || $choice === 'skip' || !$choice) {
                    continue;
                }

                if ($choice === 'new') {
                    $product = Product::create([
                        'barcode' => trim((string) ($barcodes[$pendingId] ?? '')),
                        'name'    => $pending->material_name,
                        'company' => $pending->company,
                    ]);
                    $created++;
                } else {
                    $product = Product::find((int) $choice);
                    if (!$product) {
                        continue; // 🩹 حماية: معرّف منتج غير صالح وصل بالخطأ
                    }
                }

                ProductManualSalesRate::updateOrCreate(
                    ['product_id' => $product->id],
                    ['sales_quantity' => $pending->quantity, 'period_days' => $pending->period_days]
                );

                $resolved++;
            }

            PendingManualSalesRate::whereIn('id', $pendingRows->keys())->delete();
        });

        return ['resolved' => $resolved, 'created' => $created];
    }
}
