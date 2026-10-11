<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يخزّن قرار المشرف بشأن "أي الشركات تُستثنى من ترميم النواقص" لكل
 * جلسة رفع جرد على حدة — طلب صريح لحل مشكلة ترميم أصناف الإكسسوار
 * تلقائياً رغم عدم رغبة الصيدلية بذلك.
 *
 * 🎓 لماذا نخزّنه على import_sessions تحديداً (وليس Session PHP عادية
 * أو تمريره مباشرة كمعامل بالـ Job)؟ لأن دورة حياة هذا القرار قد تمتد
 * عبر خطوات متعددة منفصلة زمنياً: رفع الملف → (احتمال) شاشة "مواد جديدة
 * بالجرد" التي يُؤكِّدها المستخدم لاحقاً يدوياً → عندها فقط يُستدعى
 * الترميم الفعلي. Session PHP قد تنتهي أو تُفرَّغ بين هذه الخطوات، بينما
 * سجل import_sessions موجود أصلاً ومصمَّم بالضبط لتتبّع حالة هذه العملية
 * متعددة الخطوات — امتداد طبيعي له بدل آلية تخزين مؤقتة موازية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_sessions', function (Blueprint $table) {
            $table->json('excluded_restocking_companies')->nullable()->after('has_sales');
        });
    }

    public function down(): void
    {
        Schema::table('import_sessions', function (Blueprint $table) {
            $table->dropColumn('excluded_restocking_companies');
        });
    }
};
