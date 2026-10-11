<?php

declare(strict_types=1);

namespace App\Services\Inventory\Surplus;

use App\Enums\ImportFileType;
use App\Models\ImportLog;

/**
 * 🎯 مصدر الحقيقة الوحيد لسؤال "ما الدورة الحالية؟" — يستهلكه SurplusEngine (لحصر
 * الالتزامات)، ومُسجِّل المناقلات العكسية ومخطِّط المناقلات بين الصيدليات (لوسم ما
 * يُنشئانه)، وشارة "آخر تحديث للبيانات" (ج.3).
 *
 * 🎓 لماذا كلاس منفصل عن SurplusEngine؟ لأن من يحتاج "الدورة" أكثر ممن يحتاج "الفائض":
 * وسم مستند بدورته لا يتطلب حساب فائض أي زوج. فصلهما يُبقي المستهلك الخفيف خفيفاً.
 */
final class InventoryCycleResolver
{
    public function current(): InventoryCycle
    {
        return InventoryCycle::fromLog(ImportLog::latestSuccessful(ImportFileType::inventoryCycleTypes()));
    }
}
