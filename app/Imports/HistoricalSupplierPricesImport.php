<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Illuminate\Support\Collection;

/**
 * 🎯 الترتيب المتوقَّع للأعمدة: اسم المورد[0] - باركود[1] - اسم المادة[2]
 * - الشركة[3] - سعر الوحدة[4].
 *
 * 🩹 لاحظ أن هذا الـ Import **لا يكتب لقاعدة البيانات مباشرة** (بعكس
 * SalesImport/ShortagesImport اللذين يُدرجان لجدول مؤقت صفاً بصف). هنا
 * نجمع كل الصفوف بذاكرة واحدة فقط (public Collection $rows)، والمعالجة
 * الفعلية (المطابقة + الإدراج الجماعي) تتم لاحقاً دفعة واحدة بخدمة
 * HistoricalPurchaseImportService — تطبيقاً مباشراً للدرس المستفاد من
 * إصلاح مشكلة "Maximum execution time exceeded": لا نُنفّذ أي استعلام
 * قاعدة بيانات داخل حلقة قراءة الملف نفسها.
 */
class HistoricalSupplierPricesImport implements ToCollection, WithStartRow
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
            $supplierName = trim((string) ($row[0] ?? ''));
            $barcode      = trim((string) ($row[1] ?? ''));
            $materialName = trim((string) ($row[2] ?? ''));
            $company      = trim((string) ($row[3] ?? '')) ?: '-';
            $unitCost     = (float) ($row[4] ?? 0);

            if (!$supplierName || !$barcode || $unitCost <= 0) {
                continue;
            }

            $this->rows->push([
                'supplier_name' => $supplierName,
                'barcode'        => $barcode,
                'material_name' => $materialName,
                'company'        => $company,
                'unit_cost'      => $unitCost,
            ]);
        }
    }
}
