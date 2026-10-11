<?php

namespace App\Enums;

/**
 * 🎯 (ب.6) سير عمل المناقلة العكسية (صيدلية ← المستودع). كانت تُنفَّذ تلقائياً فور
 * رصد الفائض؛ أصبحت اقتراحاً ينتظر "تنفيذ" صريحاً — بنفس سير PharmacyTransferStatus
 * بالضبط، لأن النوعين يتنافسان على **نفس الفائض** (راجع SurplusEngine).
 */
enum ReverseTransferStatus: string
{
    case Suggested = 'suggested';
    case Executed = 'executed';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Suggested => 'مقترحة ⏳',
            self::Executed => 'نُفِّذت ✅',
            self::Dismissed => 'مرفوضة ❌',
        };
    }
}
