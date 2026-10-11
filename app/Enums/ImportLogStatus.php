<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 🎯 حالة سطر واحد بسجل الرفع الموحَّد.
 *
 * ⚖️ لماذا "نجاح مع تحذيرات" حالة مستقلة لا مجرد عدّاد؟ لأن سؤال المستخدم
 * الأكثر تكراراً ("هل نجح رفع المخزون القياسي؟") جوابه ليس نعم/لا: الملف كُتب
 * فعلاً، لكن النظام اتخذ قرارات نيابة عنه (سالب←صفر، رمز مجهول...). فصلها يسمح
 * بفلترة "كل ما يحتاج نظرة" بنقرة واحدة دون فتح كل سجل.
 *
 * Processing وAwaitingAction حالتان **غير نهائيتين** — الأولى أثناء المعالجة
 * الخلفية، والثانية عند توقف الجلسة بانتظار قرار بشري (مطابقة أسماء، تأكيد مواد).
 */
enum ImportLogStatus: string
{
    case Processing = 'processing';
    case AwaitingAction = 'awaiting_action';
    case Succeeded = 'succeeded';
    case SucceededWithWarnings = 'succeeded_with_warnings';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Processing => 'قيد المعالجة ⏳',
            self::AwaitingAction => 'بانتظار إجراء منك ✋',
            self::Succeeded => 'نجح ✅',
            self::SucceededWithWarnings => 'نجح مع تحذيرات ⚠️',
            self::Failed => 'فشل ❌',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::SucceededWithWarnings, self::Failed], true);
    }

    public function isSuccessful(): bool
    {
        return $this === self::Succeeded || $this === self::SucceededWithWarnings;
    }

    /** @return list<string> */
    public static function successfulValues(): array
    {
        return [self::Succeeded->value, self::SucceededWithWarnings->value];
    }

    /**
     * يترجم حالة ImportSession القديمة (نص حر بجدول import_sessions) لحالة سجل.
     * نقطة الترجمة الوحيدة — RecordsImportSession يستدعيها فيتزامن السجلان تلقائياً.
     */
    public static function fromSessionStatus(string $sessionStatus): self
    {
        return match ($sessionStatus) {
            'completed' => self::Succeeded,
            'failed' => self::Failed,
            'needs_mapping', 'needs_pending_products', 'needs_exclusion_choice' => self::AwaitingAction,
            default => self::Processing,
        };
    }
}
