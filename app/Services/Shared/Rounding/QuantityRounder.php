<?php

declare(strict_types=1);

namespace App\Services\Shared\Rounding;

/**
 * 🎯 (أ.5) قاعدة التدوير الموحَّدة: **دوِّر مرة واحدة فقط، عند الحافة الأخيرة**.
 *
 * 🎓 من أين جاءت القاعدة؟ استُخرجت من SalesRemainderResolverService (مخزِّن
 * SalesQuantityRemainder) — المكان الوحيد الذي طبّقها سابقاً: الكسور تُحمل بدقة كاملة
 * عبر الدفعات (مع تطبيع 4 خانات ضد انجراف الفاصلة العائمة)، ولا تُحوَّل لعدد صحيح إلا
 * لحظة "الشحن". تكرار التقريب بكل خطوة وسيطة يراكم الخطأ: 2.4 → 2 ثم +2.4 → 4، بينما
 * الصحيح 4.8 → 5. مع أزواج "مقترح/معتمد" الجديدة (ب.2، ج.2) صار هناك أكثر من خطوة
 * تمر بها نفس الكمية — فاحتجنا القاعدة كخدمة مشتركة لا كسطرين مخفيين بخدمة واحدة.
 *
 * ⚖️ لماذا PRECISION = 4؟ نفس دقة عمود sales_quantity_remainders.remainder_quantity
 * (decimal 4) — دقة واحدة للنظام كله، فلا يُخزَّن رقم بدقة ويُقرأ بأخرى.
 *
 * كلاس نقي بلا حالة ولا قاعدة بيانات — قابل للاختبار وحده وآمن للحقن بأي مكان.
 */
final class QuantityRounder
{
    public const PRECISION = 4;

    /**
     * تطبيع **وسيط** (ليس تدويراً لعدد صحيح): يزيل ذيول IEEE754 (0.1+0.2 = 0.30000000000000004)
     * مع إبقاء الكسر الحقيقي. استخدمه بين الخطوات؛ لا تستخدمه كنتيجة نهائية لكمية تُشحن.
     */
    public function normalize(int|float|string $value): float
    {
        return round((float) $value, self::PRECISION);
    }

    /**
     * التحويل **النهائي** لكمية صحيحة (Half-Up): يُستدعى مرة واحدة على القيمة الخام
     * الكاملة عند الحافة الأخيرة (حفظ كمية معتمدة، حفظ كمية فاتورة معدَّلة).
     * التطبيع قبل التدوير ضروري: 2.4999999999 (ناتج حساب) يجب أن يُعامَل 2.5 → 3.
     */
    public function toWholeUnits(int|float|string|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return max(0, (int) round($this->normalize($value), 0, PHP_ROUND_HALF_UP));
    }

    /**
     * يقسم كمية كسرية إلى "ما يُشحن الآن" (أرضية) و"باقٍ يُرحَّل" — بالضبط خوارزمية
     * SalesRemainderResolverService: الباقي لا يُفقَد ولا يُقرَّب، يُحمل للدفعة التالية.
     *
     * @return array{whole: int, remainder: float}
     */
    public function splitWhole(int|float|string $value): array
    {
        $total = $this->normalize($value);
        $whole = (int) floor($total);

        return ['whole' => $whole, 'remainder' => $this->normalize($total - $whole)];
    }
}
