<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 🎯 (ب.4) أي أنواع فواتير المناقلة تُولَّد من دفعة الرفع هذه — اختيار بنموذج الرفع.
 *
 *  - Basic:     الفواتير "الأساسية" (processed) — ناتج استراتيجية الترميم المختارة
 *               (مبيعات/مخزون قياسي/مدمج/...)، ومعها تسجيل النواقص الجديدة التي تكشفها.
 *  - Shortages: فواتير "ترميم النواقص" (supplementary_invoice) فقط — من جدول النواقص القائم.
 *  - Both:      السلوك القديم كما هو (الافتراضي — لا يتغيّر شيء لمن لا يلمس الخيار).
 *
 * 🎓 لماذا Enum بدوال سلوك لا Strategy كاملة؟ فحصتُ كود التوليد الفعلي: القرار هنا
 * "تشغيل/تخطّي" لخطوتين مستقلتين موجودتين أصلاً (strategy->execute و fulfillActiveShortages)،
 * لا "كيف" تُحسب أي منهما. كلاسات Strategy منفصلة كانت ستغلّف سطراً شرطياً واحداً بكل
 * منها — تعقيد بلا مقابل. الشرط نفسه يعيش بمكان واحد: InvoiceScopeGate.
 */
enum InvoiceGenerationScope: string
{
    case Basic = 'basic';
    case Shortages = 'shortages';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Basic => 'فواتير أساسية فقط',
            self::Shortages => 'فواتير نواقص فقط',
            self::Both => 'الاثنين معاً',
        };
    }

    public function includesBasic(): bool
    {
        return $this !== self::Shortages;
    }

    public function includesShortages(): bool
    {
        return $this !== self::Basic;
    }
}
