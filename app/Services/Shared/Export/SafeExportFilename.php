<?php

declare(strict_types=1);

namespace App\Services\Shared\Export;

/**
 * 🎯 تنظيف اسم ملف التصدير قبل تمريره لـ Excel::download().
 *
 * 🎓 لماذا؟ أسماء الصيدليات والموردين حقول نصية حرة بلا قيد بقاعدة البيانات، وقد تحتوي
 * "/" أو "\". Symfony يرفض أي اسم ملف كهذا برأس Content-Disposition ويرمي
 * InvalidArgumentException ← خطأ 500 بدل تنزيل الملف. نستبدل هذه المحارف (ومحارف
 * التحكم) بشرطة "-" مركزياً بدل تكرار الإصلاح بكل نقطة تصدير.
 *
 * كلاس نقي بلا حالة — آمن للاستدعاء من أي مكان.
 */
final class SafeExportFilename
{
    public static function sanitize(string $fileName): string
    {
        return preg_replace('#[\\\\/\x00-\x1F\x7F]+#u', '-', $fileName);
    }
}
