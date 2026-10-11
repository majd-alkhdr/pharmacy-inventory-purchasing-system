<?php

declare(strict_types=1);

namespace App\Services\Inventory\InvoiceEditing;

/** 🎯 (ب.2) ما الذي تغيّر فعلاً بحفظ واحد — لرسالة النجاح فقط. */
final class InvoiceEditSummary
{
    public function __construct(
        public readonly int $changed,
        public readonly int $removed,
        public readonly int $added,
    ) {
    }

    public function message(): string
    {
        if ($this->changed + $this->removed + $this->added === 0) {
            return 'ℹ️ لم يتغيّر شيء — الكميات المُرسلة مطابقة للحالية.';
        }

        return "✅ حُفظت التعديلات: {$this->changed} كمية معدَّلة، {$this->removed} مادة محذوفة، {$this->added} مادة مضافة — وسُجِّلت بسجل النشاط.";
    }
}
