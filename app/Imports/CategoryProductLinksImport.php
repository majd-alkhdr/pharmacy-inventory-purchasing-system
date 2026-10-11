<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Illuminate\Support\Collection;

/** الترتيب المتوقَّع: باركود[0] - اسم التصنيف[1]. */
class CategoryProductLinksImport implements ToCollection, WithStartRow, WithCustomCsvSettings
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

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ',',
            'enclosure' => '"',
            'input_encoding' => 'UTF-8',
        ];
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            $barcode = trim((string) ($row[0] ?? ''));
            $category = trim((string) ($row[1] ?? ''));

            if (!$barcode || !$category) {
                continue;
            }

            $this->rows->push(['barcode' => $barcode, 'category_name' => $category]);
        }
    }
}