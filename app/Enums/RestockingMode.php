<?php

namespace App\Enums;

/**
 * استراتيجية الترميم المختارة لعملية رفع/تشغيل واحدة — القيمة التي
 * يختارها المستخدم بشاشة "المناقلات" (الخيار 1، 2، أو 3 بطلبك الأصلي).
 *
 * 🎯 هذا الـ Enum هو "المفتاح" الذي يستخدمه RestockingStrategyResolver
 * لاختيار كلاس الـ Strategy المناسب. إضافة خيار رابع مستقبلاً (مثلاً
 * "ترميم بناءً على موسمية الطلب") تعني: حالة جديدة هنا + كلاس Strategy
 * جديد يطبّق نفس العقد — صفر تعديل على الكونترولر أو الـ Resolver نفسه.
 * هذا بالضبط الفرق العملي بين Strategy Pattern و match/if متضخّم يكبر
 * بمكان واحد مع كل خيار جديد.
 */
enum RestockingMode: string
{
    case Sales = 'sales';
    case StandardInventory = 'standard_inventory';
    case Hybrid = 'hybrid';

    /**
     * 🆕 الوضع الرابع: المبيعات تُشغِّل الفحص، لكن الكمية = فجوة المخزون
     * القياسي (لا كمية البيع نفسها). بخلاف الوضعين الآخرين، لا "يُشغِّل
     * فحصاً فورياً لكل زوج له مخزون قياسي بالصيدليات المتأثرة" — فقط
     * الأزواج التي باعت شيئاً فعلياً بهذه الدفعة تحديداً تُقيَّم. راجع
     * SalesGatedStandardInventoryRestockingStrategy للتفاصيل الكاملة.
     */
    case SalesGatedStandardInventory = 'sales_gated_standard_inventory';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'ترميم بناءً على المبيعات',
            self::StandardInventory => 'ترميم بناءً على المخزون القياسي',
            self::Hybrid => 'ترميم مدمج (مبيعات + مخزون قياسي)',
            self::SalesGatedStandardInventory => 'مخزون قياسي مُفعَّل بالمبيعات',
        };
    }

    /** هل يشغَّل جزء "المبيعات" ضمن هذا الوضع (منفرداً أو كجزء من الهجين)؟ */
    public function usesSales(): bool
    {
        return $this === self::Sales || $this === self::Hybrid || $this === self::SalesGatedStandardInventory;
    }

    /** هل يشغَّل جزء "المخزون القياسي" ضمن هذا الوضع (منفرداً أو كجزء من الهجين)؟
     *
     * ⚠️ [مهم] SalesGatedStandardInventory تُعيد false هنا **عمداً**، رغم أن
     * حسابها الفعلي مبنيّ على المخزون القياسي بالكامل (نفس ParLevelCalculator).
     * السبب: هذه الدالة لا تعني "هل تستخدم بيانات المخزون القياسي بحسابها؟"
     * — تعني تحديداً "هل يُشغَّل فحص فوري شامل عند رفع ملف جرد؟"، وهو بالضبط
     * ما لا يريده هذا الوضع: فلسفته أن **المبيعات وحدها** هي المُحفِّز، لا رفع
     * ملف جرد. لو أعادت true هنا، كانت ستُشغِّل StandardInventoryRestockingStrategy
     * الفعلية (فحص شامل لكل الأزواج) من نقاط استدعاء لا علاقة لها بالمبيعات
     * إطلاقاً (راجع InventoryUploadService وPendingProductService) — نتيجة
     * تخالف تصميم الوضع كلياً. راجع RestockingStrategyResolver::resolveSalesComponent()
     * لكيفية توجيه جزء المبيعات لهذا الوضع تحديداً بأمان رغم usesSales()=true.
     */
    public function usesStandardInventory(): bool
    {
        return $this === self::StandardInventory || $this === self::Hybrid;
    }
}
