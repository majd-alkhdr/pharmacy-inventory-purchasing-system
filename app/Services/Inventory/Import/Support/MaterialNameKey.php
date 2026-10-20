<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\Support;

/**
 * مفتاح مطابقة محافظ لأسماء المواد: يزيل فقط ما لا يغيّر المعنى (محارف غير مرئية، مسافات زائدة،
 * تشكيل/تطويل، أرقام هندية ← لاتينية، حالة الحروف اللاتينية). لا يوحّد أي حرفين عربيين مختلفين
 * ولا الفواصل — لأن ذلك قد يدمج مادتين مختلفتين فعلاً (راجع تحذير ArabicTextNormalizer).
 */
final class MaterialNameKey
{
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public static function key(string $name): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }

        // محارف غير مرئية (RTL/LTR، zero-width، BOM).
        $name = (string) preg_replace('/[\x{00AD}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $name);
        // التشكيل والتطويل.
        $name = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $name);
        // كل أنواع المسافات ← مسافة واحدة.
        $name = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $name));

        return mb_strtolower(strtr($name, self::DIGITS), 'UTF-8');
    }
}
