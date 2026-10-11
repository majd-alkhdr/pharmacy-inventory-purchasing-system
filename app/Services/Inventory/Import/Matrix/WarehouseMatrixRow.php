<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\Matrix;

use InvalidArgumentException;
use OutOfBoundsException;

/**
 * 🎯 صف واحد **مُنظَّف** من ملف المصفوفة — ما يتبقى بعد تطبيق قواعد الملف
 * (الفراغ = 0، السالب = 0، الكسر يُخفَّض) وقبل أي لمس لقاعدة البيانات.
 * هو "لغة التخاطب" بين القارئ/المُحلِّل (الطبقة 2) والمستهلكين (المركزي،
 * لقطة الصيدليات) — لا يعرف أياً منهم.
 *
 * 🎓 لماذا DTO بثوابت (Invariants) تُفرَض بالمُنشئ وليس مصفوفة؟
 * قواعدك ("لا كمية سالبة بعد الآن") تُطبَّق مرة واحدة بالمُحلِّل. لو بقي الصف
 * مصفوفة، يستطيع أي كود لاحق أن يمرّر سالباً أو رمزاً فارغاً دون أن ينتبه أحد.
 * هنا الحالة الفاسدة **غير قابلة للتمثيل**: من يحاول بناءها يحصل على استثناء
 * فوراً عند مصدر الخطأ، لا بعد ثلاث خدمات وقاعدة بيانات.
 *
 * 📐 دلالة pharmacyQuantities: تحوي مدخلاً لـ**كل** عمود صيدلية بالملف — الفراغ
 * صُفِّر بالمُحلِّل، فالمستهلك لا يحتاج معرفة "هل الغياب صفر أم مجهول؟". (داخل
 * المصفوفة الغياب دائماً صفر؛ المجهول هو المادة غير الموجودة بالملف أصلاً.)
 * التكلفة: ~10 أعداد لكل صف وصف واحد بالذاكرة بكل لحظة (تدفّق) — لا شيء.
 */
final class WarehouseMatrixRow
{
    /**
     * @param int                $sourceRow          رقم الصف بالملف الأصلي (للتقارير والتحذيرات)
     * @param array<int, int>    $pharmacyQuantities فهرس عمود الصيدلية ← كميتها (≥ 0)
     */
    public function __construct(
        public readonly int $sourceRow,
        public readonly string $barcode,
        public readonly string $name,
        public readonly string $company,
        public readonly int $centralQuantity,
        public readonly array $pharmacyQuantities,
    ) {
        if ($barcode === '') {
            throw new InvalidArgumentException("الصف {$sourceRow}: رمز المادة فارغ — يجب أن يتخطاه المُحلِّل قبل بناء الصف.");
        }

        if ($centralQuantity < 0) {
            throw new InvalidArgumentException("الصف {$sourceRow}: كمية مركزية سالبة ({$centralQuantity}) — يجب تصفيرها قبل بناء الصف.");
        }

        foreach ($pharmacyQuantities as $column => $quantity) {
            if (!is_int($quantity) || $quantity < 0) {
                throw new InvalidArgumentException("الصف {$sourceRow}: كمية غير صالحة بالعمود {$column} — يجب أن تكون عدداً صحيحاً ≥ 0.");
            }
        }
    }

    /**
     * كمية موقع بعينه (المركزي أو عمود صيدلية).
     *
     * @throws OutOfBoundsException لعمود لا وجود له — خطأ برمجي يجب أن يفشل بصوت عالٍ،
     *                              لا أن يُعاد 0 فيبدو كأنه "فراغ مقصود".
     */
    public function quantityAt(int $columnIndex): int
    {
        if ($columnIndex === MatrixColumnMap::CENTRAL) {
            return $this->centralQuantity;
        }

        return $this->pharmacyQuantities[$columnIndex]
            ?? throw new OutOfBoundsException("الصف {$this->sourceRow}: لا يوجد عمود كميات بالفهرس {$columnIndex}.");
    }

    /** هل للمادة أي رصيد موجب بأي موقع؟ (57% من مواد كشفك الحالي: لا). */
    public function hasAnyStock(): bool
    {
        return $this->centralQuantity > 0 || array_sum($this->pharmacyQuantities) > 0;
    }
}
