<?php

namespace App\Enums;

/**
 * 🆕 إضافة PartiallyCancelled: قرار المستخدم صراحة — فاتورة استُلمت
 * جزئياً ثم أُلغي المتبقي منها **ليست نفس حالة** فاتورة لم يصلها شيء
 * إطلاقاً (Cancelled). التمييز مهم لأن الكمية المتبقية غير المستلَمة
 * بهذا السيناريو تحديداً هي مرشَّح حقيقي لمنطق "قنص نواقص السوق"
 * (المادة طُلبت فعلاً ولم يستطع المورد توفيرها بالكامل)، بينما فاتورة
 * أُلغيت بالكامل قبل أي استلام لا تحمل نفس الدلالة.
 */
enum PurchaseOrderStatus: string
{
    case Draft              = 'draft';
    case Approved           = 'approved';
    case Sent                = 'sent';
    case PartiallyReceived   = 'partially_received';
    case Received            = 'received';
    case Cancelled           = 'cancelled';
    case PartiallyCancelled  = 'partially_cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft             => 'مسودة 📝',
            self::Approved          => 'معتمدة ✅',
            self::Sent               => 'مُرسَلة للمورد 📤',
            self::PartiallyReceived  => 'استلام جزئي ⚠️',
            self::Received           => 'مستلمة بالكامل 📦',
            self::Cancelled          => 'ملغاة بالكامل ❌',
            self::PartiallyCancelled => 'استلام جزئي — أُلغي الباقي 🚫',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * 🎯 الإلغاء الكامل مسموح فقط قبل أي استلام فعلي (Draft/Approved/Sent).
     * بعد بدء الاستلام الفعلي (PartiallyReceived)، الخيار المتاح هو
     * "إلغاء المتبقي" فقط (partiallyCancel)، وليس إلغاء الفاتورة كاملة
     * — لأن جزءاً منها وصل فعلياً وأصبح رصيداً حقيقياً بالمخزون.
     */
    public function canBeFullyCancelled(): bool
    {
        return in_array($this, [self::Draft, self::Approved, self::Sent], true);
    }

    /** الإلغاء الجزئي متاح فقط لفاتورة بها استلام جزئي فعلي وليس بعد */
    public function canCancelRemaining(): bool
    {
        return $this === self::PartiallyReceived;
    }

    /** هل هذه الفاتورة "منتهية" ولا داعي لعرضها ضمن أي إجراء مستقبلي (اعتماد/إرسال/إلغاء)؟ */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Received, self::Cancelled, self::PartiallyCancelled], true);
    }
}
