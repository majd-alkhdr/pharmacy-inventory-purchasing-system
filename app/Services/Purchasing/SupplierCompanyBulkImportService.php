<?php
// app/Services/Purchasing/SupplierCompanyBulkImportService.php
namespace App\Services\Purchasing;

use App\Models\Supplier;
use App\Models\SupplierCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 يستبدل بالكامل قائمة الشركات المفضَّلة لكل مورد مذكور بالملف — نفس
 * فلسفة "آخر رفعة = الحقيقة الوحيدة"، هنا على مستوى (مورد → قائمة شركات).
 * موردون غير مذكورين بالملف لا يُلمَسون إطلاقاً.
 *
 * 🩹 إنشاء تلقائي لمورد جديد كلياً غير موجود — نفس نمط
 * HistoricalPurchaseImportService::process() تماماً (نفس المشكلة، نفس الحل).
 */
class SupplierCompanyBulkImportService
{
    /**
     * @param Collection<int, array{supplier_name:string, companies:Collection<int,string>}> $rows
     * @return array{suppliers_updated:int, suppliers_created:int, companies_linked:int}
     */
    public function process(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return ['suppliers_updated' => 0, 'suppliers_created' => 0, 'companies_linked' => 0];
        }

        $now = now();

        $supplierNames = $rows->pluck('supplier_name')->unique()->values();
        $existingNames = Supplier::whereIn('name', $supplierNames)->pluck('name')->all();
        $newNames = $supplierNames->diff($existingNames)->values();

        if ($newNames->isNotEmpty()) {
            $newRows = $newNames->map(fn($name) => [
                'name' => $name,
                'lead_time_days' => 7,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            foreach (array_chunk($newRows, 500) as $chunk) {
                DB::table('suppliers')->insertOrIgnore($chunk);
            }
        }

        $supplierIdsByName = Supplier::whereIn('name', $supplierNames)->pluck('id', 'name');
        $companiesLinked = 0;

        DB::transaction(function () use ($rows, $supplierIdsByName, $now, &$companiesLinked) {
            foreach ($rows as $row) {
                $supplierId = $supplierIdsByName[$row['supplier_name']] ?? null;

                if (!$supplierId) {
                    continue; // 🩹 حماية نظرية فقط — لا يجب أن يحدث بعد الإنشاء أعلاه
                }

                // 🎯 الاستبدال الكامل — حذف القائمة القديمة لهذا المورد
                // تحديداً، ثم إدراج القائمة الجديدة من الملف.
                SupplierCompany::where('supplier_id', $supplierId)->delete();

                $companyRows = $row['companies']->map(fn($company) => [
                    'supplier_id' => $supplierId,
                    'company_name' => $company,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                SupplierCompany::insert($companyRows);
                $companiesLinked += count($companyRows);
            }
        });

        return [
            'suppliers_updated' => $rows->count(),
            'suppliers_created' => $newNames->count(),
            'companies_linked'  => $companiesLinked,
        ];
    }
}
