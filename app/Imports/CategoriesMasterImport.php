<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Illuminate\Support\Collection;

class CategoriesMasterImport implements ToCollection, WithStartRow
{
    public Collection $names;

    public function __construct()
    {
        $this->names = collect();
    }

    public function startRow(): int
    {
        return 2;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            $name = trim((string) ($row[0] ?? ''));
            if ($name) {
                $this->names->push($name);
            }
        }
    }
}
