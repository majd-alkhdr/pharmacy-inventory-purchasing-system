<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\CentralInventory;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Collection;

class CentralInventoryImport implements ToCollection, WithStartRow, WithChunkReading
{
    public array $matched   = []; // [product_id => quantity]
    public array $unmatched = []; // مواد رمزها غير موجود
    private ?Collection $productsCache = null;

    private function products(): Collection
    {
        return $this->productsCache ??= Product::select('id', 'barcode', 'name', 'company')
            ->get()->keyBy('barcode');
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function startRow(): int
    {
        return 2;
    }

    public function collection(Collection $rows)
    {
        $allProducts = $this->products(); // نفس الكاش لكل الـ chunks

        $toUpdate = []; // [barcode => ['name'=>, 'company'=>]] لتحديث الأسماء المتغيرة

        foreach ($rows as $row) {
            // ✅ رقم المادة[0] - شركة[1] - مادة[2] - كمية[3]
            $barcode      = trim($row[0] ?? '');
            $company      = trim($row[1] ?? '') ?: '-';
            $materialName = trim($row[2] ?? '');
            $quantity     = (int) ($row[3] ?? 0);

            if (!$barcode || !$materialName) continue;

            $product = $allProducts->get($barcode);

            if ($product) {
                // ✅ الرمز موجود — هل تغيّر الاسم أو الشركة؟
                if ($product->name !== $materialName || $product->company !== $company) {
                    $toUpdate[$barcode] = [
                        'name'    => $materialName,
                        'company' => $company,
                    ];
                }
                $this->matched[$product->id] = ($this->matched[$product->id] ?? 0) + $quantity;
            } else {
                // ❌ رمز جديد غير موجود
                $this->unmatched[] = [
                    'barcode'       => $barcode,
                    'material_name' => $materialName,
                    'company'       => $company,
                    'quantity'      => $quantity,
                ];
            }
        }

        // ✅ تحديث الأسماء المتغيرة دفعة واحدة
        foreach ($toUpdate as $barcode => $data) {
            Product::where('barcode', $barcode)->update($data);
        }
    }
}
