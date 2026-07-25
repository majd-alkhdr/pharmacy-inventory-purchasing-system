<?php

namespace App\Enums;

/**
 * 🎯 بنفس فلسفة ShortageStatus وInvoiceStatus بالضبط (راجع تقرير [012]
 * بالمرحلة الأولى): بديل "Magic Strings" لحالات فاتورة الشراء، مع
 * casts() على موديل PurchaseOrder لضمان أن أي مقارنة تمر عبر PHP نفسه
 * (TypeError عند أي قيمة خاطئة) بدل تخزينها بصمت بقاعدة البيانات.
 *
 * ⚠️ درس مُستفاد من تقريرَي [017] و[020] بالمرحلة الأولى، مطبَّق هنا
 * منذ البداية: بما أننا نضيف casts() لهذا العمود من أول يوم (وليس
 * إضافة لاحقة على عمود قديم)، **لا يوجد خطر أثر رجعي هنا** — كل كود
 * جديد سيُكتب من الأساس وهو يعرف أن status كائن Enum، لا نص خام.
 */
enum PurchaseOrderStatus: string
{
    case Draft            = 'draft';
    case Approved         = 'approved';
    case Sent              = 'sent';
    case PartiallyReceived = 'partially_received';
    case Received          = 'received';
    case Cancelled         = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft             => 'مسودة 📝',
            self::Approved          => 'معتمدة ✅',
            self::Sent               => 'مُرسَلة للمورد 📤',
            self::PartiallyReceived  => 'استلام جزئي ⚠️',
            self::Received           => 'مستلمة بالكامل 📦',
            self::Cancelled          => 'ملغاة ❌',
        };
    }

    /** هل ما زالت هذه الفاتورة قابلة للتعديل من المشرف قبل الإرسال؟ */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
