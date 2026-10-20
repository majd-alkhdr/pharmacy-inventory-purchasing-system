<?php

declare(strict_types=1);

namespace App\Services\Inventory\Reconciliation;

/** 🎯 (ب.3) نتيجة مطابقة اسم واحد من ملف التحضير — يصنّف فقط، والخدمة تقرر ما تفعله بكل نوع. */
enum NameMatchKind
{
    /** مطابقة تامة (بالمفتاح المحافظ) لمادة واحدة بالفاتورة. */
    case Exact;
    /** مطابقة لمادة واحدة بالفاتورة بعد تطبيع عربي (ة/ه، همزات، فواصل) فقط. */
    case Approximate;
    /** مادة واحدة بالكتالوج بنفس الاسم، لكنها ليست بالفاتورة. */
    case NotInDocument;
    /** أكثر من مادة مرشحة — لا تخمين. */
    case Ambiguous;
    /** لا مادة بهذا الاسم. */
    case Unknown;
}
