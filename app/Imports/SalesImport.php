<?php

namespace App\Imports;

use App\Models\TemporarySale;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Illuminate\Support\Collection;

class SalesImport implements ToCollection, WithStartRow, WithChunkReading, WithBatchInserts
{
    public function chunkSize(): int
    {
        return 1000;
    }
    public function batchSize(): int
    {
        return 1000;
    }
    // باقي الكود كما هو — collection() ستُستدعى لكل chunk تلقائياً
    protected $sessionId;

    public function __construct($sessionId)
    {
        $this->sessionId = $sessionId;
    }

    public function startRow(): int
    {
        return 2;
    }

    public function collection(Collection $rows)
    {
        $records = [];

        foreach ($rows as $row) {
            // ✅ رقم المادة[0] - شركة[1] - مادة[2] - صيدلية[3] - كمية[4]
            $barcode      = trim($row[0] ?? '');
            $company      = trim($row[1] ?? '') ?: '-';
            $materialName = trim($row[2] ?? '');
            $pharmacyName = trim($row[3] ?? '');
            // 1️⃣ قراءة القيمة كعدد عشري أولاً للحفاظ على الكسور (مثل 0.5)
            $rawQuantity  = floatval($row[4] ?? 0);
            // 2️⃣ جبر الكسر إلى الأعلى دائماً (0.5 تصبح 1، و 1.2 تصبح 2) ثم تحويلها لعدد صحيح
            $quantity     = (int) ceil($rawQuantity);

            if (!$pharmacyName || !$materialName || !$barcode || $quantity <= 0) continue;

            $records[] = [
                'session_id'    => $this->sessionId,
                'pharmacy_name' => $pharmacyName,
                'company'       => $company,
                'material_name' => $materialName,
                'barcode'       => $barcode,
                'quantity'      => $quantity,
                'pharmacy_id'   => null,
                'product_id'    => null,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        }

        foreach (array_chunk($records, 500) as $chunk) {
            TemporarySale::insert($chunk);
        }
    }
}
