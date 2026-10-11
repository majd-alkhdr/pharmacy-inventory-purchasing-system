<?php

declare(strict_types=1);

namespace App\Services\Purchasing\SupplierScope;

use App\Enums\SupplierScopeMode;

/**
 * 🎯 (ج.1) نفس دور RestockingStrategyResolver: يحوّل اختيار المستخدم (Enum) لكائن الاستراتيجية.
 * match() بسيط يكفي — الاستراتيجيتان كلاسات محدَّدة تبنيها الحاوية تلقائياً.
 */
final class SupplierScopeStrategyResolver
{
    public function __construct(
        private readonly DirectSupplierScopeStrategy $direct,
        private readonly CompanyDerivedSupplierScopeStrategy $byCompany,
    ) {
    }

    public function resolve(SupplierScopeMode $mode): SupplierScopeStrategy
    {
        return match ($mode) {
            SupplierScopeMode::DirectSuppliers => $this->direct,
            SupplierScopeMode::ByCompany => $this->byCompany,
        };
    }
}
