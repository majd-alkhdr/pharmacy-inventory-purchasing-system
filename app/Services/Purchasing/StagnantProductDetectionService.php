<?php

namespace App\Services\Purchasing;

use App\Models\InventoryBatch;
use App\Models\ProductPurchaseSetting;
use Illuminate\Support\Collection;

/**
 * 🎯 "الطبقة الأولى" من فلتر الاستبعاد بمستندك حرفياً: *"يقوم النظام
 * بمسح الأرصدة الحالية؛ أي صنف تاريخ صلاحيته قريب... يتم استبعاده فوراً
 * من خوارزمية الشراء، ويُوضع بقائمة الأصناف الراكدة"*.
 *
 * 🎓 نقطة تصميمية مهمة: هذه الخدمة **تُعيد تقييم كل شيء من الصفر** بكل
 * تشغيل (Idempotent)، ولا "تراكم" أعلاماً فقط. لماذا هذا مهم؟
 * تخيّل: مادة كانت راكدة الأسبوع الماضي (دفعة قريبة الانتهاء)، لكن
 * جاءت دفعة جديدة طازجة هذا الأسبوع بيعت منها الدفعة القديمة بالكامل.
 * لو اكتفينا بـ"إضافة" أعلام دون "إزالة" ما لم يعد ينطبق، ستبقى هذه
 * المادة موسومة "راكدة" للأبد رغم أن وضعها تغيّر فعلياً. لذلك كل
 * تشغيل يُصحّح كلا الاتجاهين: يُضيف أعلاماً جديدة **ويُزيل** ما لم يعد
 * محقّاً.
 */
class StagnantProductDetectionService
{
    /**
     * @return array{flagged: int, unflagged: int}
     */
    public function handle(int $monthsThreshold = 6): array
    {
        $currentlyStagnantProductIds = InventoryBatch::query()
            ->usable()
            ->nearingExpiry($monthsThreshold)
            ->distinct()
            ->pluck('product_id');

        return [
            'flagged'   => $this->flagAsStagnant($currentlyStagnantProductIds),
            'unflagged' => $this->unflagNoLongerStagnant($currentlyStagnantProductIds),
        ];
    }

    private function flagAsStagnant(Collection $productIds): int
    {
        $newlyFlaggedCount = 0;

        foreach ($productIds as $productId) {
            $setting = ProductPurchaseSetting::firstOrNew(['product_id' => $productId]);

            if (!$setting->is_stagnant) {
                $newlyFlaggedCount++;
                $setting->stagnant_marked_at = now();
            }

            $setting->is_stagnant = true;
            $setting->save();
        }

        return $newlyFlaggedCount;
    }

    private function unflagNoLongerStagnant(Collection $stillStagnantProductIds): int
    {
        return ProductPurchaseSetting::where('is_stagnant', true)
            ->whereNotIn('product_id', $stillStagnantProductIds)
            ->update(['is_stagnant' => false, 'stagnant_marked_at' => null]);
    }
}
