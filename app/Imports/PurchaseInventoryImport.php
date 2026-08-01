<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Collection;
use Carbon\Carbon;

/**
 * 🎯 يقرأ ملف جرد المشتريات الموحّد بالترتيب المتفَق عليه بالضبط:
 *
 *   [0] رمز المادة   [1] اسم المادة   [2] اسم الشركة   [3] الكمية
 *   [4] التصنيف (⚠️ عمود موجود بالملف لكن مُتجاهَل تماماً هنا — راجع الشرح أدناه)
 *   [5] سعر البيع    [6] تاريخ الصلاحية   [7] تاريخ الشراء
 *   [8] الحد الأدنى (قد تكون القيمة "-" = لا حد أدنى)
 *   [9] هل هي صنف تارجت؟ (نعم/لا أو 1/0)
 *   [10] 🆕 آخر سعر شراء — آخر عمود بالملف (أُضيف بعد الاتفاق الصريح على
 *        تفعيل مراقب تذبذب التكلفة اعتماداً على هذا الملف تحديداً).
 *
 * ⚠️ عمود "التصنيف" موجود بالملف لأسباب تشغيلية للمستخدم، لكن بقرار صريح
 * منه أثناء التصميم: "التصنيف يبقى كما كان تماماً، ولا حتى نرفعه بالجرد" —
 * لذلك هذا الـ Import **لا يقرأه إطلاقاً ولا يستخدمه بأي شكل**. التصنيف
 * يبقى حصراً عبر شاشة "التصنيفات (الاستطباب)" المستقلة الموجودة أصلاً.
 *
 * 🩹 لا يكتب لقاعدة البيانات مباشرة (بنفس نمط SellingPricesImport
 * وHistoricalSupplierPricesImport) — يجمع كل الصفوف بالذاكرة فقط،
 * والمعالجة الفعلية (تجميع التكرار + المطابقة + الإدراج الجماعي) تتم
 * لاحقاً دفعة واحدة بخدمة PurchaseInventoryUploadService.
 */
class PurchaseInventoryImport implements ToCollection, WithStartRow, WithChunkReading
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

    public function chunkSize(): int
    {
        return 1000;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            $barcode = trim((string) ($row[0] ?? ''));
            $name    = trim((string) ($row[1] ?? ''));
            $company = trim((string) ($row[2] ?? '')) ?: '-';
            $qty     = (int) ($row[3] ?? 0);
            // $row[4] = التصنيف — متجاهَل عمداً، راجع الشرح أعلاه.
            $sellingPrice   = $this->parseNullableFloat($row[5] ?? null);
            $expiryDate     = $this->parseNullableDate($row[6] ?? null);
            $purchaseDate   = $this->parseNullableDate($row[7] ?? null);
            $minStock       = $this->parseMinStock($row[8] ?? null);
            $isTarget       = $this->parseBool($row[9] ?? null);
            $lastPurchasePrice = $this->parseNullableFloat($row[10] ?? null); // 🆕 آخر عمود

            if (!$barcode || !$name || $qty <= 0) {
                continue;
            }

            $this->rows->push([
                'barcode'                => $barcode,
                'name'                    => $name,
                'company'                 => $company,
                'quantity'                => $qty,
                'selling_price'          => $sellingPrice,
                'expiry_date'            => $expiryDate,
                'purchase_date'          => $purchaseDate,
                'min_stock'              => $minStock,
                'is_target'              => $isTarget,
                'last_purchase_price'   => $lastPurchasePrice,
            ]);
        }
    }

    private function parseNullableFloat($value): ?float
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || $value === '-') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function parseMinStock($value): ?int
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || $value === '-') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function parseBool($value): bool
    {
        $value = mb_strtolower(trim((string) ($value ?? '')));

        return in_array($value, ['1', 'نعم', 'yes', 'true', 'تارجت'], true);
    }

    private function parseNullableDate($value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return Carbon::instance(
                    \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)
                )->toDateString();
            }

            return Carbon::parse($value)->toDateString();
        } catch (\Exception $e) {
            return null;
        }
    }
}
