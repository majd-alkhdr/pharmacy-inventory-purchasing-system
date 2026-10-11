<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 🎯 حقول "البيانات الأساسية للمادة" القابلة للتصحيح اليدوي بشاشة التحقق — ولكل حقل ملفه المصدر
 * الوحيد. التصحيح اليدوي **ليس مصدراً موازياً**: أول رفع لاحق لملف الحقل يحمل قيمة له يكتب فوقه
 * كالمعتاد (لا علم "تجاوز يدوي"، لا تخطٍّ عند الرفع).
 */
enum ProductMasterField: string
{
    case Name = 'name';
    case Company = 'company';
    case Price = 'price';
    case PackSize = 'pack_size';
    case MinReorderGap = 'min_reorder_gap';
    case CentralQuantity = 'central_quantity';

    public function label(): string
    {
        return match ($this) {
            self::Name => 'اسم المادة',
            self::Company => 'الشركة',
            self::Price => 'سعر المادة',
            self::PackSize => 'حجم العبوة',
            self::MinReorderGap => 'الحد الأدنى للفرق',
            self::CentralQuantity => 'رصيد المستودع المركزي',
        };
    }

    /**
     * رصيد المركزي **للعرض فقط**: هو الرصيد الفعلي الذي تُولَّد منه فواتير المناقلات، فتعديله يدوياً
     * يغيّر ما يُشحَن فوراً — لا "تصحيح بصري". مصدره الوحيد ملف المصفوفة.
     */
    public function isEditable(): bool
    {
        return $this !== self::CentralQuantity;
    }

    /** @return list<self> */
    public static function editable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $field): bool => $field->isEditable()));
    }

    /** الملف الذي يكتب فوق هذا الحقل عند رفعه التالي. */
    public function sourceFile(): ImportFileType
    {
        return $this === self::CentralQuantity ? ImportFileType::WarehouseMatrix : ImportFileType::MaterialMaster;
    }

    /**
     * رصيد المركزي يُستبدَل **بالكامل** مع كل رفع للكشف (أو ملف الجرد المركزي القديم) لكل المواد معاً —
     * فمصدره "آخر رفع ناجح لأحد هذين الملفين" بلا حاجة لسجل لكل مادة. بقية الحقول تُكتب لكل مادة
     * على حدة وفقط حين تحمل الخلية قيمة، فتحتاج سجلاً لكل (مادة، حقل).
     *
     * @return list<ImportFileType>
     */
    public function wholesaleReplacementFiles(): array
    {
        return $this === self::CentralQuantity ? [ImportFileType::WarehouseMatrix, ImportFileType::CentralInventory] : [];
    }
}
