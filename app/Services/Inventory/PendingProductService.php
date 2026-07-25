<?php

namespace App\Services\Inventory;

use App\Models\CentralInventory;
use App\Models\PendingInventoryItem;
use App\Models\Product;
use App\Models\TemporarySale;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 المسؤولية الوحيدة: تحويل "المواد المعلّقة" (pending_inventory_items)
 * التي وافق عليها المستخدم إلى منتجات حقيقية بجدول products، ثم متابعة
 * تسلسل المعالجة (ترميم نواقص → مطابقة مبيعات إن وجدت).
 *
 * تعتمد (Composition) على ShortageFulfillmentService و SalesMappingService
 * بدل تكرار منطقهما — بالضبط كما InventoryUploadService.
 */
class PendingProductService
{
    public function __construct(
        private readonly ShortageFulfillmentService $shortageFulfillment,
        private readonly SalesMappingService $salesMapping,
    ) {}

    public function createPendingProducts(string $sessionId): array
    {
        DB::beginTransaction();
        try {
            $pendingItems = PendingInventoryItem::where('session_id', $sessionId)->get();

            foreach ($pendingItems as $item) {
                $product = Product::updateOrCreate(
                    ['barcode' => $item->barcode],
                    ['name' => $item->material_name, 'company' => $item->company]
                );
                CentralInventory::updateOrCreate(
                    ['product_id' => $product->id],
                    ['quantity' => $item->quantity]
                );
            }

            PendingInventoryItem::where('session_id', $sessionId)->delete();

            $shortageResult = $this->shortageFulfillment->fulfillActiveShortages();

            $hasSales = TemporarySale::forSession($sessionId)->exists();

            if (!$hasSales) {
                DB::commit();
                $msg = $shortageResult['fulfilled_count'] > 0
                    ? "✅ تم إنشاء المواد الجديدة وترميم {$shortageResult['fulfilled_count']} نقص."
                    : '✅ تم إنشاء المواد الجديدة. لا توجد نواقص معلقة حالياً.';
                return ['needs_mapping' => false, 'message' => $msg];
            }

            $this->salesMapping->matchAutomatically($sessionId);
            DB::commit();

            $unmapped = $this->salesMapping->getUnmappedSales($sessionId);
            if ($unmapped['pharmacies']->isNotEmpty() || $unmapped['products']->isNotEmpty()) {
                return ['needs_mapping' => true, ...$unmapped];
            }

            return ['needs_mapping' => false, 'generate' => true];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
