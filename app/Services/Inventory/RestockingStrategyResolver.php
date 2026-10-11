<?php
// app/Services/Inventory/RestockingStrategyResolver.php

namespace App\Services\Inventory;

use App\Enums\RestockingMode;

/**
 * 🎯 نقطة الدخول الوحيدة لطبقة الكونترولر/الـ Job لاختيار استراتيجية
 * الترميم المناسبة — مسؤوليتها الوحيدة: تحويل RestockingMode (اختيار
 * المستخدم بالشاشة) إلى كائن RestockingStrategy الصحيح. لا تعرف شيئاً
 * عن كيفية عمل أي استراتيجية داخلياً — فقط "أيّهم" يُشغَّل.
 *
 * 🎓 لماذا match() بسيط هنا كافٍ، ولا نحتاج IoC binding بـ AppServiceProvider؟
 * لأن كل استراتيجية (SalesBasedRestockingStrategy، StandardInventoryRestockingStrategy،
 * HybridRestockingStrategy) تعتمد بمُنشئها على كلاسات **محدَّدة (concrete)**
 * فقط (لا على واجهة RestockingStrategy نفسها) — حاوية Laravel (Container)
 * تبنيها تلقائياً بالـ Reflection بلا أي إعداد إضافي، تماماً كأي كلاس
 * عادي آخر بالمشروع. هذا الـ Resolver نفسه يُبنى بنفس الطريقة تلقائياً
 * لو طُلب بمُنشئ أي Controller — لا حاجة لتسجيله بأي مكان.
 *
 * 🎓 إضافة استراتيجية رابعة مستقبلاً: كلاس جديد يطبّق RestockingStrategy
 * + حالة enum جديدة بـ RestockingMode + سطر واحد هنا بالـ match(). لا
 * تعديل على أي كونترولر أو Job يستخدم هذا الـ Resolver — بالضبط الفائدة
 * العملية لمبدأ Open/Closed التي شرحناها بتعليق RestockingMode أصلاً.
 */
final class RestockingStrategyResolver
{
    public function __construct(
        private readonly SalesBasedRestockingStrategy $salesStrategy,
        private readonly StandardInventoryRestockingStrategy $standardInventoryStrategy,
        private readonly HybridRestockingStrategy $hybridStrategy,
        private readonly SalesGatedStandardInventoryRestockingStrategy $salesGatedStrategy, // 🆕
    ) {}

    public function resolve(RestockingMode $mode): RestockingStrategy
    {
        return match ($mode) {
            RestockingMode::Sales => $this->salesStrategy,
            RestockingMode::StandardInventory => $this->standardInventoryStrategy,
            RestockingMode::Hybrid => $this->hybridStrategy,
            RestockingMode::SalesGatedStandardInventory => $this->salesGatedStrategy, // 🆕
        };
    }

    /**
     * 🆕 "أيّ استراتيجية تحمل جزء المبيعات لهذا الوضع؟" — يُستخدَم **حصراً**
     * من نقطتين مؤجَّلتين (PendingProductService، SalesMappingService) تُكمِلان
     * جزء المبيعات بعد مطابقة أسماء يدوية، بعد أن نفَّذتا جزء المخزون القياسي
     * (إن وُجد) بشكل منفصل ومباشر مسبقاً.
     *
     * 🎓 لماذا لا يكفي resolve($mode) بهاتين النقطتين؟ لأنه لو استُدعي بوضع
     * Hybrid لأعاد HybridRestockingStrategy نفسها — التي تُنفِّذ جزء المخزون
     * القياسي **مجدَّداً** داخلياً (راجع HybridRestockingStrategy::execute())،
     * فيُشغَّل مرتين لنفس الدفعة. resolve() يُجيب "ما الاستراتيجية الكاملة
     * لهذا الوضع؟"؛ هذه الدالة تُجيب سؤالاً أضيق ومختلفاً تماماً: "ما الكلاس
     * الذي يعرف كيف يُكمِل **جزء المبيعات فقط**؟" — السؤال الثاني هو ما
     * تحتاجه هاتان النقطتان تحديداً، لا الأول.
     *
     * @throws \LogicException الوضع لا يستخدم المبيعات إطلاقاً (StandardInventory) —
     *         استدعاء خاطئ من المُستدعي (يجب فحص usesSales() قبل الاستدعاء)، لا حالة تشغيلية متوقَّعة.
     */
    public function resolveSalesComponent(RestockingMode $mode): RestockingStrategy
    {
        return match ($mode) {
            RestockingMode::Sales, RestockingMode::Hybrid => $this->salesStrategy,
            RestockingMode::SalesGatedStandardInventory => $this->salesGatedStrategy,
            RestockingMode::StandardInventory => throw new \LogicException(
                'resolveSalesComponent() استُدعيت بوضع StandardInventory الذي لا يستخدم المبيعات إطلاقاً.'
            ),
        };
    }
}
