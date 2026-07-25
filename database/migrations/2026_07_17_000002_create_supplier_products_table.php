<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 هذا الجدول يطبّق قرارك مباشرة: "مادة واحدة قد ترتبط بعدة موردين
 * بعروض مختلفة". لاحظ أنه ليس جدول Pivot بسيط (لا نستخدم belongsToMany
 * المباشر)، بل موديل كامل له بيانات خاصة به (unit_cost, is_preferred).
 *
 * لماذا موديل كامل وليس Pivot بسيط؟
 * لأن "مورد + مادة" له بيانات تخصه هو (السعر، هل هو المفضّل، متى آخر
 * تحديث سعر) — وهذه بيانات نحتاج الاستعلام عنها مباشرة (WHERE is_preferred)
 * وليس فقط المرور عبرها كجسر بين جدولين. متى ما احتجت هذا، الحل الصحيح
 * بـ Laravel هو "Full Model" وليس Pivot عادي.
 *
 * is_preferred: يسمح للمشرف بتحديد "المورد الافتراضي" لهذه المادة عندما
 * لا يوجد سبب لاختيار غيره (توجيه الربحية لاحقاً قد يتجاوز هذا الاختيار
 * إن وجد عرضاً أفضل من مورد آخر — هذا بالضبط سيناريو "الربحية الأعلى"
 * بالمستند الذي أرسلته).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // آخر سعر معروف لهذه المادة من هذا المورد (بدون أي عرض/بونص —
            // العروض المركّبة لها جدول منفصل supplier_offers للتوسّع بحرية).
            $table->decimal('unit_cost', 10, 2);

            $table->boolean('is_preferred')->default(false);
            $table->timestamp('last_price_updated_at')->nullable();
            $table->timestamps();

            // مادة واحدة لا يمكن أن ترتبط بنفس المورد مرتين بصفين منفصلين
            $table->unique(['supplier_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_products');
    }
};
