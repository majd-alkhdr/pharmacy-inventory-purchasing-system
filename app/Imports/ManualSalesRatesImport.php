<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;

/**
 * 🎯 ملف مبيعات يدوي لتغذية معدّل الاستهلاك عند غياب تاريخ فواتير كافٍ —
 * راجع ManualSalesRateUploadService. الترتيب: اسم المادة[0] - اسم
 * الشركة[1] - الكمية المباعة[2]. المدة (بالأشهر) لا تُقرأ من الملف
 * إطلاقاً — حقل واحد بالواجهة يُطبَّق على الرفعة كاملة (راجع
 * UploadManualSalesRatesRequest).
 */
class ManualSalesRatesImport implements ToCollection, WithStartRow
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
            $name     = trim((string) ($row[0] ?? ''));
            $company  = trim((string) ($row[1] ?? ''));
            $quantity = trim((string) ($row[2] ?? ''));

            if (!$name || !$company || !is_numeric($quantity) || (int) $quantity <= 0) {
                continue;
            }

            $this->rows->push([
                'material_name' => $name,
                'company'       => $company,
                'quantity'      => (int) $quantity,
            ]);
        }
    }
}
