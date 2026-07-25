<?php

namespace App\Services\Inventory;

use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\TemporarySale;
use App\Services\Inventory\Support\BulkCaseUpdater;
use App\Services\Inventory\Support\RecordsImportSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 المسؤولية الوحيدة: مطابقة أسماء الصيدليات/المواد القادمة من ملف
 * المبيعات مع الجداول الحقيقية (pharmacies, products)، سواء تلقائياً (عبر
 * ذاكرة المطابقة بجدول pharmacy_mappings) أو يدوياً (من شاشة المطابقة).
 *
 * 🎯 لاحظ حقن InvoiceGenerationService بالـ Constructor بدل استدعاء دالة
 * داخلية: هذا هو الفرق بين "دمج كل شيء بكلاس واحد" (كما كان بالأصل) وبين
 * "تركيب خدمات صغيرة ببعضها" (Composition عبر Dependency Injection) —
 * المبدأ الخامس من SOLID (Dependency Inversion).
 */
class SalesMappingService
{
    use RecordsImportSession, BulkCaseUpdater;

    public function __construct(
        private readonly InvoiceGenerationService $invoiceGenerator
    ) {}

    public function getUnmappedSales(string $sessionId): array
    {
        return [
            'pharmacies' => TemporarySale::forSession($sessionId)->unmappedPharmacies()->distinct()->pluck('pharmacy_name'),
            'products'   => TemporarySale::forSession($sessionId)->unmappedProducts()->distinct()
                ->select('barcode', 'material_name', 'company')->get(),
        ];
    }

    /**
     * تُستدعى بعد اكتمال قراءة ملف الإكسل (المطابقة التلقائية عبر أسماء
     * متطابقة حرفياً + ذاكرة المطابقة)، ثم تقرر: هل نحتاج مطابقة يدوية؟
     * أم ننتقل مباشرة لتوليد الفواتير؟
     */
    public function finishSalesMatching(string $sessionId): void
    {
        $this->matchByExactNames($sessionId);
        $this->applyPharmacyMappingMemory($sessionId);

        $unmapped = $this->getUnmappedSales($sessionId);

        if ($unmapped['pharmacies']->isNotEmpty() || $unmapped['products']->isNotEmpty()) {
            $this->markSession($sessionId, 'needs_mapping', 'توجد أسماء غير مطابقة تحتاج مراجعة يدوية.');
            return;
        }

        $result = $this->invoiceGenerator->generateInvoicesFromTemp($sessionId);
        $this->markSession($sessionId, $result['success'] ? 'completed' : 'failed', $result['message']);
    }

    /** نسخة تُستخدم داخل transaction قائمة أصلاً (من PendingProductService) بدون تحديد حالة الجلسة النهائية */
    public function matchAutomatically(string $sessionId): void
    {
        $this->matchByExactNames($sessionId);
        $this->applyPharmacyMappingMemory($sessionId);
    }

