<?php

namespace App\Enums;

/**
 * 🎯 سير عمل مناقلة مباشرة بين صيدليتين — راجع تعليق مايجريشن
 * create_pharmacy_transfers_table للسبب الكامل وراء وجود سير عمل هنا
 * إطلاقاً (بخلاف المناقلات العكسية، التي تُنفَّذ فوراً بلا مراجعة).
 */
enum PharmacyTransferStatus: string
{
    case Suggested = 'suggested';
    case Completed = 'completed';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Suggested => 'اقتراح بانتظار المراجعة ⏳',
            self::Completed => 'نُفِّذت ✅',
            self::Dismissed => 'مرفوضة ❌',
        };
    }
}
