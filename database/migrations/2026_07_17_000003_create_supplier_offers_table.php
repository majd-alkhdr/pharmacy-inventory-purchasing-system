<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 لماذا جدول منفصل تماماً عن supplier_products بدل إضافة عمودَي
 * bonus_qty و discount_percent مباشرة عليه؟
 * ------------------------------------------------------------------
 * لأن المستند الذي أرسلته يذكر صراحة "نظام البونص والعروض المركّبة"
 * (compound offers). عرض مركّب يعني عادة **شرائح متعددة**:
 *   - اشترِ 50 علبة → احصل على 5 مجاناً
 *   - اشترِ 100 علبة → احصل على 12 مجاناً (نسبة أفضل)
 * لو خزّنّا bonus_qty كعمود واحد بجدول supplier_products، لا يمكننا
 * تمثيل أكثر من شريحة واحدة لكل مورد+مادة. بجدول منفصل، نضيف صفاً واحداً
 * لكل شريحة، وكلها تشير لنفس رابطة supplier_product_id.
 *
 * bonus_type: نوعان فقط بهذه المرحلة (كمية مجانية / نسبة خصم) — هذا Enum
 * حقيقي (App\Enums\BonusType) وليس نص حر، لتفادي بالضبط نفس مشكلة
 * "Magic Strings" التي وثّقتها بتقرير [012] بالمرحلة الأولى.
 *
 * starts_at / ends_at: العروض غالباً محدودة زمنياً — محرك "مراقب تذبذب
 * التكلفة" (Cost Variance Monitor) سيحتاج هذا التاريخ ليعرف "هل هذا العرض
 * ما زال سارياً أم انتهى وارتفع السعر فعلياً؟".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_product_id')->constrained()->cascadeOnDelete();

            // الحد الأدنى من الكمية المطلوب شراؤها لتفعيل هذه الشريحة من العرض
            $table->unsignedInteger('min_quantity');

            // 'free_quantity' أو 'percent_discount' — راجع App\Enums\BonusType
            $table->string('bonus_type');
            $table->decimal('bonus_value', 10, 2); // كمية مجانية أو نسبة مئوية حسب bonus_type

            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['supplier_product_id', 'min_quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_offers');
    }
};
