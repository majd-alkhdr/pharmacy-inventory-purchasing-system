<?php
// app/Services/Purchasing/PriorityBasedPurchaseDecisionService.php
namespace App\Services\Purchasing;

use App\Models\Product;

class PriorityBasedPurchaseDecisionService
{
    public function __construct(
        private readonly SupplierSelectionService $supplierSelection,
    ) {}

    public function decide(Product $product, RequiredQuantityResult $requirement, float $unitCost, ?int $overrideSupplierId = null): ?PurchaseLineDecision
    {
        if (!$requirement->hasRequirement()) {
            return null;
        }

        $supplierId = $overrideSupplierId ?? $this->supplierSelection->resolveSingleSupplierFor($product);

        if (!$supplierId) {
            return null;
        }

        $qty = $requirement->requiredQty;

        return new PurchaseLineDecision(
            productId: $product->id,
            supplierId: $supplierId,
            finalQty: $qty,
            unitCost: $unitCost,
            bonusQty: 0,
            lineTotalCost: round($unitCost * $qty, 2),
            scenarioNote: 'تم اختيار المورد تلقائياً حسب الشركة المفضّلة المسجَّلة له.',
        );
    }
}
