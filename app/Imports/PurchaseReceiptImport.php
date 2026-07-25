<?php

namespace App\Imports;

use App\Models\TemporaryPurchaseReceipt;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Illuminate\Support\Collection;
use Carbon\Carbon;

/**
 * 🎯 بنفس نمط ShortagesImport بالضبط (اتساق معماري): قراءة صفوف Excel
 * خام وتحويلها لسجلات مؤقتة بجدول temporary_purchase_receipts، تمهيداً
 * للمطابقة التلقائية/اليدوية اللاحقة.
 *
 * الترتيب المتوقَّع للأعمدة: باركود[0] - مادة[1] - شركة[2] - الكمية
 * المستلمة[3] - تاريخ الصلاحية[4] (اختياري).
 */
class PurchaseReceiptImport implements ToCollection, WithStartRow
{
    public int $rowsRead = 0;

    public function __construct(
        private readonly string $sessionId,
        private readonly ?int $purchaseOrderId,
    ) {
    }

    public function startRow(): int
    {
        return 2;
    }

    public function collection(Collection $rows)
    {
        $records = [];

        foreach ($rows as $row) {
            $barcode      = trim((string) ($row[0] ?? ''));
            $materialName = trim((string) ($row[1] ?? ''));
            $company      = trim((string) ($row[2] ?? '')) ?: '-';
            $receivedQty  = (int) ($row[3] ?? 0);
            $rawExpiry    = $row[4] ?? null;

            if (!$materialName || $receivedQty <= 0) {
                continue;
            }

            $expiryDate = null;
            if ($rawExpiry) {
                try {
                    $expiryDate = is_numeric($rawExpiry)
                        ? Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($rawExpiry))->toDateString()
                        : Carbon::parse($rawExpiry)->toDateString();
                } catch (\Exception $e) {
                    $expiryDate = null; // 🩹 تاريخ غير صالح؟ نتجاهله بدل رفض الصف كاملاً — الاستلام يبقى مهماً حتى بلا تاريخ صلاحية
                }
            }

            $records[] = [
                'session_id'         => $this->sessionId,
                'purchase_order_id' => $this->purchaseOrderId,
                'barcode'             => $barcode,
                'material_name'      => $materialName,
                'company'             => $company,
                'received_qty'       => $receivedQty,
                'expiry_date'         => $expiryDate,
                'product_id'          => null,
                'matched_purchase_order_item_id' => null,
                'created_at'          => now(),
                'updated_at'          => now(),
            ];

            $this->rowsRead++;
        }

        if (!empty($records)) {
            TemporaryPurchaseReceipt::insert($records);
        }
    }
}
