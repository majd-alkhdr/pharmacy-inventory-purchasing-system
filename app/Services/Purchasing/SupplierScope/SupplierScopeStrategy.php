<?php

declare(strict_types=1);

namespace App\Services\Purchasing\SupplierScope;

/**
 * 🎯 (ج.1) عقد "كيف نصل لقائمة الموردين" — نفس فلسفة RestockingStrategy بالضبط: كل طريقة
 * اختيار كلاس مستقل، والمُنسِّق لا يعرف إلا هذا العقد. طريقة ثالثة لاحقاً (مثلاً "حسب التصنيف")
 * = كلاس جديد + حالة Enum + سطر بالـ Resolver، صفر تعديل على PurchaseOrderGenerationService.
 */
interface SupplierScopeStrategy
{
    /**
     * @param list<int> $selectedSupplierIds ما اختاره المستخدم (قد تكون فارغة بالاستراتيجية المباشرة = الكل)
     * @throws \InvalidArgumentException اختيار لا يتسق مع الاستراتيجية (مورد لا يحمل الشركة المختارة...)
     */
    public function resolve(array $selectedSupplierIds, ?string $company): SupplierScope;
}
