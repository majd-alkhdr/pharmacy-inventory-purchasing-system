<?php
// app/Services/Purchasing/GeneratePurchaseOrdersParams.php
namespace App\Services\Purchasing;

use App\Enums\PurchaseGenerationMode;
use App\Enums\PurchaseScenario;
use App\Enums\ShortageSource;
use App\Services\Purchasing\SupplierScope\SupplierScope;

final class GeneratePurchaseOrdersParams
{
    /**
     * 🆕 (ج.1) $supplierScope: ناتج استراتيجية النطاق الموحَّد (مورد/موردون مباشرة، أو مشتق من
     * شركة). null = بُني بالطريقة القديمة — يُشتق من $supplierId (مُجمَّد للتوافق مع أي مستدعٍ قديم).
     */
    public function __construct(
        public readonly int $forecastPeriodDays,
        public readonly PurchaseScenario $scenario,
        public readonly PurchaseGenerationMode $generationMode,
        public readonly ?ShortageSource $shortageSourceFilter = null,
        public readonly ?int $supplierId = null,
        public readonly ?float $manualBudgetOverride = null,
        public readonly array $supplierOverrides = [],
        private readonly ?SupplierScope $supplierScope = null,
    ) {}

    public function supplierScope(): SupplierScope
    {
        return $this->supplierScope ?? new SupplierScope($this->supplierId ? [$this->supplierId] : []);
    }
}
