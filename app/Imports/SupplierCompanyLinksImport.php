<?php
// app/Imports/SupplierCompanyLinksImport.php
namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;

/**
 * 🎯 ملف ربط مورد↔شركات مفضَّلة بالجملة — عرض صف متغيّر: العمود[0] اسم
 * المورد، الأعمدة التالية أسماء شركات متتالية حتى أول خلية فارغة (قد
 * تكون شركتين أو عشراً، لا عدد ثابت). راجع SupplierCompanyBulkImportService.
 */
class SupplierCompanyLinksImport implements ToCollection, WithStartRow
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

            if (!$supplierName) {
                continue;
            }

            $companies = collect();
            for ($i = 1; $i < $row->count(); $i++) {
                $company = trim((string) ($row[$i] ?? ''));

                if ($company === '') {
                    break; // 🎯 أول خلية فارغة = نهاية قائمة الشركات لهذا الصف
                }

                $companies->push($company);
            }

            if ($companies->isEmpty()) {
                continue; // 🩹 مورد بلا أي شركة مذكورة — تجاهل الصف بالكامل
            }

            $this->rows->push([
                'supplier_name' => $supplierName,
                'companies'      => $companies->unique()->values(),
            ]);
        }
    }
}
