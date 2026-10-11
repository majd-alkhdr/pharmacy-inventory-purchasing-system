<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\MaterialMaster;

use InvalidArgumentException;

/**
 * 🎯 صف نظيف من ملف "بيانات المواد". الفرق الجوهري عن WarehouseMatrixRow: هنا
 * **null يعني "لا تغيير"** لكل عمود اختياري — لا صفراً، ولا قيمة افتراضية. الملف
 * هذا لا يحمل كميات إطلاقاً (لا مركزي ولا صيدليات)؛ فقط بيانات وصفية عن المادة
 * نفسها. راجع MaterialMasterRowParser للقواعد الكاملة.
 */
final class MaterialMasterRow
{
    public function __construct(
        public readonly int $sourceRow,
        public readonly string $barcode,
        public readonly ?string $name,
        public readonly ?string $company,
        public readonly ?float $price,
        public readonly ?int $packSize,
        public readonly ?int $minReorderGap,
    ) {
        if ($barcode === '') {
            throw new InvalidArgumentException('barcode must not be empty — the parser must filter these out before constructing a row.');
        }

        if ($price !== null && $price < 0) {
            throw new InvalidArgumentException('price must not be negative — the parser must clean this before constructing a row.');
        }

        if ($packSize !== null && $packSize < 0) {
            throw new InvalidArgumentException('packSize must not be negative — the parser must clean this before constructing a row.');
        }

        if ($minReorderGap !== null && $minReorderGap < 0) {
            throw new InvalidArgumentException('minReorderGap must not be negative — the parser must clean this before constructing a row.');
        }
    }

    /** لا شيء بهذا الصف يستحق كتابة — الباركود وحده لا يكفي لفتحه أصلاً. */
    public function isEntirelyBlank(): bool
    {
        return $this->name === null
            && $this->company === null
            && $this->price === null
            && $this->packSize === null
            && $this->minReorderGap === null;
    }
}
