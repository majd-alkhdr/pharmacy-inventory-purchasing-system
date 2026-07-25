<?php

namespace App\Enums;

/**
 * 🎯 يمثّل "محرك السيناريوهات" (Scenario Builder) الموصوف بقسم 3 من
 * مستندك — الثلاثة خيارات بالضبط كما وصفتها:
 * "ترميم المخزون فقط" / "الربحية الأعلى" / "الشامل المتوازن (الافتراضي)".
 *
 * هذا الـ Enum لا يحمل منطقاً حسابياً بنفسه (بعكس ItemAvailability
 * بالمرحلة الأولى الذي يحمل fromQuantities()) — لأن منطق كل سيناريو
 * معقّد بما يكفي (فلترة + ترتيب أولويات + قرارات ميزانية) ليستحق كلاس
 * Service مستقل خاص به بمرحلة بناء المحرك القادمة، وليس مجرد match()
 * داخل الـ Enum. الـ Enum هنا دوره فقط: **تسمية آمنة** للخيار المُختار.
 */
enum PurchaseScenario: string
{
    case RestockingOnly    = 'restocking_only';
    case MaxProfitability  = 'max_profitability';
    case Balanced           = 'balanced';

    public function label(): string
    {
        return match ($this) {
            self::RestockingOnly   => 'ترميم المخزون فقط (أولوية السرعة)',
            self::MaxProfitability => 'الربحية الأعلى (استغلال العروض)',
            self::Balanced          => 'الشامل المتوازن (افتراضي)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::RestockingOnly   => 'يغطي النواقص الصفرية وما هبط تحت الحد الأدنى فقط، بغض النظر عن العروض.',
            self::MaxProfitability => 'يوجّه الطلبات نحو أفضل بونص/عروض، ويرفع كميات أصناف التارجت لتحقيق شرائح خصم أعلى.',
            self::Balanced          => 'يوازن بين توفير النواقص بسرعة واستغلال العروض المتاحة.',
        };
    }
}
