<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * تصنيف التحذيرات غير الحاجبة التي قد يُنتجها استيراد ملف — كل تحذير هو
 * "قرار اتخذه النظام نيابة عن المستخدم" ويجب أن يكون **مرئياً** له.
 *
 * 🎯 القاعدة الحاكمة (بقرارك): لا تحويل صامت للبيانات. أي تصحيح تلقائي
 * (سالب ← 0، كسر ← عدد صحيح…) يُعدّ ويُعرَض بنتيجة الرفع، ويُحفظ الصف الأصلي
 * كعيّنة ليتمكن المستخدم من مراجعته.
 *
 * نفس نمط ShortageSource/RestockingMode: Enum يُصنِّف فقط، لا يقرر سلوكاً.
 */
enum ImportWarning: string
{
    case NegativeQuantity = 'negative_quantity';
    case FractionalQuantity = 'fractional_quantity';
    case InvalidQuantity = 'invalid_quantity';
    case InvalidValue = 'invalid_value';
    case DuplicateBarcode = 'duplicate_barcode';
    case SkippedRow = 'skipped_row';
    case StrayCell = 'stray_cell';

    // 🆕 (أ.4) فحص تطابق الأكواد بين الملفات — أخطاء كانت صامتة (حسابات خاطئة بلا رسالة).
    case UnknownProductCode = 'unknown_product_code';
    case MissingFromLatestMatrix = 'missing_from_latest_matrix';
    case UnknownPharmacyCode = 'unknown_pharmacy_code';
    case ProductNotInDocument = 'product_not_in_document';

    public function label(): string
    {
        return match ($this) {
            self::UnknownProductCode => 'رمز مادة غير موجود بملف الأصناف (غير معروف للنظام)',
            self::MissingFromLatestMatrix => 'رمز موجود بملف الأصناف وغير موجود بآخر كشف مستودعات',
            self::UnknownPharmacyCode => 'اسم/رمز صيدلية غير معروف',
            self::ProductNotInDocument => 'مادة محضَّرة غير موجودة بالفاتورة الأصلية',
            self::NegativeQuantity   => 'كمية سالبة حُوِّلت إلى صفر',
            self::FractionalQuantity => 'كمية كسرية خُفِّضت لأقرب عدد صحيح',
            self::InvalidQuantity    => 'قيمة غير صالحة بخانة كمية (نص أو خارج المدى) عُدّت صفراً',
            self::InvalidValue       => 'قيمة غير صالحة بخانة رقمية — تُركت بلا تغيير',
            self::DuplicateBarcode   => 'رمز مادة مكرر (جُمعت كمياته)',
            self::SkippedRow         => 'صف متخطَّى',
            self::StrayCell          => 'بيانات تحت عمود بلا رأس (تم تجاهلها)',
        };
    }
}
