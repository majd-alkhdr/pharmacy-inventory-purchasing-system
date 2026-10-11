<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Illuminate\Support\Collection;

/**
 * 🎯 قراءة "استطلاعية" سريعة لملف الجرد اليومي — الهدف الوحيد: استخراج
 * قائمة الشركات المميَّزة الموجودة بعمود [1] (نفس ترتيب CentralInventoryImport:
 * رقم المادة[0] - الشركة[1] - مادة[2] - كمية[3])، لعرضها بشاشة "استثناء
 * من الترميم" **قبل** بدء المعالجة الكاملة الفعلية بالخلفية.
 *
 * ⚠️ لا تُطابِق بالباركود، لا تكتب لقاعدة البيانات، ولا تُنشئ أي سجل —
 * فقط قراءة عمود واحد وتجميع القيم المميَّزة. هذا يبقيها سريعة جداً حتى
 * على ملفات كبيرة، لأنها لا تُنفِّذ أي استعلام قاعدة بيانات إطلاقاً.
 */
class InventoryCompanyPeekImport implements ToCollection, WithStartRow
{
    public Collection $companies;

    public function __construct()
    {
        $this->companies = collect();
    }

    public function startRow(): int
    {
        return 2;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            $company = trim((string) ($row[1] ?? ''));
            if ($company && $company !== '-') {
                $this->companies->push($company);
            }
        }

        $this->companies = $this->companies->unique()->sort()->values();
    }
}
