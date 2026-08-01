<?php

namespace App\Services\Inventory;

use App\Models\CentralInventory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\TemporarySale;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 المسؤولية الوحيدة: تحويل صفوف temporary_sales المُطابَقة (بعد ما صار
 * عندها pharmacy_id و product_id) إلى فواتير حقيقية + سجلات نواقص للعجز.
 *
 * 🎯 تسجيل النواقص نفسها (مع الدمج بدل التكرار) مفوَّض بالكامل لـ
 * ShortageRecorderService — هذا الصنف لا "يعرف" كيف يُدمَج النقص، فقط
 * "متى" يجب تسجيله (بعد اكتشاف عجز بالكمية).
 */
class InvoiceGenerationService
{
    public function __construct(
        private readonly ShortageRecorderService $shortageRecorder,
    ) {}

    public function generateInvoicesFromTemp(string $sessionId): array
    {
        $aggregatedSales = TemporarySale::forSession($sessionId)
            ->select('pharmacy_id', 'product_id', DB::raw('SUM(quantity) as total_quantity'))
            ->groupBy('pharmacy_id', 'product_id')
            ->get()
            ->groupBy('pharmacy_id');

        if ($aggregatedSales->isEmpty()) {
            TemporarySale::forSession($sessionId)->delete();
            return ['success' => false, 'message' => 'لم يتم العثور على بيانات مبيعات صالحة.'];
        }

        DB::beginTransaction();
        try {
            $allProductIds = $aggregatedSales->flatten()->pluck('product_id')->unique()->values();
            $allStocks     = CentralInventory::whereIn('product_id', $allProductIds)->lockForUpdate()->get()->keyBy('product_id');

            $stockUpdates  = [];
            $invoiceItems  = [];
            $newShortages  = collect(); // 🎯 Collection بدل array — تُمرَّر مباشرة لـ ShortageRecorderService

            foreach ($aggregatedSales as $pharmacyId => $items) {
                $invoice = Invoice::create([
                    'pharmacy_id' => $pharmacyId,
                    'date'        => now()->toDateString(),
                    'status'      => 'processed',
                ]);

                foreach ($items as $item) {
                    $productId     = $item->product_id;
                    $totalRequired = $item->total_quantity;
                    $currentQty    = $stockUpdates[$productId] ?? ($allStocks->get($productId)?->quantity ?? 0);
                    $suppliedQty   = min($totalRequired, $currentQty);
                    $shortageQty   = $totalRequired - $suppliedQty;
                    $stockUpdates[$productId] = $currentQty - $suppliedQty;

                    $invoiceItems[] = [
                        'invoice_id'    => $invoice->id,
                        'product_id'    => $productId,
                        'requested_qty' => $totalRequired,
                        'supplied_qty'  => $suppliedQty,
                        'shortage_qty'  => $shortageQty,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ];

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

            // 🎯 التعديل الجوهري: بدل Shortage::insert() الخام (كان يُنشئ
            // صفاً جديداً كل يوم حتى لو موجود نقص معلّق أصلاً لنفس المادة)،
            // نستدعي الخدمة التي تدمج مع أي نقص pending موجود مسبقاً.
            $this->shortageRecorder->recordMany($newShortages);

            foreach ($stockUpdates as $productId => $newQty) {
                CentralInventory::where('product_id', $productId)->update(['quantity' => max(0, $newQty)]);
            }

            TemporarySale::forSession($sessionId)->delete();
            DB::commit();

            return ['success' => true, 'message' => '🎉 تم توليد فواتير المناقلة بنجاح.'];
        } catch (\Exception $e) {
            DB::rollBack();
            return ['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()];
        }
    }
}