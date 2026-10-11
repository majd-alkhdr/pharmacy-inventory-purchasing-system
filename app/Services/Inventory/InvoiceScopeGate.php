<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\InvoiceGenerationScope;
use Closure;

/**
 * 🎯 (ب.4) المكان الوحيد الذي يُطبَّق فيه اختيار "نوع الفواتير المولَّدة". كل نقطة استدعاء
 * (الرفع المباشر، تأكيد المواد المعلَّقة، إكمال المبيعات بعد المطابقة) تمرّر خطوتها عبر
 * هنا بدل أن تكتب `if ($scope->includesBasic())` بنفسها — فلو أُضيف نوع رابع لاحقاً، يُعدَّل
 * هذا الكلاس والـ Enum فقط.
 *
 * ⚖️ التخطّي يُعيد نتيجة "فارغة" بنفس الشكل (RestockingResult::empty / مصفوفة عدّادات
 * صفرية) لا null — المستدعون يبنون رسالة الملخص من النتيجة كما كانوا، بلا فروع جديدة.
 */
final class InvoiceScopeGate
{
    public function __construct(
        private readonly ShortageFulfillmentService $shortageFulfillment,
    ) {
    }

    /** @param Closure(): RestockingResult $generateBasic */
    public function basic(InvoiceGenerationScope $scope, Closure $generateBasic): RestockingResult
    {
        return $scope->includesBasic() ? $generateBasic() : RestockingResult::empty();
    }

    /**
     * @param array<string> $excludedCompanies
     * @return array{fulfilled_count: int, partial_count: int, blocked_by_minimum_count: int}
     */
    public function shortages(InvoiceGenerationScope $scope, array $excludedCompanies): array
    {
        return $scope->includesShortages()
            ? $this->shortageFulfillment->fulfillActiveShortages($excludedCompanies)
            : ['fulfilled_count' => 0, 'partial_count' => 0, 'blocked_by_minimum_count' => 0];
    }

    /** سطر يُلحَق بملخص الرفع حين يُتخطّى نوع — حتى لا يبدو "صفر فواتير" خطأً. */
    public function note(InvoiceGenerationScope $scope): string
    {
        return match ($scope) {
            InvoiceGenerationScope::Basic => ' 🧾 (بحسب اختيارك: فواتير أساسية فقط — لم تُولَّد فواتير ترميم نواقص.)',
            InvoiceGenerationScope::Shortages => ' 🧾 (بحسب اختيارك: فواتير نواقص فقط — لم تُولَّد فواتير أساسية ولم تُسجَّل نواقص جديدة.)',
            InvoiceGenerationScope::Both => '',
        };
    }
}
