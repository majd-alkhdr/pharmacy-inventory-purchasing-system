<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\Support;

/**
 * 🎯 يفسّر خلية ملف (رقماً أصلياً بـ xlsx، أو نصاً بأي شكل يكتبه مستخدم عربي)
 * كرقم — أو null إن تعذّر ذلك كلياً. **منطق مستخرَج**: كان بالأصل خاصاً بـ
 * WarehouseMatrixRowParser وحده، ثم احتاجه MaterialMasterRowParser بالضبط
 * بنفس القواعد (أرقام هندية، فاصلة عشرية عربية، فاصلة آلاف لاتينية) — استخراجه
 * هنا يمنع انحراف القاعدتين عن بعضهما لاحقاً عند تعديل أيٍّ منهما لوحده.
 *
 * لا يُقرِّب ولا يرفض السالب ولا يتحقق من مدى — تلك قرارات **دلالية** يختلف
 * سلوكها المطلوب بين ملف وآخر (المصفوفة تُصفِّر السالب وتُخفِّض الكسر؛ ملف
 * بيانات المواد يترك القيمة المريبة بلا لمس وينبّه). كل مُستدعٍ يطبّق قواعده
 * الخاصة على الرقم العائم الذي يُعيده هذا الكلاس.
 */
final class ArabicNumberParser
{
    public static function toFloat(string|int|float|bool $raw): ?float
    {
        if (is_bool($raw)) {
            return null;
        }

        if (is_int($raw)) {
            return (float) $raw;
        }

        if (is_float($raw)) {
            return is_finite($raw) ? $raw : null;
        }

        $text = strtr(trim($raw), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', '٬' => '', "\u{00A0}" => '', ' ' => '',
        ]);

        // "1,5" فاصلة عشرية (لا نقطة معها). "1,234.5" فاصلة آلاف.
        if (str_contains($text, ',')) {
            $text = str_contains($text, '.') ? str_replace(',', '', $text) : str_replace(',', '.', $text);
        }

        return is_numeric($text) ? (float) $text : null;
    }
}
