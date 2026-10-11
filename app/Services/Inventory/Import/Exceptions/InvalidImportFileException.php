<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\Exceptions;

use RuntimeException;

/**
 * 🎯 خطأ "الملف نفسه غير صالح" — يُميَّز عمداً عن أخطاء النظام (اتصال، قاعدة
 * بيانات) لأن رسالته **موجَّهة للمستخدم** ومكتوبة بلغته: تُعرض كما هي بحقل
 * message لجلسة الاستيراد، وتقول له ماذا يُصلح بملفه، لا ماذا حدث داخل الكود.
 *
 * 🎓 لماذا Named Constructors بدل رسائل مبعثرة بمواقع الرمي؟ رسائل الخطأ
 * للمستخدم جزء من "واجهة" النظام؛ تجميعها هنا يجعلها متسقة اللهجة،
 * قابلة للمراجعة بمكان واحد، وقابلة للاختبار بالاسم بدل مقارنة نص حرفي.
 */
final class InvalidImportFileException extends RuntimeException
{
    public static function emptyFile(): self
    {
        return new self('الملف فارغ أو لا يحوي صف رؤوس الأعمدة (الصف الأول).');
    }

    public static function unreadable(string $reason): self
    {
        return new self("تعذّرت قراءة الملف: {$reason}");
    }

    public static function headerMismatch(string $columnLetter, string $expected, string $actual): self
    {
        $found = $actual === '' ? '(فارغ)' : "«{$actual}»";

        return new self(
            "صيغة الملف غير مطابقة: العمود {$columnLetter} يجب أن يكون «{$expected}» لكن وُجد {$found}. "
            . 'الترتيب المطلوب: رمز المادة، الاسم، الشركة، مستودع الصناعة، ثم الصيدليات.'
        );
    }

    /** نفس رسالة headerMismatch() بالضبط لكن بترتيب أعمدة ملف "بيانات المواد" (لا كشف المستودعات). */
    public static function materialMasterHeaderMismatch(string $columnLetter, string $expected, string $actual): self
    {
        $found = $actual === '' ? '(فارغ)' : "«{$actual}»";

        return new self(
            "صيغة الملف غير مطابقة: العمود {$columnLetter} يجب أن يكون «{$expected}» لكن وُجد {$found}. "
            . 'الترتيب المطلوب: رمز المادة، اسم المادة، الشركة، السعر، حجم العبوة، الحد الأدنى للفرق.'
        );
    }

    /** 🆕 (ب.3) نفس الرسالة بترتيب أعمدة ملف "تحضير المناقلة". */
    public static function preparationHeaderMismatch(string $columnLetter, string $expected, string $actual): self
    {
        $found = $actual === '' ? '(فارغ)' : "«{$actual}»";

        return new self(
            "صيغة ملف التحضير غير مطابقة: العمود {$columnLetter} يجب أن يكون «{$expected}» لكن وُجد {$found}. "
            . 'الترتيب المطلوب: رمز المادة، اسم المادة، الكمية المحضّرة — نزّل القالب من شاشة المطابقة.'
        );
    }

    public static function blankHeaderGap(string $columnLetter): self
    {
        return new self(
            "العمود {$columnLetter} بلا رأس (اسم صيدلية) لكن بعده أعمدة لها رؤوس. "
            . 'احذف العمود الفارغ أو اكتب له اسم الصيدلية.'
        );
    }

    public static function duplicateLocationHeader(string $header, string $firstLetter, string $secondLetter): self
    {
        return new self(
            "الصيدلية «{$header}» مكررة بالعمودين {$firstLetter} و{$secondLetter} — "
            . 'لا يمكن تحديد أيهما الصحيح، احذف أحدهما.'
        );
    }

    /** @param list<string> $columnLetters */
    public static function conflictingPharmacyColumns(string $pharmacyName, array $columnLetters): self
    {
        $letters = implode(' و', $columnLetters);

        return new self(
            "الأعمدة {$letters} تشير كلها إلى الصيدلية «{$pharmacyName}» — "
            . 'لا يمكن جمعها ولا اختيار أحدها. احذف المكرر أو صحّح أسماء الرؤوس.'
        );
    }
}
