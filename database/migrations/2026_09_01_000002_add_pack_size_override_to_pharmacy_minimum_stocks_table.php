<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 "تجاوز حجم العبوة لهذه الصيدلية تحديداً" — بقرار صريح: حجم العبوة
 * (pack_size) يبقى بجوهره خاصية **مادة**، لا خاصية "صيدلية + مادة" مثل
 * min_reorder_gap/minimum_quantity (عبوة الأموكسيسيلين نفسها بنفس عدد
 * الوحدات أينما ذهبت). لذلك products.pack_size يبقى تماماً كما هو
 * كمصدر افتراضي — لا يُحذف ولا يُنقَل بالكامل بهذه المايجريشن.
 *
 * هذا العمود هنا هو **تجاوز اختياري لكل زوج (صيدلية + مادة)** فقط —
 * يُملأ حصراً إن تضمَّن ملف جرد صيدلية معيّنة عمود batch_size اختيارياً
 * لصنف ما (راجع طلب المشروع الأصلي: "تجاهل العمود إن كان غائباً").
 * هذا يحل تحديداً مشكلة "تسرّب" القيمة بين الصيدليات، التي كانت ستحصل
 * لو كتبنا مباشرة على products.pack_size من ملف صيدلية واحدة، فيتأثر
 * بها ترميم كل الصيدليات الأخرى لنفس الصنف بالخطأ.
 *
 * ⚠️ nullable() عمداً بدل default(0) — لأن للقيمتين معنى مختلف هنا:
 * - NULL = "لا يوجد تجاوز إطلاقاً لهذه الصيدلية، استخدم products.pack_size كما هو"
 * - 0    = "هذه الصيدلية صراحة بلا أي قيد تعبئة لهذا الصنف" (تعطيل فعلي
 *          للتقريب حتى لو كان للمنتج نفسه pack_size > 0 بمكان آخر)
 * لو استخدمنا default(0) هنا كنا سنخلط الحالتين، ونفقد القدرة على
 * التعبير عن "صيدلية تريد صراحة تعطيل قيد التعبئة الافتراضي للمنتج".
 *
 * الأولوية عند الحساب (راجع StandardInventoryRestockingStrategy لاحقاً):
 *   pharmacy_minimum_stocks.pack_size_override ?? products.pack_size
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_minimum_stocks', function (Blueprint $table) {
            $table->unsignedInteger('pack_size_override')->nullable()->after('min_reorder_gap');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_minimum_stocks', function (Blueprint $table) {
            $table->dropColumn('pack_size_override');
        });
    }
};
