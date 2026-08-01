<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Illuminate\Support\Collection;

/**
 * الترتيب المتوقَّع للأعمدة: باركود[0] - سعر البيع[1].
 * لا يكتب لقاعدة البيانات مباشرة — يجمع كل الصفوف بالذاكرة فقط، والمعالجة
 * الفعلية (المطابقة + الإدراج الجماعي) تتم لاحقاً دفعة واحدة بالخدمة.
 */
class SellingPricesImport implements ToCollection, WithStartRow
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
            $price   = (float) ($row[1] ?? 0);

            if (!$barcode || $price <= 0) {
                continue;
            }

            $this->rows->push(['barcode' => $barcode, 'price' => $price]);
        }
    }
}
