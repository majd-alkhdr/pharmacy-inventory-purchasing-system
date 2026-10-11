<?php

declare(strict_types=1);

namespace App\Services\Inventory\InvoiceEditing;

/**
 * 🎯 خطة تعديل محسوبة بالكامل قبل أي كتابة — نفس فلسفة MatrixWritePlan (خطة ثم تنفيذ):
 * أي خطأ بالمدخلات يُكتشف قبل لمس قاعدة البيانات. مُجمِّع داخلي قابل للتغيّر عمداً.
 *
 * @internal خاص بـ InvoiceEditingService
 */
final class InvoiceEditPlan
{
    /** @var array<int, int> [item_id => الكمية الجديدة] */
    public array $updates = [];

    /** @var list<int> */
    public array $removals = [];

    /** @var array<int, int> [product_id => الكمية] */
    public array $additions = [];

    /** @var array<int, int> [product_id => فرق المركزي: موجب يُعاد، سالب يُخصم] */
    public array $centralDeltas = [];

    public function addCentralDelta(int $productId, int $delta): void
    {
        $this->centralDeltas[$productId] = ($this->centralDeltas[$productId] ?? 0) + $delta;
    }

    public function isEmpty(): bool
    {
        return $this->updates === [] && $this->removals === [] && $this->additions === [];
    }
}
