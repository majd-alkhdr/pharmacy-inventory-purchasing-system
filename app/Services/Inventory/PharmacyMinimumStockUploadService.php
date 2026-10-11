<?php

namespace App\Services\Inventory;

use App\Enums\ImportFileType;
use App\Models\Pharmacy;
use App\Services\Inventory\Import\Support\ImportDiagnostics;
use App\Services\Inventory\Import\Validation\CrossFileCodeValidator;
use App\Models\PharmacyMinimumStock;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 يُطبَّق ملف الحد الأدنى لكل زوج (صيدلية + مادة) — "آخر ملف مرفوع هو
 * مصدر الحقيقة"، محفوظ حتى تعديله برفع ملف جديد لاحقاً. القيمة "-"
 * تحذف أي حد أدنى مسجَّل سابقاً لهذا الزوج تحديداً (لا تُصفِّره).
 *
 * 🆕 (2026-08) العمود الخامس الاختياري min_reorder_gap: بعكس minimum
 * (حيث "-" له معنى صريح = حذف)، هذا الحقل أبسط — إن غاب أو كانت خانته
 * فارغة بهذا الصف، لا نلمسه إطلاقاً (لا نُصفّره ولا نحذفه)، فقط نُحدِّثه
 * حين تصل قيمة رقمية صريحة له.
 */
class PharmacyMinimumStockUploadService
{
    public function __construct(
        private readonly CrossFileCodeValidator $crossFileValidator = new CrossFileCodeValidator(), // 🆕 (أ.4)
    ) {}

    /**
     * 🆕 (أ.4) 'diagnostics': هذا الملف كان **يُنشئ صيدلية جديدة بصمت** لأي اسم غير معروف
     * (ensurePharmaciesExist) — خطأ إملائي واحد باسم صيدلية = صيدلية وهمية جديدة بمخزون
     * قياسي، والصيدلية الحقيقية بلا تحديث، بلا أي رسالة. السلوك نفسه مُجمَّد كما هو (قد
     * يكون مقصوداً لصيدلية افتُتحت للتو)، لكنه أصبح **تحذيراً مرئياً** بنتيجة الرفع.
     * وبالمثل الرموز غير المطابَقة أصبحت تحذير UnknownProductCode بأرقام صفوفها.
     *
     * @param Collection<int, array> $rows خام من PharmacyMinimumStockImport
     * @return array{matched:int, unmatched:Collection, diagnostics: ImportDiagnostics}
     */
    public function process(Collection $rows): array
    {
        $diagnostics = new ImportDiagnostics();

        if ($rows->isEmpty()) {
            return ['matched' => 0, 'unmatched' => collect(), 'diagnostics' => $diagnostics];
        }

        // رقم الصف التقريبي بالملف: صف الرؤوس (1) + الفهرس (من 0) + 1.
        $firstRowByPharmacy = [];
        $firstRowByBarcode = [];
        foreach ($rows->values() as $index => $row) {
            $firstRowByPharmacy[(string) $row['pharmacy_name']] ??= $index + 2;
            $firstRowByBarcode[(string) $row['barcode']] ??= $index + 2;
        }
        $this->crossFileValidator->recordUnknownPharmacies($firstRowByPharmacy, $diagnostics);
        $this->crossFileValidator->recordUnknownProducts($firstRowByBarcode, $diagnostics, ImportFileType::PharmacyMinimumStock);

        $pharmacyNames = $rows->pluck('pharmacy_name')->unique()->values();
        $this->ensurePharmaciesExist($pharmacyNames);
        $pharmacyIdsByName = Pharmacy::whereIn('name', $pharmacyNames)->pluck('id', 'name');

        $barcodes = $rows->pluck('barcode')->unique()->values();
        $productIdsByBarcode = Product::whereIn('barcode', $barcodes)->pluck('id', 'barcode');

        $matched = 0;
        $unmatched = collect();

        foreach ($rows as $row) {
            $productId = $productIdsByBarcode[$row['barcode']] ?? null;

            if (!$productId) {
                $unmatched->push("{$row['barcode']} — {$row['material_name']} ({$row['pharmacy_name']})");
                continue;
            }

            $pharmacyId = $pharmacyIdsByName[$row['pharmacy_name']];

            if ($row['minimum'] === null) {
                // 🎯 "-" = حذف أي حد أدنى مسجَّل سابقاً لهذا الزوج تحديداً
                PharmacyMinimumStock::where('pharmacy_id', $pharmacyId)
                    ->where('product_id', $productId)
                    ->delete();
            } else {
                $attributes = ['minimum_quantity' => $row['minimum']];

                // 🆕 لا نُصفّر min_reorder_gap إن لم يرد العمود بهذا الصف —
                // نُحدِّثه فقط إذا وردت قيمة رقمية صريحة.
                if ($row['min_reorder_gap'] !== null) {
                    $attributes['min_reorder_gap'] = $row['min_reorder_gap'];
                }

                PharmacyMinimumStock::updateOrCreate(
                    ['pharmacy_id' => $pharmacyId, 'product_id' => $productId],
                    $attributes
                );
            }
            $matched++;
        }

        return ['matched' => $matched, 'unmatched' => $unmatched->unique()->values(), 'diagnostics' => $diagnostics];
    }

    private function ensurePharmaciesExist(Collection $names): void
    {
        $existing = Pharmacy::whereIn('name', $names)->pluck('name');
        $missing = $names->diff($existing);

        if ($missing->isEmpty()) {
            return;
        }

        $now = now();
        $rows = $missing->map(fn($name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now])->all();
        DB::table('pharmacies')->insertOrIgnore($rows);
    }
}
