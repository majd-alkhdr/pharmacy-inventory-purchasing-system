<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Illuminate\Support\Collection;

/**
 * 🎯 الترتيب المتوقَّع: اسم الصيدلية[0] - رقم المادة[1] - اسم المادة[2]
 * - الحد الأدنى[3] - 🆕 الحد الأدنى للفرق قبل الترميم (min_reorder_gap)[4]
 * (اختياري).
 *
 * القيمة "-" بعمود الحد الأدنى[3] تعني "لا يوجد حد أدنى لهذا الزوج" —
 * تُزيل أي قيد سابق (وليس تصفيره).
 *
 * 🆕 عمود min_reorder_gap[4] اختياري تماماً: خانة فارغة أو عمود غير موجود
 * بالملف → null (الخدمة تحتفظ بالقيمة المحفوظة سابقاً، ولا تُصفّرها).
 */
class PharmacyMinimumStockImport implements ToCollection, WithStartRow
{
    public Collection $rows;

    public function __construct()
    {
        $this->rows = collect();
    }

    public function startRow(): int
    {
        return 2;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            $pharmacyName = trim((string) ($row[0] ?? ''));
            $barcode      = trim((string) ($row[1] ?? ''));
            $materialName = trim((string) ($row[2] ?? ''));
            $rawMin       = trim((string) ($row[3] ?? ''));
            $rawGapMin    = trim((string) ($row[4] ?? '')); // 🆕

            if (!$pharmacyName || !$barcode) {
                continue;
            }

            $minimum = ($rawMin === '' || $rawMin === '-') ? null : (is_numeric($rawMin) ? (int) $rawMin : null);

            // 🆕 خانة فارغة أو "-" أو عمود غير موجود أصلاً بالملف → null
            // (لا يُلمَس أي شيء بهذا الحقل). رقم صريح (بما فيه 0) → يُعتمَد.
            $minReorderGap = ($rawGapMin === '' || $rawGapMin === '-') ? null : (is_numeric($rawGapMin) ? (int) $rawGapMin : null);

            $this->rows->push([
                'pharmacy_name'    => $pharmacyName,
                'barcode'           => $barcode,
                'material_name'    => $materialName,
                'minimum'           => $minimum,
                'min_reorder_gap'  => $minReorderGap, // 🆕
            ]);
        }
    }
}
