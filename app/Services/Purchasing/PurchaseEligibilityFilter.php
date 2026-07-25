<?php

namespace App\Services\Purchasing;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * 🎯 "الطبقة الأولى" فقط من فلتر الاستبعاد بمستندك (فحص الصلاحية/الركود).
 * أي منتج مُعلَّم is_stagnant=true بواسطة StagnantProductDetectionService
 * يُستبعد بالكامل من ترشيحات الشراء — لا يُحسَب له احتياج إطلاقاً، بغض
 * النظر عن معدل استهلاكه أو رصيده.
 *
 * ⚠️ "الطبقة الثانية" (فحص الطلبيات قيد التوريد) **ليست هنا عمداً** —
 * راجع الشرح المفصَّل بتعليق OpenPurchaseOrderQuantityService: هي خصم
 * ضمن معادلة الاحتياج، وليست استبعاداً منفصلاً. وضعها هنا كان سيُنتج
 * تكراراً منطقياً (Logic Duplication) بين مكانين يفعلان نفس الشيء
 * بصيغتين مختلفتين.
 */
class PurchaseEligibilityFilter
{
    public function eligibleProductsQuery(): Builder
    {
        return Product::query()
            ->whereDoesntHave('purchaseSetting', fn (Builder $q) => $q->where('is_stagnant', true));
    }

    public function isEligible(Product $product): bool
    {
        return !($product->purchaseSetting?->is_stagnant ?? false);
    }
}
