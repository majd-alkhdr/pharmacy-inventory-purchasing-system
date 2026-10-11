<?php

declare(strict_types=1);

namespace App\Services\Inventory;

/**
 * 🎯 نتيجة "كم من effectiveGap يمكن تغطيته الآن؟" بمعرفة الرصيد المركزي
 * المتاح فعلياً هذه اللحظة (بعد ما استهلكته أزواج أخرى بنفس المنتج، إن
 * وُجدت، بنفس الدفعة — تلك إدارة مورد مشترك مسؤولية المستدعي، لا هذا الكلاس).
 */
final class ParLevelAllocation
{
    public function __construct(
        public readonly int $suppliedNow,
        public readonly int $remainingGap,
    ) {
    }

    public function isFullyCovered(): bool
    {
        return $this->remainingGap === 0;
    }

    public function suppliedAnything(): bool
    {
        return $this->suppliedNow > 0;
    }
}
