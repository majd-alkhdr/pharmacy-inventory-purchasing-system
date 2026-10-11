<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * 🎯 [أُعيد تصميمه بالكامل بطلب صريح] "كشف تجهيز الطلبيات" — **ورقة منفصلة
 * لكل صيدلية**، بعمودين فقط (اسم المادة، الكمية)، بلا أي تفصيل آخر إطلاقاً
 * (لا رمز مادة، لا شركة، لا تفصيل مصدر) — بديل مباشر عن الكشف الورقي الذي
 * كان يُكتَب أو يُطبَع يدوياً، بنفس البساطة لكن منسَّقاً بالاكسل.
 *
 * 🎓 لماذا ورقة منفصلة بدل جدول واحد مُسطَّح بعمود "اسم الصيدلية" (كما كانت
 * النسخة السابقة)؟ لأن الاستخدام الفعلي مختلف جوهرياً: هذا الكشف يُطبَع
 * نسخة واحدة **لكل مُجهِّز** يأخذها بالضبط لصيدليته المحدَّدة — لا أحد
 * يُجهِّز بالنظر لجدول ضخم يحوي كل الصيدليات معاً فيُصفِّيه يدوياً أولاً.
 * ورقة Excel منفصلة لكل صيدلية تعني: فتح الملف، اختيار تبويب الصيدلية،
 * طباعة تلك الورقة فقط.
 *
 * 🩹 **بديل كامل** عن PreparationExport القديمة (تفصيل المصدر من المبيعات/
 * المخزون القياسي/التتميم) — ذلك التفصيل كان مفيداً للتدقيق المحاسبي، لكن
 * هذا الكشف غرضه تشغيلي بحت (ماذا أُحضِّر الآن؟)، فلا حاجة له هنا إطلاقاً.
 * التدقيق المحاسبي يبقى متاحاً عبر "تصدير Excel" (AllInvoicesExport) —
 * المنفصل تماماً، بلا أي تغيير عليه.
 *
 * 🩹 [إصلاح جوهري] **فلاتر الشاشة (حالة التوفر، تصنيف الفاتورة) كانت
 * تُهمَل كلياً** — زر "كشف تجهيز" بالواجهة كان يُمرِّر `date` و`pharmacy_id`
 * فقط، بعكس زر "تصدير Excel" المجاور الذي يُمرِّر نفس الفلاتر الأربعة
 * الظاهرة فعلياً على الشاشة. النتيجة: فلترة المستخدم (مثلاً "العجز فقط" أو
 * "ترميم من مبيعات سابقة") كانت تُعرَض بالجدول على الشاشة، لكن الكشف
 * المُصدَّر يتجاهلها ويُصدِّر كل شيء دائماً. الآن `status` و`availability`
 * يُطبَّقان بنفس الدلالة **حرفياً** المُستخدَمة بـ AllInvoicesExport
 * (InvoiceItem::scopeAvailability/scopeAvailable/scopeShortage) — صف واحد
 * يُستبعَد من الحساب قبل التجميع، لا الناتج المُجمَّع بعد حسابه.
 *
 * 📐 بُنية المُنشِئ الأصلية (التاريخ، معرِّف صيدلية اختياري) **بقيت كما
 * هي** بأول معاملين — الكنترولر لا يحتاج تعديلاً على طريقة استدعائه
 * الحالية؛ المعاملان الجديدان اختياريان بنهاية القائمة فقط.
 */
class PreparationExport implements WithMultipleSheets
{
    public function __construct(
        private readonly string $date,
        private readonly ?int $pharmacyId = null,
        private readonly ?string $status = null, // 🆕 processed | supplementary_invoice
        private readonly ?string $availability = null, // 🆕 available | shortage
    ) {
    }

    /**
     * @return list<PreparationPharmacySheetExport>
     */
    public function sheets(): array
    {
        $pharmacies = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('pharmacies', 'pharmacies.id', '=', 'invoices.pharmacy_id')
            ->whereDate('invoices.date', $this->date)
            ->when($this->pharmacyId, fn ($q) => $q->where('invoices.pharmacy_id', $this->pharmacyId))
            ->when($this->status, fn ($q) => $q->where('invoices.status', $this->status)) // 🆕
            ->when($this->availability === 'shortage', fn ($q) => $q->where('invoice_items.shortage_qty', '>', 0)) // 🆕
            // 🎓 supplied_qty > 0 يبقى شرطاً غير مشروط دائماً — لا شيء بعتبة "متاح"
            // فقط عند availability='available' (ذاك كان سيعني "فائدته صفر" عملياً
            // هنا تحديداً: نقص صِرف (supplied_qty=0) لا يوجد منه شيء مادي
            // لتجهيزه أصلاً، بصرف النظر عن أي فلتر. دمجه مع availability='shortage'
            // أعلاه يُصفِّي تلقائياً لِما يملك توفراً جزئياً فقط (المعنى الصحيح
            // الوحيد لـ"كشف تجهيز لعناصر بها نقص" — نقص صافٍ ليس له ما يُجهَّز).
            ->where('invoice_items.supplied_qty', '>', 0)
            ->select('pharmacies.id', 'pharmacies.name')
            ->distinct()
            ->orderBy('pharmacies.name')
            ->get();

        // 🩹 اسم تبويب الورقة محدود بـ31 حرفاً بإكسل — لو تقاطع اسما صيدليتين
        // بعد الاقتطاع (نادر لكن ممكن)، Excel يرفض تبويبين بنفس الاسم حرفياً.
        // نضمن التفرّد هنا (حيث كل الصيدليات مرئية معاً دفعة واحدة)، لا داخل
        // كل ورقة على حدة حيث لا رؤية لبقية الصيدليات إطلاقاً.
        $usedTitles = [];
        $sheets = [];

        foreach ($pharmacies as $pharmacy) {
            $title = $this->uniqueSheetTitle($pharmacy->name, $usedTitles);
            $usedTitles[$title] = true;

            $sheets[] = new PreparationPharmacySheetExport(
                date: $this->date,
                pharmacyId: $pharmacy->id,
                pharmacyName: $pharmacy->name,
                sheetTitle: $title,
                status: $this->status, // 🆕
                availability: $this->availability, // 🆕
            );
        }

        return $sheets;
    }

    /** @param array<string, true> $usedTitles */
    private function uniqueSheetTitle(string $pharmacyName, array $usedTitles): string
    {
        $clean = str_replace([':', '\\', '/', '?', '*', '[', ']'], '', $pharmacyName);
        $base = mb_substr($clean, 0, 31);

        if (!isset($usedTitles[$base])) {
            return $base;
        }

        for ($suffix = 2; $suffix < 100; $suffix++) {
            $candidate = mb_substr($base, 0, 31 - mb_strlen((string) $suffix) - 1) . '-' . $suffix;
            if (!isset($usedTitles[$candidate])) {
                return $candidate;
            }
        }

        return mb_substr($base, 0, 27) . '-' . substr(uniqid(), -3); // احتياط أخير بعيد الاحتمال
    }
}
