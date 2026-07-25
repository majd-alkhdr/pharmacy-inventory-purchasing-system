<?php

namespace App\Services\Inventory;

use App\Imports\CentralInventoryImport;
use App\Imports\SalesImport;
use App\Models\PendingInventoryItem;
use App\Models\TemporarySale;
use App\Models\ImportSession;
use App\Services\Inventory\Support\RecordsImportSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 🎯 هذه خدمة "منسّق" (Orchestrator) — مسؤوليتها الوحيدة هي ترتيب تسلسل
 * الخطوات (جرد → نواقص → مبيعات)، وهي تُفوّض كل خطوة فعلية لخدمة متخصصة
 * حقنّاها عبر الـ Constructor. هي لا "تعرف" كيف تُطابق مبيعة أو كيف تُرمم
 * نقصاً — فقط "متى" يجب استدعاء كل خدمة. هذا هو نمط الـ Orchestrator
 * الشائع في العمارات النظيفة (Clean Architecture).
 */
class InventoryUploadService
{
    use RecordsImportSession;

    public function __construct(
        private readonly CentralInventoryStockService $stockService,
        private readonly ShortageFulfillmentService $shortageFulfillment,
        private readonly SalesMappingService $salesMapping,
    ) {}

    public function processInventoryUpload(string $sessionId, string $invPath, ?string $salesPath): void
    {
        $hasSales = (bool) $salesPath;

        try {
            $invImport = new CentralInventoryImport();
            Excel::import($invImport, Storage::path($invPath));
            $this->stockService->applyInventoryReplace($invImport->matched);

            if (!empty($invImport->unmatched)) {
                $this->savePendingInventoryItems($sessionId, $invImport->unmatched);
            }

            if (PendingInventoryItem::where('session_id', $sessionId)->count() > 0) {
                $this->handlePendingProductsBranch($sessionId, $hasSales, $salesPath, $invPath);
                return;
            }

            $shortageResult = $this->shortageFulfillment->fulfillActiveShortages();

            if (!$hasSales) {
                $msg = $shortageResult['fulfilled_count'] > 0
                    ? "✅ تم ترميم {$shortageResult['fulfilled_count']} نقص وإنشاء الفواتير الإلحاقية المقابلة."
                    : '✅ تم تحديث المخزون. لا توجد نواقص معلقة لترميمها حالياً.';

                $this->markSession($sessionId, 'completed', $msg);
                Storage::delete($invPath);
                return;
            }

            Excel::import(new SalesImport($sessionId), Storage::path($salesPath));
            $this->salesMapping->finishSalesMatching($sessionId);

            Storage::delete($invPath);
            Storage::delete($salesPath);
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            TemporarySale::forSession($sessionId)->delete();
            PendingInventoryItem::where('session_id', $sessionId)->delete();

            Log::error("Inventory upload failed [{$sessionId}]: " . $e->getMessage());
            $this->markSession($sessionId, 'failed', 'حدث خطأ: ' . $e->getMessage());
        }
    }

    private function savePendingInventoryItems(string $sessionId, array $unmatched): void
    {
        foreach ($unmatched as $item) {
            PendingInventoryItem::create([
                'session_id'    => $sessionId,
                'barcode'       => $item['barcode'],
                'material_name' => $item['material_name'],
                'company'       => $item['company'],
                'quantity'      => $item['quantity'],
            ]);
        }
    }

    private function handlePendingProductsBranch(string $sessionId, bool $hasSales, ?string $salesPath, string $invPath): void
    {
        // 🩹 بديل session(['pending_has_sales_...']) غير المتاح داخل Job — عمود صريح.
        ImportSession::where('session_id', $sessionId)->update(['has_sales' => $hasSales]);

        if ($hasSales) {
            Excel::import(new SalesImport($sessionId), Storage::path($salesPath));
        }

        $pendingCount = PendingInventoryItem::where('session_id', $sessionId)->count();
        $this->markSession($sessionId, 'needs_pending_products', "تم العثور على {$pendingCount} مادة جديدة بملف الجرد.");

        Storage::delete($invPath);
        if ($salesPath) {
            Storage::delete($salesPath);
        }
    }
}
