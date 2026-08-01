<?php

namespace App\Services\Inventory;

use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\Shortage;
use App\Models\TemporaryShortage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\Inventory\ShortageRecorderService;
/**
 * 🎯 المسؤولية الوحيدة: تحويل صفوف temporary_shortages المُطابَقة (يدوياً
 * أو تلقائياً) إلى سجلات shortages نهائية، مع دمج الكميات مع أي نقص معلّق
 * موجود مسبقاً لنفس الصيدلية والمادة بدل تكرار السجل.
 */
class ShortageMappingService
{
    public function __construct(
        private readonly ShortageRecorderService $shortageRecorder,
    ) {}
    public function applyPharmacyMappingMemory(string $sessionId): void
    {
        DB::statement("
            UPDATE temporary_shortages ts JOIN pharmacy_mappings pm ON ts.pharmacy_name = pm.original_name
            SET ts.pharmacy_id = pm.pharmacy_id WHERE ts.session_id = ? AND ts.pharmacy_id IS NULL
        ", [$sessionId]);
    }

    public function applyProductMappingMemory(string $sessionId): void
    {
        // 🩹 ملاحظة موروثة من الأصل: LOWER() هنا لا يستفيد من فهرس (MariaDB لا
        // تدعم Functional Index بهذه الصيغة). الأثر محدود لأنه استعلام واحد
        // لكل استيراد كامل، وليس لكل صف — راجع تقرير المايجريشن للتفاصيل.
        DB::statement("
            UPDATE temporary_shortages ts JOIN product_mappings pmap
                ON LOWER(ts.material_name) = LOWER(pmap.original_name) AND LOWER(ts.company) = LOWER(pmap.company)
            SET ts.product_id = pmap.product_id WHERE ts.session_id = ? AND ts.product_id IS NULL
        ", [$sessionId]);
    }

    public function saveShortagesMapping(string $sessionId, array $pharmacies, array $products): array
    {
        DB::beginTransaction();
        try {
            [$skippedPharmacies, $newPharmacyNames, $manualPharmacyLinks] = $this->splitPharmacyChoices($pharmacies);
            $this->createNewPharmacies($newPharmacyNames);
            $this->persistPharmacyMappings($newPharmacyNames, $manualPharmacyLinks);

            DB::statement("
                UPDATE temporary_shortages ts JOIN pharmacy_mappings pm ON ts.pharmacy_name = pm.original_name
                SET ts.pharmacy_id = pm.pharmacy_id WHERE ts.session_id = ?
            ", [$sessionId]);

            [$skippedProducts, $newProducts, $manualProductLinks] = $this->splitProductChoices($products);
            $this->createNewProductsWithMappings($newProducts, $manualProductLinks);

            DB::statement("
                UPDATE temporary_shortages ts JOIN product_mappings pmap
                    ON LOWER(ts.material_name) = LOWER(pmap.original_name) AND LOWER(ts.company) = LOWER(pmap.company)
                SET ts.product_id = pmap.product_id WHERE ts.session_id = ?
            ", [$sessionId]);

            DB::commit();

            if (count($skippedPharmacies) > 0 || count($skippedProducts) > 0) {
                $names = collect($skippedProducts)->pluck('material_name')
                    ->merge(collect($skippedPharmacies)->pluck('excel_name'))->filter()->implode('، ');
                return ['success' => true, 'warning' => "⚠️ تم حفظ معظم البيانات، لكن تعذّرت معالجة: {$names}"];
            }

            return ['success' => true, 'message' => $this->finalizeShortagesImport($sessionId)];
        } catch (\Exception $e) {
            DB::rollBack();
            TemporaryShortage::forSession($sessionId)->delete();
            return ['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()];
        }
    }

    public function finalizeShortagesImport(string $sessionId): string
{
    $aggregated = TemporaryShortage::forSession($sessionId)
        ->whereNotNull('pharmacy_id')->whereNotNull('product_id')
        ->selectRaw('pharmacy_id, product_id, SUM(quantity) as total_quantity, MAX(shortage_date) as latest_date')
        ->groupBy('pharmacy_id', 'product_id')
        ->get();

    $importedCount = $aggregated->count();

    if ($importedCount > 0) {
        // 🎯 نفس منطق الدمج المستخدم بالرفع اليومي بالضبط — مصدر حقيقة واحد
        // بدل نسخة موازية هنا (راجع ShortageRecorderService لتفاصيل الدمج).
        $this->shortageRecorder->recordMany(
            $aggregated->map(fn ($row) => [
                'pharmacy_id' => $row->pharmacy_id,
                'product_id'  => $row->product_id,
                'quantity'    => $row->total_quantity,
                'occurred_at' => $row->latest_date,
            ])
        );
    }

    TemporaryShortage::forSession($sessionId)->delete();

    return "✅ تم معالجة {$importedCount} تركيبة (صيدلية-مادة)، وتم دمج المتكرر منها مع النواقص المعلقة القائمة.";
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
            $materialName = $item['material_name'] ?? null;
            $company      = $item['company'] ?? null;
            $mappedId     = $item['mapped_id'] ?? null;
            $barcode      = $item['barcode'] ?? null;

            if (!$materialName || !$mappedId) {
                $skipped[] = $item;
                continue;
            }

            if ($mappedId === 'NEW') {
                $companyValue = ($company && $company !== '-') ? $company : 'عام';
                $finalBarcode = $barcode ?: ('NB-' . strtoupper(Str::random(14)));
                $new[$finalBarcode] = ['material_name' => $materialName, 'company' => $companyValue];
            } else {
                $manual[$materialName . '||' . ($company ?? '')] = [
                    'material_name' => $materialName,
                    'company'       => $company,
                    'product_id'    => $mappedId,
                ];
            }
        }

        return [$skipped, $new, $manual];
    }

    private function createNewProductsWithMappings(array $newProducts, array $manualProductLinks): void
    {
        if (!empty($newProducts)) {
            $now  = now();
            $rows = [];
            foreach ($newProducts as $barcode => $p) {
                $rows[] = ['barcode' => $barcode, 'name' => $p['material_name'], 'company' => $p['company'], 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('products')->insertOrIgnore($chunk);
            }
        }

        $newBarcodes         = array_keys($newProducts);
        $productIdsByBarcode = !empty($newBarcodes)
            ? Product::whereIn('barcode', $newBarcodes)->pluck('id', 'barcode')
            : collect();

        if ($productIdsByBarcode->isNotEmpty()) {
            $now     = now();
            $invRows = $productIdsByBarcode
                ->map(fn($id) => ['product_id' => $id, 'quantity' => 0, 'created_at' => $now, 'updated_at' => $now])
                ->values()->all();
            foreach (array_chunk($invRows, 500) as $chunk) {
                DB::table('central_inventories')->insertOrIgnore($chunk);
            }
        }

        $now = now();
        $productMappingRows = [];
        foreach ($newProducts as $barcode => $p) {
            if ($pid = $productIdsByBarcode[$barcode] ?? null) {
                $productMappingRows[] = ['original_name' => $p['material_name'], 'company' => $p['company'], 'product_id' => $pid, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach ($manualProductLinks as $link) {
            $productMappingRows[] = ['original_name' => $link['material_name'], 'company' => $link['company'], 'product_id' => $link['product_id'], 'created_at' => $now, 'updated_at' => $now];
        }

        if (!empty($productMappingRows)) {
            foreach (array_chunk($productMappingRows, 500) as $chunk) {
                DB::table('product_mappings')->upsert($chunk, ['original_name', 'company'], ['product_id', 'updated_at']);
            }
        }
    }
}
