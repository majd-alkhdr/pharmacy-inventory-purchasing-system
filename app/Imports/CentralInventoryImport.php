<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\CentralInventory;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Collection;

/**
 * 🆕 (2026-08) عمود خامس اختياري: حجم العبوة (pack_size). هذا هو ملف
 * "الجرد المركزي" — أول ملف يُنشئ منتجات جديدة كلياً بالنظام (كل الملفات
 * الأخرى تكتفي بالمطابقة فقط) — لذلك اخترناه عمداً كمصدر وحيد لحجم
 * العبوة، بدل خلط قسم المشتريات بقسم العمليات (المناقلات).
 *
 * 🆕 (2026-09) عمود سادس اختياري: السعر — بنفس المنطق بالضبط ولنفس
 * السبب: بقرار صريح من صاحب المشروع، تقارير المناقلات (قيمة المخزون/
 * المناقلات/النواقص) يجب ألا تعتمد على أي ملف من قسم المشتريات، والجرد
 * المركزي هو الملف الوحيد المرفوع بانتظام كافٍ ليكون مصدراً موثوقاً
 * لسجل سعر تاريخي. راجع CentralInventoryPriceRecorderService للخطوة
 * التالية (المقارنة بآخر سعر مسجَّل + الإدراج فقط عند الاختلاف).
 *
 * الترتيب: رقم المادة[0] - شركة[1] - مادة[2] - كمية[3] - حجم العبوة[4] (اختياري) - السعر[5] (اختياري).
 */
class CentralInventoryImport implements ToCollection, WithStartRow, WithChunkReading
{
    public array $matched   = []; // [product_id => quantity]
    public array $unmatched = []; // مواد رمزها غير موجود
    public array $prices    = []; // 🆕 [product_id => آخر سعر ذُكر لها بهذا الملف]
    // 🩹 [إصلاح جوهري] صفوف فشل تحديثها فعلياً (راجع تعليق الحلقة أدناه) — قبل هذا
    // الإصلاح، صف واحد به خانة معطوبة (مثلاً صيغة Excel خارجية غير محسوبة، راجع
    // looksLikeFormula()) كان يُسقِط استثناء قاعدة بيانات يُنهي معالجة **الملف
    // كله فوراً** — حتى لو كان الصف رقم 451 من أصل 20,000، تُفقَد كل النتائج
    // الجيدة الـ19,999 الأخرى معه. الآن: صف معطوب يُسجَّل هنا ويُتخطى، والبقية
    // تكتمل بنجاح.
    public array $failedUpdates = []; // [barcode => رسالة الخطأ]
    private ?Collection $productsCache = null;

