<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseScenario;

/**
 * 🎯 يمثّل "مدخلات المشرف" (Supervisor Parameters) الموصوفة بقسم 1 من
 * مستندك: مدة التنبؤ، نطاق الفاتورة (مورد محدد أو الكل)، السيناريو
 * المُختار، وتحديد يدوي اختياري لسقف الميزانية لهذا التشغيل بالذات.
 *
 * 🎓 لماذا DTO بدل تمرير 5 معاملات منفصلة لدالة generate()؟
 * لأن قائمة معاملات طويلة (Long Parameter List) هي "رائحة كود" (Code
 * Smell) معروفة — يصعب تتبع أي معامل يقابل أي قيمة عند الاستدعاء، وأي
 * معامل جديد مستقبلاً (مثلاً: استبعاد صيدليات معينة) يعني تعديل توقيع
 * الدالة بكل مكان تُستدعى منه. بكائن واحد، إضافة حقل جديد لا يكسر أي
 * استدعاء قائم (طالما له قيمة افتراضية معقولة).
 */
final class GeneratePurchaseOrdersParams
{
    public function __construct(
        public readonly int $forecastPeriodDays,
        public readonly PurchaseScenario $scenario,
        public readonly ?int $supplierId = null,
        public readonly ?float $manualBudgetOverride = null,
    ) {
    }
}
