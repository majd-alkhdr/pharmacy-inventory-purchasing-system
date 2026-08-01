<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * 🎯 يمثّل "الشركات المصنّعة المفضّلة" لمورد معيّن — أساس اختيار المورد
 * الجديد (شركة ↔ مورد بدل مادة ↔ مورد)، بعد قرار المستخدم الصريح بتبسيط
 * الاختيار لأن عدد المواد كبير جداً ليُربط كل واحدة يدوياً.
 *
 * راجع وثيقة التصميم "تصميم-اعادة-هيكلة-المشتريات.md" §3.1 و§4.6 للسياق
 * الكامل، ومايجريشن 2026_08_01_000001 لتفاصيل قرار الجدول العلائقي.
 */
class SupplierCompany extends Model
{
    protected $fillable = ['supplier_id', 'company_name'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * 🎯 الاستعلام الأهم بهذا الموديل بالكامل: "من هم الموردون الذين
     * يفضّلون توزيع شركة معيّنة؟" — يُستدعى من SupplierSelectionService
     * لكل منتج مؤهَّل أثناء توليد فواتير الشراء.
     *
     * @return Collection<int, Supplier>
     */
    public static function suppliersForCompany(string $company): Collection
    {
        return static::where('company_name', $company)
            ->with('supplier')
            ->get()
            ->pluck('supplier')
            ->filter() // 🩹 احتياط: لو مورد حُذف بطريقة ما دون Cascade صحيح
            ->unique('id')
            ->values();
    }
}
