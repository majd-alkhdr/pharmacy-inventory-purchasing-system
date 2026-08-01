<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يطبّق قرارك: تصنيف/استطباب كـ"قائمة ثابتة مُدارة" (وليس نصاً حراً
 * على عمود products.category مباشرة) — حتى لا تتكرر نفس الفئة بصيغ
 * مختلفة (مثلاً "مسكنات" مرة و"مُسكنات" مرة أخرى)، فيبقى أي تقرير
 * لاحق ("كل مبيعات المسكنات") موثوقاً 100%.
 *
 * 🎓 لماذا Pivot (متعدد لمتعدد) وليس عمود category_id واحد على
 * products؟ لأنك أكّدت صراحة أن المادة الواحدة قد تنتمي لأكثر من
 * تصنيف (مثلاً: "مسكنات" و"مضادات التهاب" معاً).
 *
 * ⚠️ لا يوجد هنا أي مصدر لإنشاء تصنيف جديد تلقائياً من ملف الربط —
 * فقط "ملف قائمة التصنيفات الرئيسية" (الذي سيُستورَد أولاً ومنفصلاً)
 * يُنشئ تصنيفات جديدة. ملف "ربط المنتجات بالتصنيفات" لاحقاً لا يفعل
 * سوى الربط بتصنيفات موجودة أصلاً — أي تصنيف غير معروف بالملف الثاني
 * يُتجاهَل الربط له (راجع CategoryImportService لاحقاً).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('category_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['category_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('categories');
    }
};
