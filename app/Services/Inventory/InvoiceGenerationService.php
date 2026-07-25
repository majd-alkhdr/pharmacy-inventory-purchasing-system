<?php

namespace App\Services\Inventory;

use App\Models\CentralInventory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Shortage;
use App\Models\TemporarySale;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 المسؤولية الوحيدة: تحويل صفوف temporary_sales المُطابَقة (بعد ما صار
 * عندها pharmacy_id و product_id) إلى فواتير حقيقية + سجلات نواقص للعجز.
 */
class InvoiceGenerationService
{
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
            $newShortages  = [];

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
                        $newShortages[] = [
                            'pharmacy_id' => $pharmacyId,
                            'product_id'  => $productId,
                            'quantity'    => $shortageQty,
                            'status'      => 'pending',
                            'created_at'  => now(),
                            'updated_at'  => now(),
                        ];
                    }
                }
            }

            foreach (array_chunk($invoiceItems, 500) as $chunk) {
                InvoiceItem::insert($chunk);
            }
            if (!empty($newShortages)) {
                foreach (array_chunk($newShortages, 500) as $chunk) {
                    Shortage::insert($chunk);
                }
            }

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
