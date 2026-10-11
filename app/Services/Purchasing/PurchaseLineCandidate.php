<?php
// app/Services/Purchasing/PurchaseLineCandidate.php
namespace App\Services\Purchasing;

use App\Models\Product;

final class PurchaseLineCandidate
{
    public function __construct(
        public readonly Product $product,
        public readonly RequiredQuantityResult $requirement,
        public readonly PurchaseLineDecision $decision,
        public readonly bool $isTargetItem,     // معلوماتي فقط — لا يؤثر على الأولوية
        public readonly bool $isMarketShortage, // استثناء ميزانية منفصل تماماً، بلا تغيير
    ) {}

    public function priorityLabel(): string
    {
        return match (true) {
            $this->isMarketShortage => 'قنص نواقص سوق (استثناء ميزانية)',
            $this->isTargetItem     => 'صنف تارجت (معلوماتي)',
            default                   => 'عادي',
        };
    }
}
