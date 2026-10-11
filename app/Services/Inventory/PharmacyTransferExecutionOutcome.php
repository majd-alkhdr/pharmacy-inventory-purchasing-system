<?php

declare(strict_types=1);

namespace App\Services\Inventory;

/**
 * 🎯 نتيجة الموافقة على مناقلة مقترَحة — راجع تعليق PharmacyTransferExecutionService
 * لسبب وجود ثلاث حالات لا حالة نجاح/فشل ثنائية: الرصيد الفعلي وقت الموافقة قد
 * يختلف عمّا كان وقت الاقتراح (اقتراح آخر نُفِّذ بينهما مثلاً)، فتنفيذ كل صنف
 * على حدة قد يكون كاملاً، جزئياً، أو معدوماً — والمستخدم يستحق معرفة أيّها بالضبط.
 */
final class PharmacyTransferExecutionOutcome
{
    public function __construct(
        public readonly int $fullyExecutedCount,
        public readonly int $partiallyExecutedCount,
        public readonly int $skippedCount,
    ) {
    }

    public function wasFullyAsSuggested(): bool
    {
        return $this->partiallyExecutedCount === 0 && $this->skippedCount === 0;
    }
}
