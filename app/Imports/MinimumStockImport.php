<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Illuminate\Support\Collection;

/**
 * 🎯 ملف مستقل خاص بالحد الأدنى فقط — بعد فصله عن ملف جرد المشتريات
 * الموحّد بقرار صريح من صاحب المشروع. الترتيب: رمز المادة[0] - اسم
 * المادة[1] (معلوماتي فقط، لا يُستخدم بالمطابقة) - الحد الأدنى[2].
 *
 * القيمة "-" بعمود الحد الأدنى تعني "لا يوجد حد أدنى لهذه المادة" —
 * تُصفِّر أي حد أدنى محفوظ سابقاً (وليس تجاهلها).
 */
class MinimumStockImport implements ToCollection, WithStartRow
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
            $barcode = trim((string) ($row[0] ?? ''));
            $name    = trim((string) ($row[1] ?? ''));
            $rawMin  = trim((string) ($row[2] ?? ''));

            if (!$barcode) {
                continue;
            }

            $minStock = ($rawMin === '' || $rawMin === '-') ? null : (is_numeric($rawMin) ? (int) $rawMin : null);

            $this->rows->push([
                'barcode'    => $barcode,
                'name'        => $name,
                'min_stock'  => $minStock,
            ]);
        }
    }
}
