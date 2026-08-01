<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = ['name', 'contact_person', 'phone', 'email', 'lead_time_days', 'notes', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function products()
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * 🆕 الشركات المصنّعة المفضّلة لهذا المورد — أساس اختيار المورد الجديد
     * (مورد ↔ شركة، بدل مورد ↔ مادة). هذا هو المصدر *الفعلي* المستخدَم
     * الآن من SupplierSelectionService عند توليد فواتير الشراء.
     *
     * لا علاقة له بـ distributedCompanies() أدناه (المحسوبة حياً من
     * supplier_products) — ذلك يبقى معلوماتياً بحتاً بشاشة تفاصيل المورد
     * القديمة، بينما هذا هو مصدر القرار الفعلي الجديد.
     */
    public function preferredCompanies()
    {
        return $this->hasMany(SupplierCompany::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where('name', 'LIKE', "%{$term}%");
    }

    /**
     * 🎯 قائمة الشركات المصنّعة التي يوزّعها هذا المورد فعلياً الآن —
     * محسوبة حيّاً من رابطة supplier_products→products.company (الأصل
     * الحقيقي الوحيد)، وليست عموداً مخزَّناً يحتاج مزامنة. أي تغيير
     * لحظي (ربط/فك ربط مادة، أو تصحيح شركة منتج) ينعكس هنا فوراً بدون
     * أي كود إضافي بأي كونترولر.
     *
     * ⚠️ منذ إعادة هيكلة المشتريات (2026-08): هذه الدالة أصبحت معلوماتية
     * بحتة فقط (عرض بشاشة تفاصيل المورد)، ولم تعد تُستخدم بمنطق اختيار
     * المورد الفعلي — ذلك انتقل بالكامل لـ preferredCompanies() أعلاه،
     * لأن اختيار المورد لم يعد مبنياً على supplier_products (الأسعار)
     * بل على تفضيل الشركة الصريح المُدخَل يدوياً.
     *
     * @return array<string>
     */
    public function distributedCompanies(): array
    {
        return $this->products()
            ->join('products', 'products.id', '=', 'supplier_products.product_id')
            ->whereNotNull('products.company')
            ->distinct()
            ->orderBy('products.company')
            ->pluck('products.company')
            ->all();
    }

    public function distributesCompany(?string $companyName): bool
    {
        if (!$companyName) {
            return false;
        }

        return in_array($companyName, $this->distributedCompanies(), true);
    }
}
