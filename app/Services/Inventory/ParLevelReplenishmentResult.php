<?php
// app/Services/Inventory/ParLevelReplenishmentResult.php

namespace App\Services\Inventory;

/**
 * ⚠️ [مهجورة منذ 2026-09] لم تعد ParLevelReplenishmentService::replenish()
 * تُعيد هذا الشكل — استُبدلت بـ RestockingResult (شكل موحَّد تُعيده كل
 * استراتيجيات الترميم الثلاث، لا هذه فقط). أُبقيت بالكود بلا حذف تمهيداً
 * لتنظيف لاحق، بنفس فلسفة الإبقاء على InvoiceGenerationService بلا حذف.
 */
final class ParLevelReplenishmentResult
{
    public function __construct(
        public readonly int $pairsEvaluated = 0,
        public readonly int $fulfilledCount = 0,
        public readonly int $partialCount = 0,
        public readonly int $shortagesRecorded = 0,
        public readonly int $shortagesResolved = 0,
    ) {}

    public static function empty(): self
    {
        return new self();
    }
}
