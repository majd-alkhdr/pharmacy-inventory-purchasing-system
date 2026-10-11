<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\Matrix;

use Generator;

/**
 * 🎯 نتيجة "فتح" ملف المصفوفة: **خريطة الأعمدة جاهزة ومُتحقَّق منها فوراً**،
 * والصفوف تُقرأ لاحقاً على التدفق.
 *
 * 🎓 لماذا لا تُعاد الصفوف مباشرة من المُحلِّل؟ المستهلك يحتاج معرفة أعمدة
 * الصيدليات **قبل** أن يقرأ صفاً واحداً (ليطابقها بالصيدليات المسجَّلة ويتوقف
 * عند المجهول). لو أعاد المُحلِّل Generator فقط، لتأخّر اكتشاف خطأ رؤوس الملف
 * حتى أول تكرار. هنا: فشل الرؤوس يحدث بسطر parse() نفسه.
 *
 * ⚠️ تدفق **أحادي المرور**: rows() يُستهلَك مرة واحدة. للمرور الثاني افتح الملف
 * من جديد (القراءة رخيصة الآن، وهذا بالضبط ما يجعل "أكِّد ثم أعِد الاستيراد"
 * تصميماً عملياً لمواد جديدة).
 */
final class ParsedMatrix
{
    /**
     * @param Generator<int, WarehouseMatrixRow> $rows
     */
    public function __construct(
        public readonly MatrixColumnMap $columns,
        private readonly Generator $rows,
    ) {
    }

    /**
     * صفوف نظيفة، بأرقام صفوفها الأصلية. الباركود المكرر يُعاد كصفوف منفصلة —
     * **على المستهلك جمع كمياتها** (بالمفتاح: الباركود)، والمُحلِّل يسجّل التكرار
     * تحذيراً فقط لأن الدمج على التدفق يتطلب حجز كل الصفوف بالذاكرة.
     *
     * @return Generator<int, WarehouseMatrixRow>
     */
    public function rows(): Generator
    {
        return $this->rows;
    }
}
