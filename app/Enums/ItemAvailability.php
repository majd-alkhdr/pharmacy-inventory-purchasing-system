<?php

namespace App\Enums;

/**
 * 🎯 هذا الـ Enum يحل مشكلة تكرار حقيقية وموجودة فعلياً بمشروعك:
 * نفس منطق match(true) بالضبط (isFullShipment / isShortage) كان مكرراً حرفياً
 * بثلاث ملفات مختلفة: InvoiceExport::map()، AllInvoicesExport::map()،
 * وضمنياً بمنطق invoice-details.blade.php.
 * لو غداً حبيت تضيف حالة رابعة (مثلاً "شحن زائد")، بالكود القديم لازم تعدّل
 * بثلاث أماكن وتنسى مكان رابع بسهولة. هون تعدّل مكان واحد فقط.
 */
enum ItemAvailability: string
{
    case FullShipment = 'full';
    case Partial       = 'partial';
    case Shortage       = 'shortage';

    public static function fromQuantities(int $suppliedQty, int $requestedQty, int $shortageQty): self
    {
        return match (true) {
            $shortageQty === 0 && $suppliedQty >= $requestedQty => self::FullShipment,
            $suppliedQty > 0                                    => self::Partial,
            default                                              => self::Shortage,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::FullShipment => 'شحن كامل ✅',
            self::Partial       => 'شحن جزئي ⚠️',
            self::Shortage       => 'عجز ❌',
        };
    }

    /** الكمية الظاهرة بجدول التصدير: المشحونة إن وُجدت، وإلا كمية العجز */
    public static function displayQty(int $suppliedQty, int $shortageQty): int
    {
        return $suppliedQty > 0 ? $suppliedQty : $shortageQty;
    }
}
