<?php

namespace App\Enums;

/**
 * 🎯 مصير بند واحد عند تنفيذ مستند مناقلة (عكسية أو بين صيدليتين).
 *
 * Superseded: فائض هذا البند التُزم به لمناقلة أخرى بنفس الدورة قبل تنفيذ هذا المستند
 * (الإلغاء المتبادل، ب.6/ب.7). يُستبعد البند كاملاً — لا تنفيذ جزئي لكميته.
 */
enum TransferItemExecutionStatus: string
{
    case Executed = 'executed';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Executed => 'نُفِّذ',
            self::Superseded => 'أُلغي تلقائياً — الفائض التُزم به لمناقلة أخرى',
        };
    }
}
