<?php
// app/Services/Inventory/SalesRemainderResolverService.php

namespace App\Services\Inventory;

use App\Models\SalesQuantityRemainder;
use Illuminate\Support\Collection;

/**
 * 🎯 المسؤولية الوحيدة: تحويل "طلب مبيعات كسري" (ناتج تقسيم عبوة —
 * ظرف من عبوة ظرفين = 0.5، من عبوة 3 ظروف = 0.33...) إلى "طلب صحيح جاهز
 * للشحن فعلياً"، عبر دمج الكسر الجديد بهذه الدفعة مع أي باقٍ متراكم
 * مخزَّن من دفعات سابقة لنفس الزوج (صيدلية+مادة)، دفعة تلو الأخرى، حتى
 * يصل المجموع لعدد صحيح.
 *
 * 🎓 لماذا خدمة منفصلة تماماً عن InvoiceGenerationService، لا مجرد سطر
 * إضافي بداخلها؟ لأن "متى نعتبر كسراً متراكماً جاهزاً للشحن" قرار عمل
 * مستقل تماماً عن "كيف نبني فاتورة" — نفس مبدأ SRP المُطبَّق بكل مكان
 * آخر بالمشروع (راجع الفصل 6 بالتقرير التعليمي، قسم SRP، ومثال
 * ShortageRecorderService بالتحديد — أقرب مثال مشابه: خدمة صغيرة تُستدعى
 * مرة واحدة من داخل حلقة التجميع، مسؤولة فقط عن قرار واحد محدَّد).
 *
 * 🧮 الخوارزمية (بالضبط كما حدَّدها صاحب المشروع):
 * لكل زوج (صيدلية، مادة) بهذه الدفعة:
 *   total     = الباقي_المخزَّن_من_قبل + الكسر_الجديد_بهذه_الدفعة
 *   shippable = floor(total)   ← هذا فقط ما يُشحَن فعلياً الآن
 *   new_remainder = total − shippable   ← يُخزَّن لدفعة لاحقة
 *
 * مثال: باقٍ مخزَّن = 0.5، مبيعات هذه الدفعة = 1.0 → total = 1.5 →
 * يُشحَن 1، ويُخزَّن باقٍ جديد = 0.5.
 *
 * ⚠️ لماذا يجب استدعاؤها **داخل** نفس معاملة قاعدة البيانات (Transaction)
 * التي تُنشئ الفواتير، لا قبلها؟ لأن تحديث الباقي المخزَّن "يستهلك" ذلك
 * الكسر منطقياً — لو فشل إنشاء الفواتير لاحقاً بنفس العملية وتراجعت
 * المعاملة (Rollback)، يجب أن يتراجع تحديث الباقي معه أيضاً بالضبط، وإلا
 * "يُفقَد" ذلك الكسر بلا أي شحن فعلي يقابله (نقص صامت لن يُكتشَف إلا
 * لاحقاً بفحص يدوي دقيق). استدعاؤها خارج المعاملة كان يخاطر بهذا التناقض
 * بالضبط.
 */
class SalesRemainderResolverService
{
    /**
     * 🆕 (أ.5) الرياضيات نفسها (تطبيع 4 خانات + floor للشحن + باقٍ مُرحَّل) انتقلت إلى
     * QuantityRounder المشتركة بلا تغيير حرف بالنتيجة — هذه الخدمة كانت مصدر القاعدة،
     * وأصبحت أول مستهلكيها. افتراضي المُنشئ يُبقي أي `new` يدوي قديم يعمل كما هو.
     */
    public function __construct(
        private readonly \App\Services\Shared\Rounding\QuantityRounder $rounder = new \App\Services\Shared\Rounding\QuantityRounder(),
    ) {}

    /**
     * @param Collection $rawAggregatedSales كل عنصر: (object) {pharmacy_id, product_id, raw_quantity}
     *        — ناتج SUM(quantity) الخام (كسري) لهذه الدفعة فقط، مُجمَّعاً حسب الزوج، قبل أي تقريب.
     * @return Collection كل عنصر: (object) {pharmacy_id, product_id, total_quantity} — الكمية
     *         الصحيحة الجاهزة للشحن فعلياً بعد دمج الباقي المخزَّن. **لا تحوي أزواجاً بقيمة صفر**
     *         (لم تصل بعد لعدد صحيح — لا شيء لفعله بشأنها هذه الدفعة، والباقي مُخزَّن لدفعة لاحقة).
     */
    public function resolve(Collection $rawAggregatedSales): Collection
    {
        if ($rawAggregatedSales->isEmpty()) {
            return collect();
        }

        $pharmacyIds = $rawAggregatedSales->pluck('pharmacy_id')->unique();
        $productIds  = $rawAggregatedSales->pluck('product_id')->unique();

        // ⚡ استعلام واحد يجلب فقط الأزواج ذات الصلة بهذه الدفعة، بدل
        // استعلام "هل يوجد باقٍ مخزَّن لهذا الزوج؟" منفصل لكل صف.
        $existingRemainders = SalesQuantityRemainder::whereIn('pharmacy_id', $pharmacyIds)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy(fn(SalesQuantityRemainder $r) => "{$r->pharmacy_id}_{$r->product_id}");

        $resolved = collect();
        $remainderUpserts = [];
        $now = now();

        foreach ($rawAggregatedSales as $row) {
            $pharmacyId = (int) $row->pharmacy_id;
            $productId  = (int) $row->product_id;
            $key = "{$pharmacyId}_{$productId}";

            $storedRemainder = (float) ($existingRemainders->get($key)->remainder_quantity ?? 0);

            // 🩹 round() هنا وبكل نقطة حسابية لاحقة — تفادياً لانجراف
            // دقة الفاصلة العائمة (Floating-Point Drift) الذي قد يتراكم
            // عبر مئات الدفعات المتتالية بمرور الوقت (مثال: 0.1 + 0.2 لا
            // يساوي 0.3 تماماً بحساب IEEE754 القياسي بلا تقريب صريح).
            ['whole' => $shippable, 'remainder' => $newRemainder] = $this->rounder->splitWhole(
                $this->rounder->normalize($storedRemainder + (float) $row->raw_quantity)
            );

            $remainderUpserts[] = [
                'pharmacy_id'         => $pharmacyId,
                'product_id'          => $productId,
                'remainder_quantity' => $newRemainder,
                'created_at'          => $now,
                'updated_at'          => $now,
            ];

            if ($shippable > 0) {
                $resolved->push((object) [
                    'pharmacy_id'     => $pharmacyId,
                    'product_id'      => $productId,
                    // 🩹 اسم الحقل total_quantity عمداً (لا quantity) —
                    // يطابق الاسم الذي يتوقّعه InvoiceGenerationService
                    // بحلقته الحالية بالفعل، فلا حاجة لتعديل تلك الحلقة
                    // إطلاقاً غير نقطة بناء $aggregatedSales نفسها.
                    'total_quantity' => $shippable,
                ]);
            }
        }

        foreach (array_chunk($remainderUpserts, 500) as $chunk) {
            SalesQuantityRemainder::upsert($chunk, ['pharmacy_id', 'product_id'], ['remainder_quantity', 'updated_at']);
        }

        return $resolved;
    }
}
