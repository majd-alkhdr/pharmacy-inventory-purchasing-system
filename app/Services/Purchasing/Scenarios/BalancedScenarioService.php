<?php

namespace App\Services\Purchasing\Scenarios;

use App\Models\Product;
use App\Services\Purchasing\ProductDemandCalculation;
use App\Services\Purchasing\PurchaseLineDecision;
use App\Services\Purchasing\SupplierPricingResolver;

/**
 * 🎯 يطبّق وصف مستندك: *"يوازن بين توفير النواقص بسرعة، وبين استغلال
 * العروض المتاحة، وهو السيناريو الافتراضي."*
 *
 * الفرق الجوهري عن "ترميم فقط": يستخدم SupplierPricingResolver لمقارنة
 * كل الموردين المتاحين عند **الكمية المطلوبة بالضبط** (لا أقل ولا أكثر)،
 * فيستفيد تلقائياً من أي عرض سارٍ فعلاً عند هذه الكمية — لكن **لا يُضخّم**
 * الكمية بحثاً عن شريحة أعلى (هذا محصور بسيناريو "الربحية الأعلى" فقط،
 * لأن تضخيم الكمية يعني تجميد رأس مال إضافي — قرار يستحق سيناريو صريح
 * منفصل يختاره المشرف عمداً، وليس سلوكاً افتراضياً صامتاً).
 */
class BalancedScenarioService implements PurchaseScenarioStrategy
{
    public function __construct(
        private readonly SupplierPricingResolver $pricingResolver,
    ) {
    }

    public function decide(Product $product, ProductDemandCalculation $demand): ?PurchaseLineDecision
    {
        if (!$demand->hasRequirement()) {
            return null;
        }

        return $this->pricingResolver->resolveBestChoiceAt($product, $demand->requiredQty);
    }
}
