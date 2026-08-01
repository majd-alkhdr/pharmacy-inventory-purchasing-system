<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Collection;

/**
 * 🎯 يمثّل نتيجة محاولة اختيار مورد لمنتج معيّن حسب شركته المصنّعة —
 * ثلاث حالات ممكنة فقط:
 *
 *   1) resolved: تم اختيار مورد واحد بوضوح (سواء لأنه الوحيد المفضِّل
 *      لهذه الشركة، أو لأن المشرف اختاره صراحة عبر شاشة حل التعارض).
 *   2) conflict: يوجد أكثر من مورد يفضّل نفس الشركة، ولم يُحدَّد أي
 *      اختيار بعد لهذه الدفعة (Session) — يحتاج تدخلاً يدوياً.
 *   3) none: لا يوجد أي مورد يفضّل هذه الشركة إطلاقاً.
 *
 * 🎓 لماذا DTO صريح بدل إرجاع Supplier|null من resolveFor() مباشرة؟
 * لأن null وحدها لا تُفرِّق بين "لا يوجد مورد إطلاقاً" (حالة 3، تُحسَب
 * ضمن productsWithNoSupplier) و"يوجد أكثر من مورد ولم يُختَر أحدهم بعد"
 * (حالة 2، يجب جمعها لعرضها كشاشة اختيار قبل إكمال التوليد) — هذا الفرق
 * جوهري لسلوك PurchaseOrderGenerationService بالكامل.
 */
final class SupplierSelectionResult
{
    private function __construct(
        public readonly ?int $supplierId,
        public readonly string $status, // 'resolved' | 'conflict' | 'none'
        public readonly Collection $conflictingSupplierIds = new Collection(),
    ) {
    }

    public static function resolved(int $supplierId): self
    {
        return new self($supplierId, 'resolved');
    }

    public static function conflict(Collection $conflictingSupplierIds): self
    {
        return new self(null, 'conflict', $conflictingSupplierIds);
    }

    public static function none(): self
    {
        return new self(null, 'none');
    }

    public function isResolved(): bool { return $this->status === 'resolved'; }
    public function isConflict(): bool { return $this->status === 'conflict'; }
    public function isNone(): bool { return $this->status === 'none'; }
}
