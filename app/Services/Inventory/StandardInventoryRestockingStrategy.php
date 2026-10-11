<?php
// app/Services/Inventory/StandardInventoryRestockingStrategy.php

namespace App\Services\Inventory;

use App\Enums\ShortageSource;

/**
 * 🎯 محوِّل (Adapter) رفيع فوق ParLevelReplenishmentService — يكسو
 * الخدمة "المحرك" الموجودة أصلاً بعقد RestockingStrategy الموحَّد، بلا
 * إعادة كتابة منطقها (المُختبَر أصلاً ومُستخدَم فعلياً بالإنتاج).
 *
 * 🎓 لماذا Adapter رفيع بدل نقل كل منطق ParLevelReplenishmentService
 * لهذا الكلاس مباشرة؟ لأن الخدمة الأصلية اسمٌ نطاقي (Domain) وصفي —
 * "محرك ترميم المخزون القياسي" — بينما هذا الكلاس اسمٌ نمطي (Pattern) —
 * "استراتيجية مخزون قياسي ضمن نظام المناقلات". الفصل يبقي كل كلاس بمسؤولية
 * واحدة: ParLevelReplenishmentService يعرف "كيف نحسب ونُنفِّذ الترميم"،
 * وهذا الكلاس يعرف "كيف يُستدعى هذا ضمن نظام الاستراتيجيات الثلاث
 * الموحَّد" فقط. لو احتاج المشروع مستقبلاً استدعاء محرك الترميم مباشرة
 * من مكان لا علاقة له بـ"المناقلات" (تقرير مستقل، أمر Artisan تشخيصي)،
 * يبقى ذلك ممكناً بلا اضطرار لبناء RestockingContext كاملاً لغرضه —
 * ولا يضطر أي مستهلك حالي للخدمة الأصلية لتغيير طريقة استدعائه.
 */
final class StandardInventoryRestockingStrategy implements RestockingStrategy
{
    public function __construct(
        private readonly ParLevelReplenishmentService $engine,
    ) {}

    public function execute(RestockingContext $context): RestockingResult
    {
        return $this->engine->replenish(
            pharmacyIds: $context->pharmacyIds,
            excludedCompanies: $context->excludedCompanies,
            reconcileAgainst: $context->reconcileAgainst, // 🆕 راجع تعليق RestockingContext وParLevelReplenishmentService
        );
    }

    public function source(): ShortageSource
    {
        return ShortageSource::StandardInventory;
    }
}
