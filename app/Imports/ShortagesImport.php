<?php

namespace App\Imports;

use App\Models\TemporaryShortage;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class ShortagesImport implements ToCollection, WithChunkReading, WithStartRow
{
    protected string $sessionId;
    public int $rowsRead = 0;

    public function __construct(string $sessionId)
    {
        $this->sessionId = $sessionId;
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
        $records = [];

        foreach ($rows as $row) {
            // 🩹 تصحيح: الترتيب الفعلي المعروض بقالب الواجهة هو
            // صيدلية[0] - باركود[1] - مادة[2] - شركة[3] - كمية[4] - تاريخ[5]
            // الترتيب القديم (صيدلية-شركة-مادة-كمية-تاريخ) لم يكن يتضمن عمود
            // الباركود إطلاقاً، فكان يقرأ عمود "الشركة" النصي على أنه "الكمية"
            // الرقمية، فتتحول كل كمية إلى 0 ويُستبعد كل صف من الملف تلقائياً.
            $pharmacyName = trim($row[0] ?? '');
            $barcode      = trim($row[1] ?? '');
            $materialName = trim($row[2] ?? '');
            $company      = trim($row[3] ?? '') ?: '-';
            $quantity     = (int) ($row[4] ?? 0);
            $rawDate      = $row[5] ?? null;

            if (!$pharmacyName || !$materialName || $quantity <= 0) {
                continue;
            }

            // معالجة التاريخ
            try {
                if (is_numeric($rawDate)) {
                    $date = Carbon::instance(
                        \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($rawDate)
                    )->toDateString();
                } elseif ($rawDate) {
                    $date = Carbon::parse($rawDate)->toDateString();
                } else {
                    $date = now()->toDateString();
                }
            } catch (\Exception $e) {
                $date = now()->toDateString();
            }

            $records[] = [
                'session_id'    => $this->sessionId,
                'pharmacy_name' => $pharmacyName,
                'barcode'       => $barcode, // 🩹 كان مفقوداً بالكامل رغم وجود العمود بقاعدة البيانات
                'company'       => $company,
                'material_name' => $materialName,
                'quantity'      => $quantity,
                'shortage_date' => $date,
                'pharmacy_id'   => null,
                'product_id'    => null,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];

            $this->rowsRead++;
        }

        foreach (array_chunk($records, 500) as $chunk) {
            TemporaryShortage::insert($chunk);
        }
    }
}
