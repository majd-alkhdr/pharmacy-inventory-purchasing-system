<?php

namespace App\Services\Inventory;

use App\Imports\ShortagesImport;
use App\Models\TemporaryShortage;
use App\Services\Inventory\Support\RecordsImportSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 🎯 المسؤولية الوحيدة: قراءة ملف النواقص التاريخية، ومحاولة مطابقتها
 * تلقائياً (بالباركود، أو بالاسم+الشركة، أو بذاكرة المطابقة السابقة).
 * توليد سجلات shortages الفعلية هو مسؤولية ShortageMappingService (بعد
 * إكمال أي مطابقة يدوية مطلوبة) — لذلك فصلناهما.
 */
class ShortageImportService
{
    use RecordsImportSession;

    public function __construct(
        private readonly ShortageMappingService $mappingService,
    ) {}

    public function processShortagesImport(string $sessionId, string $filePath): void
    {
        try {
            $import = new ShortagesImport($sessionId);
            Excel::import($import, Storage::path($filePath));

            if ($import->rowsRead === 0) {
                TemporaryShortage::forSession($sessionId)->delete();
                $this->markSession($sessionId, 'failed', 'الملف فارغ أو لا يحتوي على بيانات صالحة.');
                Storage::delete($filePath);
                return;
            }

            $this->matchByBarcodeAndName($sessionId);
            $this->mappingService->applyPharmacyMappingMemory($sessionId);
            $this->mappingService->applyProductMappingMemory($sessionId);

            $unmapped = $this->getUnmappedShortages($sessionId);
            Storage::delete($filePath);

            if ($unmapped['pharmacies']->isNotEmpty() || $unmapped['products']->isNotEmpty()) {
                $this->markSession($sessionId, 'needs_mapping', 'توجد أسماء غير مطابقة تحتاج مراجعة يدوية.');
                return;
            }

            $message = $this->mappingService->finalizeShortagesImport($sessionId);
            $this->markSession($sessionId, 'completed', $message);
        } catch (\Exception $e) {
            TemporaryShortage::forSession($sessionId)->delete();
            Log::error("Shortages import failed [{$sessionId}]: " . $e->getMessage());
            $this->markSession($sessionId, 'failed', 'حدث خطأ: ' . $e->getMessage());
        }
    }

    public function getUnmappedShortages(string $sessionId): array
    {
        return [
            'pharmacies' => TemporaryShortage::forSession($sessionId)->unmappedPharmacies()->distinct()->pluck('pharmacy_name'),
            'products'   => TemporaryShortage::forSession($sessionId)->unmappedProducts()->distinct()
                ->select('barcode', 'material_name', 'company')->get(),
        ];
    }

    private function matchByBarcodeAndName(string $sessionId): void
    {
        DB::statement("
            UPDATE temporary_shortages ts JOIN pharmacies p ON ts.pharmacy_name = p.name
            SET ts.pharmacy_id = p.id WHERE ts.session_id = ?
        ", [$sessionId]);

        DB::statement("
            UPDATE temporary_shortages ts JOIN products pr ON ts.barcode = pr.barcode
            SET ts.product_id = pr.id WHERE ts.session_id = ? AND ts.barcode != '' AND ts.product_id IS NULL
        ", [$sessionId]);

        DB::statement("
            UPDATE temporary_shortages ts JOIN products pr ON ts.material_name = pr.name AND ts.company = pr.company
            SET ts.product_id = pr.id WHERE ts.session_id = ? AND ts.product_id IS NULL
        ", [$sessionId]);
    }
}
