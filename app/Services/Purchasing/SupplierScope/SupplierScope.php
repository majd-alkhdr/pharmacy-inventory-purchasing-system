<?php

declare(strict_types=1);

namespace App\Services\Purchasing\SupplierScope;

/**
 * 🎯 (ج.1) الناتج **الموحَّد** لأي استراتيجية نطاق — وهو كل ما يعرفه منطق التوليد عن طريقة
 * الاختيار. الاستراتيجيتان تختلفان فقط بكيفية الوصول لهذه القائمة؛ ما بعدها مسار واحد بلا تكرار.
 *
 * supplierIds فارغة = كل الموردين (السلوك القديم لخيار "كل الموردين معاً").
 * companyFilter: حصر المواد بشركة واحدة (استراتيجية الشركة) — null = كل شركات الموردين المختارين.
 */
final class SupplierScope
{
    /** @param list<int> $supplierIds */
    public function __construct(
        public readonly array $supplierIds,
        public readonly ?string $companyFilter = null,
    ) {
    }

    public static function allSuppliers(): self
    {
        return new self([]);
    }

    public function isAllSuppliers(): bool
    {
        return $this->supplierIds === [];
    }

    public function includes(int $supplierId): bool
    {
        return $this->isAllSuppliers() || in_array($supplierId, $this->supplierIds, true);
    }
}
