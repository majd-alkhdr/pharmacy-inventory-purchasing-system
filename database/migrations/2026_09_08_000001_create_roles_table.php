<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 نظام الأدوار هنا مصفوفة من 4 صلاحيات مستقلة (Boolean)، لا عمود دور
 * واحد نصّي يُقارَن بقائمة "إن كان كذا فافعل كذا" بكل مكان بالكود.
 *
 * 🎓 لماذا مصفوفة، لا عمود واحد؟ لأن دور "مستخدم التقارير" تحديداً يحتاج
 * الجمع بين تقارير **قسمين مختلفين** (مناقلات + مشتريات) بلا الوصول لأي
 * قسم عمليات منهما — علاقة لا يمكن التعبير عنها بمقارنة "الدور = X"
 * بسيطة؛ تحتاج فحصاً مستقلاً لكل قسم على حدة. الأدوار الأربعة المطلوبة
 * فعلياً (مدير، مناقلات، مشتريات، تقارير) هي فقط تركيبات مختلفة مسبقة
 * التحديد من هذه الأعمدة الأربعة — لا حاجة لمنطق أكثر تعقيداً من ذلك.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // admin / transfers / purchasing / reports — للبحث البرمجي، لا للعرض
            $table->string('name');           // الاسم المعروض بالعربية بشاشات إدارة المستخدمين
            $table->boolean('can_view_transfers_operations')->default(false);
            $table->boolean('can_view_transfers_reports')->default(false);
            $table->boolean('can_view_purchasing_operations')->default(false);
            $table->boolean('can_view_purchasing_reports')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
