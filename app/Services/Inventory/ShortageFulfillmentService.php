<?php

namespace App\Services\Inventory;

use App\Enums\ShortageStatus;
use App\Models\CentralInventory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMinimumStock;
use App\Models\Shortage;
use Illuminate\Support\Facades\DB;

/**
 * 🆕 (ب.5) ══ فاتورة ترميم النواقص لها مصدر بيانات واحد فقط: جدول النواقص/العجز ══
 *
 * كانت هذه الخدمة تخلط مصدرين: (1) النواقص المعلَّقة مقابل المخزون المركزي، و(2) فائض
 * صيدليات أخرى — يصل عبر المناقلات العكسية التي كانت تُضاف للمركزي لحظة تسجيلها، ثم
 * تُستهلك هنا كـ"إعادة توزيع" وتُحذف/تُنقص سجلاتها (loadAvailableReverseTransferSources
 * وحلقة الاستهلاك). بقرار صريح متفق عليه: **حُذف** ذلك المنطق بالكامل (لا تجميد — إلغاء
 * صريح لسلوك سابق). تغطية النواقص من فائض الصيدليات أصبحت مسؤولية حصرية لمقترحات
 * "المناقلة بين الصيدليات" (InterPharmacyTransferPlanner) عبر SurplusEngine.
 *
 * النتيجة: الفاتورة الإلحاقية = نواقص × مخزون مركزي فقط، والعكسيات لم تعد تمس المركزي
 * إلا بتنفيذ صريح (TransferExecutionService). كل نوع فاتورة بمصدر واحد، بلا تداخل.
 * عمودا is_redistributed/redistribution_sources يبقيان للفواتير القديمة (يُكتبان false/null).
 */
class ShortageFulfillmentService
{
    /**
     * يرمّم النواقص المعلَّقة من المخزون المركزي فقط (راجع تعليق الكلاس، ب.5)، مع سقف
     * المخزون القياسي لكل زوج وتقريب العبوة — بلا أي تغيير بهذه القواعد.
     *
     * @param array<string> $excludedCompanies
     */
    public function fulfillActiveShortages(array $excludedCompanies = []): array
    {
        $pendingQuery = Shortage::pending()
            ->orderBy('created_at', 'asc')
            ->with('product:id,company,pack_size');

        if (!empty($excludedCompanies)) {
            $pendingQuery->whereHas('product', fn($q) => $q->whereNotIn('company', $excludedCompanies));
        }

        $pending = $pendingQuery->get();

        if ($pending->isEmpty()) {
            return ['fulfilled_count' => 0, 'partial_count' => 0, 'blocked_by_minimum_count' => 0];
        }

        $pairs = $pending->map(fn(Shortage $s) => ['pharmacy_id' => $s->pharmacy_id, 'product_id' => $s->product_id]);
        $minimumsByPair = PharmacyMinimumStock::bulkForPairs($pairs);
        $pharmacyStockByPair = $this->bulkPharmacyStock($pairs);

        $blockedByMinimumCount = 0;
        $fulfillmentCapByShortageId = [];

        $pending = $pending->filter(function (Shortage $shortage) use ($minimumsByPair, $pharmacyStockByPair, &$blockedByMinimumCount, &$fulfillmentCapByShortageId) {
            $key = "{$shortage->pharmacy_id}_{$shortage->product_id}";
            $standardLevel = $minimumsByPair[$key] ?? null;

            if ($standardLevel === null) {
                return true;
            }

            $currentPharmacyStock = $pharmacyStockByPair[$key] ?? null;

            if ($currentPharmacyStock === null) {
                return false;
            }

            $gapToStandardLevel = $standardLevel - $currentPharmacyStock;

            if ($gapToStandardLevel <= 0) {
                $blockedByMinimumCount++;
                return false;
            }

            $packSize = (int) ($shortage->product->pack_size ?? 0);
            $effectiveCap = $packSize > 0 ? intdiv($gapToStandardLevel, $packSize) * $packSize : $gapToStandardLevel;

            if ($effectiveCap <= 0) {
                $blockedByMinimumCount++;
                return false;
            }

            $fulfillmentCapByShortageId[$shortage->id] = $effectiveCap;

            return true;
        })->values();

        if ($pending->isEmpty()) {
            return ['fulfilled_count' => 0, 'partial_count' => 0, 'blocked_by_minimum_count' => $blockedByMinimumCount];
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

            $currentQty = $stockUpdates[$shortage->product_id] ?? $available;

            $standardLevelCap = $fulfillmentCapByShortageId[$shortage->id] ?? PHP_INT_MAX;
            $rawQtyToFulfill = min($shortage->quantity, $currentQty, $standardLevelCap);

            $packSize = (int) ($shortage->product->pack_size ?? 0);
            $qtyToFulfill = $packSize > 0 ? intdiv($rawQtyToFulfill, $packSize) * $packSize : $rawQtyToFulfill;

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
                'invoice_id'              => $invoiceCache[$cacheKey],
                'product_id'              => $shortage->product_id,
                'requested_qty'           => $shortage->quantity,
                'supplied_qty'            => $qtyToFulfill,
                'shortage_qty'            => $shortage->quantity - $qtyToFulfill,
                // 🆕 (ب.5) لا إعادة توزيع بعد اليوم — العمودان يبقيان لعرض الفواتير القديمة فقط.
                'is_redistributed'        => false,
                'redistribution_sources'  => null,
                'created_at'              => now(),
                'updated_at'              => now(),
            ];

            $remaining = $shortage->quantity - $qtyToFulfill;

            $note = "رُمِّم بتاريخ {$todayDate} ({$qtyToFulfill} وحدة)";
            if (isset($fulfillmentCapByShortageId[$shortage->id]) && $remaining > 0) {
                $note .= ' — توقّف الترميم عند الوصول للمخزون القياسي الثابت لهذه الصيدلية (أو أقرب عبوة كاملة دونه)؛ الباقي معلَّق حتى ترتفع الحاجة الفعلية.';
            }

            $shortageUpdates[$shortage->id] = [
                'quantity' => $remaining,
                'status'   => $remaining <= 0 ? ShortageStatus::Fulfilled : ShortageStatus::Pending,
                'notes'    => $shortage->appendResolutionNote($note),
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

        return ['fulfilled_count' => $fulfilledCount, 'partial_count' => $partialCount, 'blocked_by_minimum_count' => $blockedByMinimumCount];
    }

    private function bulkPharmacyStock($pairs): array
    {
        if ($pairs->isEmpty()) {
            return [];
        }

        $pharmacyIds = $pairs->pluck('pharmacy_id')->unique();
        $productIds  = $pairs->pluck('product_id')->unique();

        return PharmacyInventory::whereIn('pharmacy_id', $pharmacyIds)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy(fn(PharmacyInventory $row) => "{$row->pharmacy_id}_{$row->product_id}")
            ->map(fn(PharmacyInventory $row) => $row->quantity)
            ->all();
    }
}
