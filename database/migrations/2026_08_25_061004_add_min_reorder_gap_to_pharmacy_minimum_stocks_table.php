<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يضيف "الحد الأدنى للفرق قبل الترميم" (min_reorder_gap) لكل زوج
 * (صيدلية + مادة) — نفس مستوى تفصيل minimum_quantity بالضبط، لأن الحكم
 * "هل هذه الفجوة تستحق تحريك شحنة أصلاً؟" منطقي أن يبقى بنفس مستوى
 * تفصيل "ما هو المستوى القياسي؟" لهذا الزوج تحديداً.
 *
 * المعادلة (بقرار صاحب المشروع):
 *   الفرق الحالي = المخزون القياسي − المخزون في الصيدلية
 *   الفرق الحالي > الحد الأدنى للفرق  → مطلوب ترميم بقيمة الفرق
 *   الفرق الحالي <= الحد الأدنى للفرق → غير مطلوب، يُتجاهَل هذه الدفعة
 *
 * القيمة الافتراضية 0 = بلا حد أدنى إطلاقاً (أي فجوة موجبة مؤهَّلة
 * للترميم) — وهذا هو السلوك القديم تماماً قبل هذه الإضافة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_minimum_stocks', function (Blueprint $table) {
            $table->unsignedInteger('min_reorder_gap')->default(0)->after('minimum_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_minimum_stocks', function (Blueprint $table) {
            $table->dropColumn('min_reorder_gap');
        });
    }
};
