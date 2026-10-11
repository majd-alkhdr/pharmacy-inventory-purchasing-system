<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Collection;

/**
 * 🎯 الترتيب المتوقَّع: اسم الصيدلية[0] - رمز المادة[1] - اسم المادة[2]
 * - الشركة[3] - الكمية[4]. لا يكتب لقاعدة البيانات مباشرة — يجمع الصفوف
 * بالذاكرة فقط، والمطابقة + الإدراج الجماعي تتم لاحقاً بالخدمة.
 *
 * 🆕 (2026-09) عمودان اختياريان إضافيان، إن وُجدا:
 * - العمود[5]: min_threshold — حد أدنى للفرق قبل الترميم لهذا الزوج
 *   تحديداً (يُحدِّث pharmacy_minimum_stocks.min_reorder_gap).
 * - العمود[6]: batch_size — تجاوز حجم عبوة خاص بهذه الصيدلية لهذا الزوج
 *   (يُحدِّث pharmacy_minimum_stocks.pack_size_override).
 *
 * كلاهما **اختياري بالكامل بحسب الطلب الأصلي**: عمود غائد بالملف بالكامل،
 * أو خلية فارغة بصف معيّن، تعني "تجاهل" — لا لمس لأي إعداد محفوظ سابقاً
 * لهذا الزوج. القيمة تُطبَّق فقط على أزواج (صيدلية+مادة) **موجودة أصلاً**
 * بجدول pharmacy_minimum_stocks (راجع PharmacyInventoryUploadService —
 * لا يُنشئ صفاً جديداً هناك بمعزل عن minimum_quantity الحقيقي).
 *
 * 🆕 (2026-09) [إعادة تصميم الاستثناء] نفس منطق SalesImport بالضبط —
 * سطر لشركة مستثناة (قائمة دائمة، ExcludedCompany) يُتجاهَل بالكامل هنا،
 * قبل أي معالجة أخرى — لا يصل لا لتحديث الرصيد ولا لأي تجاوز (حد أدنى/
 * حجم عبوة) بجدول pharmacy_minimum_stocks.
 */
class PharmacyInventoryImport implements ToCollection, WithStartRow, WithChunkReading
{
    public Collection $rows;
    private array $excludedCompanies; // 🆕

    public function __construct()
    {
        $this->rows = collect();
        $this->excludedCompanies = \App\Models\ExcludedCompany::currentList(); // 🆕
    }

    public function startRow(): int
    {
        return 2;
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            $pharmacyName = trim((string) ($row[0] ?? ''));
            $barcode      = trim((string) ($row[1] ?? ''));
            $materialName = trim((string) ($row[2] ?? ''));
            $company      = trim((string) ($row[3] ?? '')) ?: '-';
            $quantity     = (int) ($row[4] ?? 0);

            if (!$pharmacyName || !$barcode) {
                continue;
            }

            // 🆕 شركة مستثناة = تجاهل صريح لهذا السطر
            if (in_array($company, $this->excludedCompanies, true)) {
                continue;
            }

            // 🆕 خلية فارغة/غائبة = null صراحة، لا 0 — الفرق جوهري هنا
            // (راجع تعليق pack_size_override بموديل PharmacyMinimumStock):
            // 0 يعني "قيمة فعلية صفرية"، null يعني "تجاهل، لا تجاوز إطلاقاً".
            $minThreshold = isset($row[5]) && trim((string) $row[5]) !== '' ? (int) $row[5] : null;
            $batchSize    = isset($row[6]) && trim((string) $row[6]) !== '' ? (int) $row[6] : null;

            $this->rows->push([
                'pharmacy_name' => $pharmacyName,
                'barcode'        => $barcode,
                'material_name' => $materialName,
                'company'        => $company,
                'quantity'       => max(0, $quantity),
                'min_threshold'  => $minThreshold, // 🆕
                'batch_size'     => $batchSize,     // 🆕
            ]);
        }
    }
}
