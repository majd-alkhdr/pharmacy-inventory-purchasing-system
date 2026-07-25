<?php

namespace App\Services\Inventory;

use App\Enums\ShortageStatus;
use App\Models\CentralInventory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Shortage;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 المسؤولية الوحيدة: أخذ النواقص المعلقة (FIFO حسب created_at) ومحاولة
 * سدّها من رصيد central_inventories الحالي، مع توليد فواتير إلحاقية.
 *
 * لماذا استحقت خدمة مستقلة بالذات؟ لأنها كانت تُستدعى من مكانين مختلفين
 * تماماً بالكود الأصلي (processInventoryUpload و createPendingProducts)
 * وأيضاً من InventoryController::fulfillShortages() مباشرة (زر يدوي).
 * أي منطق يُستدعى من أكثر من نقطة دخول هو أفضل مرشح لخدمة مستقلة (DRY + DIP).
 */
class ShortageFulfillmentService
{
    public function fulfillActiveShortages(): array
    {
        $pending = Shortage::pending()->orderBy('created_at', 'asc')->get();

        if ($pending->isEmpty()) {
            return ['fulfilled_count' => 0, 'partial_count' => 0];
        }

        $productIds = $pending->pluck('product_id')->unique()->values();

        DB::beginTransaction();

        $stocks = CentralInventory::whereIn('product_id', $productIds)->lockForUpdate()->get()->keyBy('product_id');

        $todayDate       = now()->toDateString();
        $fulfilledCount  = 0;
        $partialCount    = 0;
        $invoiceCache    = [];
        $invoiceItems    = [];
        $stockUpdates    = [];
        $shortageUpdates = [];

        foreach ($pending as $shortage) {
            $stock     = $stocks->get($shortage->product_id);
            $available = $stock ? $stock->quantity : 0;
            if ($available <= 0) {
                continue;
            }

            $currentQty   = $stockUpdates[$shortage->product_id] ?? $available;
            $qtyToFulfill = min($shortage->quantity, $currentQty);
            if ($qtyToFulfill <= 0) {
                continue;
            }

            $stockUpdates[$shortage->product_id] = $currentQty - $qtyToFulfill;

            $cacheKey = $shortage->pharmacy_id . '_' . $todayDate;
            if (!isset($invoiceCache[$cacheKey])) {
                $invoice = Invoice::firstOrCreate([
                    'pharmacy_id' => $shortage->pharmacy_id,
                    'status'      => 'supplementary_invoice',
                    'date'        => $todayDate,
                ]);
                $invoiceCache[$cacheKey] = $invoice->id;
            }

            $invoiceItems[] = [
                'invoice_id'    => $invoiceCache[$cacheKey],
                'product_id'    => $shortage->product_id,
                'requested_qty' => $shortage->quantity,
                'supplied_qty'  => $qtyToFulfill,
                'shortage_qty'  => $shortage->quantity - $qtyToFulfill,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];

            $remaining = $shortage->quantity - $qtyToFulfill;
            $shortageUpdates[$shortage->id] = [
                'quantity' => $remaining,
                'status'   => $remaining <= 0 ? ShortageStatus::Fulfilled : ShortageStatus::Pending,
                'notes'    => $shortage->appendResolutionNote("رُمِّم بتاريخ {$todayDate} ({$qtyToFulfill} وحدة)"),
            ];

            $remaining <= 0 ? $fulfilledCount++ : $partialCount++;
        }

        if (!empty($invoiceItems)) {
            foreach (array_chunk($invoiceItems, 500) as $chunk) {
                InvoiceItem::insert($chunk);
            }
        }

        foreach ($stockUpdates as $productId => $newQty) {
            CentralInventory::where('product_id', $productId)->update(['quantity' => $newQty]);
        }
        foreach ($shortageUpdates as $shortageId => $data) {
            Shortage::where('id', $shortageId)->update($data);
        }

        DB::commit();

        return ['fulfilled_count' => $fulfilledCount, 'partial_count' => $partialCount];
    }
}