    private function matchByExactNames(string $sessionId): void
    {
        DB::statement("
            UPDATE temporary_sales ts JOIN pharmacies p ON ts.pharmacy_name = p.name
            SET ts.pharmacy_id = p.id WHERE ts.session_id = ?
        ", [$sessionId]);

        DB::statement("
            UPDATE temporary_sales ts JOIN products pr ON ts.barcode = pr.barcode
            SET ts.product_id = pr.id WHERE ts.session_id = ?
        ", [$sessionId]);
    }

    private function applyPharmacyMappingMemory(string $sessionId): void
    {
        DB::statement("
            UPDATE temporary_sales ts JOIN pharmacy_mappings pm ON ts.pharmacy_name = pm.original_name
            SET ts.pharmacy_id = pm.pharmacy_id WHERE ts.session_id = ? AND ts.pharmacy_id IS NULL
        ", [$sessionId]);
    }

    /**
     * تُستدعى من شاشة المطابقة اليدوية بعد اختيار المستخدم لكل صف.
     */
    public function saveMapping(string $sessionId, array $pharmacies, array $products): array
    {
        DB::beginTransaction();
        try {
            [$skippedPharmacies, $newPharmacyNames, $manualPharmacyLinks] = $this->splitPharmacyChoices($pharmacies);
            $this->createNewPharmacies($newPharmacyNames);
            $this->persistPharmacyMappings($newPharmacyNames, $manualPharmacyLinks);

            DB::statement("
                UPDATE temporary_sales ts JOIN pharmacy_mappings pm ON ts.pharmacy_name = pm.original_name
                SET ts.pharmacy_id = pm.pharmacy_id WHERE ts.session_id = ?
            ", [$sessionId]);

            [$skippedProducts, $newProductsByBarcode, $manualProductLinks] = $this->splitProductChoices($products);
            $this->createNewProducts($newProductsByBarcode);

            DB::statement("
                UPDATE temporary_sales ts JOIN products pr ON ts.barcode = pr.barcode
                SET ts.product_id = pr.id WHERE ts.session_id = ? AND ts.product_id IS NULL
            ", [$sessionId]);

            $this->bulkUpdateByMap('temporary_sales', 'barcode', $manualProductLinks, 'product_id', $sessionId);

            DB::commit();

            if (count($skippedPharmacies) > 0 || count($skippedProducts) > 0) {
                $names = collect($skippedProducts)->pluck('material_name')
                    ->merge(collect($skippedPharmacies)->pluck('excel_name'))->filter()->implode('، ');
                return ['success' => false, 'message' => "⚠️ تعذّرت معالجة: {$names}"];
            }

            return $this->invoiceGenerator->generateInvoicesFromTemp($sessionId);
        } catch (\Exception $e) {
            DB::rollBack();
            return ['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()];
        }
    }

    private function splitPharmacyChoices(array $pharmacies): array
    {
        $skipped = [];
        $new     = [];
        $manual  = [];

        foreach ($pharmacies as $item) {
            $excelName = $item['excel_name'] ?? null;
            $mappedId  = $item['mapped_id'] ?? null;
            if (!$excelName || !$mappedId) {
                $skipped[] = $item;
                continue;
            }
            $mappedId === 'NEW' ? $new[] = $excelName : $manual[$excelName] = $mappedId;
        }

        return [$skipped, array_values(array_unique($new)), $manual];
    }

    private function createNewPharmacies(array $newPharmacyNames): void
    {
        if (empty($newPharmacyNames)) {
            return;
        }
        $now  = now();
        $rows = array_map(fn($name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now], $newPharmacyNames);
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('pharmacies')->insertOrIgnore($chunk);
        }
    }

    private function persistPharmacyMappings(array $newPharmacyNames, array $manualPharmacyLinks): void
    {
        $newPharmacyIdsByName = !empty($newPharmacyNames)
            ? Pharmacy::whereIn('name', $newPharmacyNames)->pluck('id', 'name')
            : collect();

        $now  = now();
        $rows = [];
        foreach ($newPharmacyNames as $name) {
            if ($pid = $newPharmacyIdsByName[$name] ?? null) {
                $rows[] = ['original_name' => $name, 'pharmacy_id' => $pid, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach ($manualPharmacyLinks as $name => $pid) {
            $rows[] = ['original_name' => $name, 'pharmacy_id' => $pid, 'created_at' => $now, 'updated_at' => $now];
        }
        if (!empty($rows)) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('pharmacy_mappings')->upsert($chunk, ['original_name'], ['pharmacy_id', 'updated_at']);
            }
        }
    }

    private function splitProductChoices(array $products): array
    {
        $skipped = [];
        $new     = [];
        $manual  = [];

        foreach ($products as $item) {
            $barcode      = $item['barcode'] ?? null;
            $materialName = $item['material_name'] ?? null;
            $company      = $item['company'] ?? null;
            $mappedId     = $item['mapped_id'] ?? null;

            if (!$barcode || !$mappedId) {
                $skipped[] = $item;
                continue;
            }

            if ($mappedId === 'NEW') {
                $new[$barcode] = [
                    'name'    => $materialName,
                    'company' => ($company && $company !== '-') ? $company : 'عام',
                ];
            } else {
                $manual[$barcode] = $mappedId;
            }
        }

        return [$skipped, $new, $manual];
    }

    private function createNewProducts(array $newProductsByBarcode): void
    {
        if (empty($newProductsByBarcode)) {
            return;
        }

        $now  = now();
        $rows = [];
        foreach ($newProductsByBarcode as $barcode => $p) {
            $rows[] = ['barcode' => $barcode, 'name' => $p['name'], 'company' => $p['company'], 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('products')->insertOrIgnore($chunk);
        }

        $newBarcodes         = array_keys($newProductsByBarcode);
        $productIdsByBarcode = Product::whereIn('barcode', $newBarcodes)->pluck('id', 'barcode');

        if ($productIdsByBarcode->isNotEmpty()) {
            $now     = now();
            $invRows = $productIdsByBarcode
                ->map(fn($id) => ['product_id' => $id, 'quantity' => 0, 'created_at' => $now, 'updated_at' => $now])
                ->values()->all();
            foreach (array_chunk($invRows, 500) as $chunk) {
                DB::table('central_inventories')->insertOrIgnore($chunk);
            }
        }
    }
}
