<?php

declare(strict_types=1);

namespace App\Services\Purchasing\SupplierScope;

use App\Models\Supplier;
use InvalidArgumentException;

/**
 * 🎯 (ج.1) الاستراتيجية الحالية: المستخدم يختار مورداً أو أكثر مباشرة (أو لا شيء = كل الموردين
 * — نفس الخيار القديم). تُولَّد فاتورة مستقلة لكل مورد مختار عبر نفس منطق التوليد.
 */
final class DirectSupplierScopeStrategy implements SupplierScopeStrategy
{
    public function resolve(array $selectedSupplierIds, ?string $company): SupplierScope
    {
        $ids = array_values(array_unique(array_map('intval', $selectedSupplierIds)));
        if ($ids === []) {
            return SupplierScope::allSuppliers();
        }

        $active = Supplier::active()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $inactive = array_diff($ids, $active);
        if ($inactive !== []) {
            throw new InvalidArgumentException('بعض الموردين المختارين غير موجودين أو غير مفعَّلين.');
        }

        return new SupplierScope($ids);
    }
}