    private function products(): Collection
    {
        return $this->productsCache ??= Product::select('id', 'barcode', 'name', 'company', 'pack_size')
            ->get()->keyBy('barcode');
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function startRow(): int
    {
        return 2;
    }

    public function collection(Collection $rows)
    {
        $allProducts = $this->products(); // نفس الكاش لكل الـ chunks

        $toUpdate = []; // [barcode => ['name'=>, 'company'=>, 'pack_size'=>?]] لتحديث الأسماء/العبوة المتغيرة

        foreach ($rows as $row) {
            // ✅ رقم المادة[0] - شركة[1] - مادة[2] - كمية[3] - حجم العبوة[4] (اختياري) - السعر[5] (اختياري)
            $barcode      = trim($row[0] ?? '');
            $company      = trim($row[1] ?? '') ?: '-';
            $materialName = trim($row[2] ?? '');
            $quantity     = (int) ($row[3] ?? 0);
            $packSize     = $this->parseNullablePackSize($row[4] ?? null);
            $price        = $this->parseNullablePrice($row[5] ?? null); // 🆕

            if (!$barcode || !$materialName) continue;

            $product = $allProducts->get($barcode);

            if ($product) {
                // 🩹 اسم/شركة معطوبان (نص صيغة خام مثلاً بخانة الاسم نفسها) لا يُقارَنان
                // بصمت — لو بدا أيٌّ منهما كصيغة Excel غير محسوبة، نتجاهل تحديث
                // الاسم/الشركة لهذا السطر تحديداً (نُبقي القيمة القديمة الصحيحة)
                // بدل كتابة نص الصيغة الحرفي كاسم مادة حقيقي بقاعدة البيانات.
                if ($this->looksLikeFormula($materialName) || $this->looksLikeFormula($company)) {
                    $this->failedUpdates[$barcode] = 'اسم المادة أو الشركة يحوي نص صيغة Excel غير محسوبة (خانة تبدأ بـ =) — تُجوهِل تحديث الاسم/الشركة لهذا السطر.';
                    $this->matched[$product->id] = ($this->matched[$product->id] ?? 0) + $quantity;
                    continue;
                }

                // ✅ الرمز موجود — هل تغيّر الاسم أو الشركة أو حجم العبوة؟
                $updateData = [];
                if ($product->name !== $materialName || $product->company !== $company) {
                    $updateData['name']    = $materialName;
                    $updateData['company'] = $company;
                }
                // 🆕 لا نُصفّر حجم العبوة إن لم يُذكر العمود بهذا السطر —
                // نُحدّثه فقط إذا وردت قيمة رقمية صريحة تختلف عن المحفوظة.
                if ($packSize !== null && (int) $product->pack_size !== $packSize) {
                    $updateData['pack_size'] = $packSize;
                }
                if (!empty($updateData)) {
                    $toUpdate[$barcode] = $updateData;
                }
                $this->matched[$product->id] = ($this->matched[$product->id] ?? 0) + $quantity;

                // 🆕 آخر سعر مذكور لنفس المنتج بنفس الملف يفوز — لا جمع، لا
                // متوسط (نفس منطق $this->matched للكمية لكن "آخر قيمة" لا "مجموع")
                if ($price !== null) {
                    $this->prices[$product->id] = $price;
                }
            } else {
                // ❌ رمز جديد غير موجود — يُرصَد الآن، ويُنشأ لاحقاً بعد
                // تأكيد المستخدم (راجع PendingProductService)
                $this->unmatched[] = [
                    'barcode'       => $barcode,
                    'material_name' => $materialName,
                    'company'       => $company,
                    'quantity'      => $quantity,
                    'pack_size'     => $packSize, // 🆕 يُرحَّل حتى إنشاء المنتج الفعلي لاحقاً
                    'price'         => $price,     // 🆕 يُرحَّل حتى إنشاء المنتج الفعلي لاحقاً
                ];
            }
        }

        // 🩹 [إصلاح جوهري] كان هذا استعلاماً بلا حماية — أي استثناء (قاعدة بيانات
        // أو غيرها) بأي صف واحد كان يُنهي معالجة الدفعة (Chunk) كلها فوراً، فيفقد
        // المستخدم كل الصفوف السليمة الأخرى بلا أي تفسير واضح (رسالة SQL خام لا
        // تُظهر أي رمز مادة تحديداً سبَّب المشكلة). الآن: صف معطوب يُسجَّل ويُتخطى،
        // والبقية تُطبَّق بنجاح — يرى المستخدم لاحقاً بالضبط أي الرموز فشلت ولماذا.
        foreach ($toUpdate as $barcode => $data) {
            try {
                Product::where('barcode', $barcode)->update($data);
            } catch (\Throwable $e) {
                $this->failedUpdates[$barcode] = $e->getMessage();
            }
        }
    }

    /** خانة تبدأ بـ "=" بعد إزالة الفراغات — صيغة Excel خام لم تُحسَب (غالباً مرجع لملف خارجي مفقود). */
    private function looksLikeFormula($value): bool
    {
        return str_starts_with(trim((string) ($value ?? '')), '=');
    }

    /**
     * 🆕 خانة فارغة أو "-" → null (لا نلمس القيمة المحفوظة أصلاً). أي رقم
     * صالح آخر (بما فيه 0 الصريح = "ألغِ قيد التعبئة") يُعتمَد كما هو.
     */
    private function parseNullablePackSize($value): ?int
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || $value === '-') {
            return null;
        }

        // 🩹 فحص صريح إضافي لصيغة Excel خام (احترازي: is_numeric() أدناه يرفضها فعلاً،
        // لكن التسجيل الصريح هنا يجعل السبب ظاهراً بـ failedUpdates بدل صمت كامل).
        if ($this->looksLikeFormula($value)) {
            return null;
        }

        return is_numeric($value) ? max(0, (int) round((float) $value)) : null;
    }

    /**
     * 🆕 نفس منطق parseNullablePackSize تماماً، لكن بلا فرض قيمة دنيا 0 —
     * سعر صفر ليس "قيداً مُلغى" كما هو الحال بحجم العبوة، بل قد يكون
     * (نادراً) قيمة حقيقية (مادة مجانية بعرض ترويجي مثلاً) — لا نمنعها.
     */
    private function parseNullablePrice($value): ?float
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || $value === '-') {
            return null;
        }

        if ($this->looksLikeFormula($value)) {
            return null;
        }

        return is_numeric($value) ? round((float) $value, 2) : null;
    }
}
