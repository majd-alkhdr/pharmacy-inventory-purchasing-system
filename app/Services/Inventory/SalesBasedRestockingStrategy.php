<?php
// app/Services/Inventory/SalesBasedRestockingStrategy.php

namespace App\Services\Inventory;

use App\Enums\ShortageSource;

/**
 * 🎯 محوِّل (Adapter) رفيع فوق InvoiceGenerationService — بنفس فلسفة
 * StandardInventoryRestockingStrategy بالضبط: منطق التوليد الفعلي (قفل
 * المخزون المركزي، استثناء الشركات، حساب الشحن والعجز) يبقى بالكامل
 * داخل InvoiceGenerationService المُختبَرة أصلاً؛ هذا الكلاس فقط يُترجم
 * بين شكل الإدخال/الإخراج الموحَّد لنظام الاستراتيجيات وشكل الخدمة
 * الأصلية (التي تتوقع sessionId فقط، وتُعيد مصفوفة بدل RestockingResult).
 *
 * ⚠️ ملاحظة مهمة حول RestockingContext هنا تحديداً: هذه الاستراتيجية
 * تستهلك $context->sessionId **فقط**. لا تستهلك pharmacyIds (نطاق
 * الترميم هنا محدَّد ضمنياً بمحتوى temporary_sales لهذه الجلسة نفسها،
 * لا بقائمة صيدليات صريحة)، ولا تستهلك excludedCompanies (تُشتق داخلياً
 * من ImportSession الخاص بالجلسة — نفس المصدر المستخدَم من
 * ShortageFulfillmentService أصلاً، فتمريرها هنا مجدَّداً كان سيعني
 * مصدرَي حقيقة لنفس القيمة). هذا التفاوت في الاستهلاك بين الاستراتيجيات
 * الثلاث هو بالضبط سبب تصميم RestockingContext بحقول متعددة اختيارية
 * بدل توقيع خاص لكل استراتيجية — راجع تعليق RestockingContext نفسه.
 */
final class SalesBasedRestockingStrategy implements RestockingStrategy
{
    public function __construct(
        private readonly InvoiceGenerationService $engine,
    ) {}

    public function execute(RestockingContext $context): RestockingResult
    {
        // 🛡️ بلا جلسة مبيعات، لا يوجد شيء لهذه الاستراتيجية لتنفيذه —
        // حالة متوقَّعة تماماً عند تشغيل Hybrid لدفعة معينة بلا ملف مبيعات
        // مرفوع (تذكير: ملف المبيعات اختياري بالكامل بطلب المشروع الأصلي).
        if (is_null($context->sessionId)) {
            return RestockingResult::empty(ShortageSource::Sales);
        }

        $raw = $this->engine->generateInvoicesFromTemp($context->sessionId);

        if (!($raw['success'] ?? false)) {
            // 🎯 فرّقنا الآن بين حالتين مختلفتين تماماً كانتا تبدوان
            // متطابقتين قبل تعديل InvoiceGenerationService (كلاهما
            // success:false بلا أي تمييز):
            //
            // 1) 'no_data' — لا يوجد ملف مبيعات لهذه الجلسة أصلاً (متوقَّع
            //    تماماً، الملف اختياري). لا خطأ هنا — نتيجة فارغة بهدوء،
            //    والوضع الهجين يكمل باستراتيجية المخزون القياسي بلا توقف.
            //
            // 2) أي سبب آخر — خطأ حقيقي غير متوقَّع (استثناء بالخدمة
            //    الأصلية). هنا **نرمي استثناءً عمداً** بدل إعادة نتيجة
            //    فارغة صامتة: لو ابتلعنا الخطأ بصمت، كان تشغيل هذه
            //    الاستراتيجية ضمن HybridRestockingStrategy سيكمل بهدوء
            //    لاستراتيجية المخزون القياسي رغم فشل جزء المبيعات فعلياً —
            //    حالة نصف مكتملة يصعب تفسيرها لاحقاً. برمي الاستثناء هنا،
            //    معاملة DB::transaction المحيطة (سواء بهذه الاستراتيجية
            //    منفردة أو ضمن Hybrid) تتراجع تلقائياً بالكامل، ويصل
            //    الخطأ لطبقة الكونترولر التي تلتقطه أصلاً بنفس نمط
            //    try/catch المُتَّبع بباقي التطبيق (راجع مثلاً
            //    fulfillShortages() بـ InventoryController).
            if (($raw['reason'] ?? null) === 'no_data') {
                return RestockingResult::empty(ShortageSource::Sales);
            }

            throw new \RuntimeException($raw['message'] ?? 'فشل توليد فواتير المناقلة من المبيعات.');
        }

        return new RestockingResult(
            source: ShortageSource::Sales,
            pairsEvaluated: $raw['pairs_evaluated'] ?? 0,
            invoicesCreated: $raw['invoices_created'] ?? 0,
            fulfilledCount: $raw['fulfilled_count'] ?? 0,
            partialCount: $raw['partial_count'] ?? 0,
            shortagesRecorded: $raw['shortages_recorded'] ?? 0,
            perPairSupplied: $raw['per_pair_supplied'] ?? [], // 🆕
            message: $raw['message'] ?? null,
        );
    }

    public function source(): ShortageSource
    {
        return ShortageSource::Sales;
    }
}
