<?php
// app/Services/Inventory/HybridRestockingStrategy.php

namespace App\Services\Inventory;

use App\Enums\ShortageSource;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 الخيار 3 الأصلي: تشغيل الاستراتيجيتين معاً ("مبيعات" + "مخزون
 * قياسي") لنفس دفعة الرفع. كل واحدة تسم نواقصها بمصدرها الصحيح بنفسها
 * (راجع SalesBasedRestockingStrategy وStandardInventoryRestockingStrategy)
 * — هذا الكلاس لا يكتب لجدول shortages مباشرة إطلاقاً، فقط يُنسِّق
 * تشغيل الاثنتين معاً ويدمج نتيجتيهما بواحدة.
 *
 * 🎓 لماذا معاملة واحدة خارجية (DB::transaction) تلف الاستراتيجيتين
 * معاً، رغم أن كل واحدة منهما تُدير معاملتها الداخلية أصلاً؟ لأن
 * "الهجين" عملية واحدة من منظور المستخدم — ضغطة زر واحدة تعني "طبّق
 * كلا الأساسين معاً الآن". بلا معاملة خارجية، لو نجحت المبيعات ثم فشل
 * المخزون القياسي بخطأ حقيقي غير متوقَّع، كانت النتيجة حالة مشوَّهة
 * يصعب تفسيرها لاحقاً: فواتير مبيعات موجودة فعلياً، لكن لا فواتير مخزون
 * قياسي، بلا أي مؤشر واضح للمستخدم أن نصف العملية فقط تمَّت. المعاملة
 * الخارجية تضمن: إما كل شيء يُطبَّق معاً، أو لا شيء إطلاقاً — بالضبط
 * نفس مبدأ "خطوات متعددة = معاملة واحدة" المُتَّبع بكل خدمة أخرى بهذا
 * المشروع (راجع ParLevelReplenishmentService وInvoiceGenerationService).
 *
 * ⚠️ لاحظ أن هذا لا يتعارض مع الاستثناء المتعمَّد لحالة "لا بيانات
 * مبيعات" (راجع SalesBasedRestockingStrategy) — تلك حالة **متوقَّعة
 * وليست خطأ**، فتُعاد كنتيجة فارغة هادئة بلا رمي استثناء، فتكمل
 * الاستراتيجية الثانية طبيعياً. الاستثناء يُرمى فقط عند خطأ **حقيقي**
 * غير متوقَّع بأي من الاستراتيجيتين — عندها فقط تتراجع المعاملة كلها.
 *
 * ملاحظة تقنية على Nested Transactions: يترجم Laravel أي DB::transaction()
 * متداخلة (سواء بأسلوب closure أو beginTransaction/commit اليدوي
 * المُستخدَم بـ InvoiceGenerationService تحديداً) إلى SAVEPOINTs تلقائياً
 * طالما كلها على نفس اتصال قاعدة البيانات (connection) الافتراضي — لا
 * حاجة لأي إعداد إضافي هنا.
 *
 * 🆕 ============ تصالح "الأكبر لا الجمع" (2026-09) ============
 * بقرار صريح من صاحب المشروع: لو نفس (الصيدلية+المادة) احتاجته
 * استراتيجيتا المبيعات والمخزون القياسي معاً بنفس الدفعة، الكمية
 * الصحيحة لشحنها هي **الأكبر بينهما، لا مجموعهما** — لأن رصيد الصيدلية
 * المرفوع (المُستخدَم بحساب فجوة المخزون القياسي) هو دائماً لقطة "بعد"
 * مبيعات اليوم، أي أن الفجوة أصلاً تعكس نفس الاستهلاك الذي تصفه المبيعات.
 * التنفيذ: بعد تشغيل المبيعات، نُمرِّر ما شحنته فعلياً (RestockingResult::perPairSupplied)
 * لاستراتيجية المخزون القياسي عبر سياق جديد (reconcileAgainst)، فتطرحه
 * من فجوتها الخاصة قبل أي تقييم — راجع تعليق ParLevelReplenishmentService
 * الكامل للرياضيات والسبب.
 *
 * ⚠️ هذا التصالح يعمل هنا فقط (المسار الذرّي الشائع) — لأن ترتيب التنفيذ
 * (مبيعات ثم مخزون قياسي، بنفس المعاملة) هو ما يجعله ممكناً أصلاً. المسار
 * النادر البديل (تأجيل جزء المبيعات لحين انتهاء مطابقة أسماء يدوية —
 * راجع InventoryUploadService وSalesMappingService::completeDeferredSalesRestocking())
 * يعكس هذا الترتيب فعلياً: المخزون القياسي يعمل فوراً، والمبيعات لاحقاً
 * بطلب مُنفصل تماماً بعد المطابقة. في تلك الحالة النادرة تحديداً، لا
 * يوجد تصالح — كلا الاستراتيجيتين قد تشحنان لنفس الزوج بلا وعي إحداهما
 * بالأخرى. محدودية معروفة وموثَّقة، لا خطأً مسكوتاً عنه؛ نفس فئة الاستثناء
 * الوحيد لمبدأ "الهجين ذرّي" المذكور أعلاه بالضبط.
 */
final class HybridRestockingStrategy implements RestockingStrategy
{
    public function __construct(
        private readonly SalesBasedRestockingStrategy $salesStrategy,
        private readonly StandardInventoryRestockingStrategy $standardInventoryStrategy,
    ) {}

    public function execute(RestockingContext $context): RestockingResult
    {
        return DB::transaction(function () use ($context) {
            // 🎯 الترتيب مقصود: المبيعات أولاً (تستهلك أولاً من رصيد
            // central_inventories الحالي)، ثم المخزون القياسي (يستهلك ما
            // تبقّى بعدها). كلاهما يقفل (lockForUpdate) فقط صفوف
            // central_inventories التي يحتاجها فعلياً، داخل هذه المعاملة
            // الخارجية نفسها — فلا خطر Race Condition بين الاستراتيجيتين
            // على نفس المنتج، وكلتاهما تريان أحدث رصيد فوراً بعد الأخرى.
            $salesResult = $this->salesStrategy->execute($context);

            // 🆕 تصالح "الأكبر لا الجمع" — راجع تعليق ParLevelReplenishmentService
            // الكامل للمنطق والسبب. نبني سياقاً جديداً للمخزون القياسي فقط
            // (لا نُعدِّل $context الأصلي، القراءة فقط readonly أصلاً)،
            // يحمل بالضبط ما شحنته المبيعات للتو بنفس هذه الدفعة.
            $standardInventoryContext = new RestockingContext(
                mode: $context->mode,
                pharmacyIds: $context->pharmacyIds,
                excludedCompanies: $context->excludedCompanies,
                sessionId: $context->sessionId,
                reconcileAgainst: $salesResult->perPairSupplied,
            );
            $standardInventoryResult = $this->standardInventoryStrategy->execute($standardInventoryContext);

            return $salesResult->mergeWith($standardInventoryResult);
        });
    }

    /**
     * 🩹 null عمداً — راجع تعليق RestockingStrategy::source() للسبب
     * الكامل: هذا الكلاس ينتج نواقص بمصدرين مختلفين معاً عبر تفويض كامل
     * لاستراتيجيتين فرعيتين، فلا قيمة واحدة من ShortageSource تُمثِّله.
     */
    public function source(): ?ShortageSource
    {
        return null;
    }
}
